<?php
// admin/schemes.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../calculate_commissions.php';
require_once __DIR__ . '/../wallet_to_epin.php';
require_once __DIR__ . '/../includes/sms_helper.php';

Auth::requireLogin();

$user = Auth::user();

// Strict Admin Privileges Check
if (!$user || (empty($user['is_admin']) && (int)$user['id'] !== 1)) {
    header('Location: ../dashboard.php');
    exit('Access Denied: Admin Privileges Required.');
}

$db = Database::getConnection();

$successMsg = '';
$errorMsg = '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 0. Admin Manual User Package Activation (Bank/Account Payment Received)
    if ($action === 'admin_activate_user') {
        $targetUserPhone = trim($_POST['target_phone'] ?? '');
        $packageType = $_POST['package_type'] ?? '';

        $stmtTarget = $db->prepare("SELECT id, full_name, phone FROM users WHERE phone = ?");
        $stmtTarget->execute([$targetUserPhone]);
        $targetUser = $stmtTarget->fetch();

        if (!$targetUser) {
            $errorMsg = "User with phone number '{$targetUserPhone}' was not found.";
        } else {
            $valueAmount = WalletToEpinEngine::getPackageValue($packageType);
            if ($valueAmount <= 0) {
                $errorMsg = "Invalid package type selected.";
            } else {
                $monthlyReturn = 0.00;
                $totalMonths = 1;

                if ($packageType === 'sip_300') {
                    $monthlyReturn = 50.00;
                    $totalMonths = 10;
                } elseif ($packageType === 'sip_600') {
                    $monthlyReturn = 100.00;
                    $totalMonths = 10;
                }

                $db->beginTransaction();

                try {
                    $today = date('Y-m-d');
                    $stmtSub = $db->prepare("
                        INSERT INTO subscriptions (user_id, package_type, payment_method, amount_paid, monthly_return_amount, total_months, months_paid, status, start_date)
                        VALUES (?, ?, 'admin', ?, ?, ?, 0, 'active', ?)
                    ");
                    $stmtSub->execute([$targetUser['id'], $packageType, $valueAmount, $monthlyReturn, $totalMonths, $today]);
                    $subId = (int)$db->lastInsertId();

                    CommissionCalculator::processSubscriptionCommissions($targetUser['id'], $packageType, $db);

                    $db->commit();

                    SMSHelper::sendSMS(
                        $targetUser['phone'],
                        "Dear {$targetUser['full_name']}, your subscription package '{$packageType}' (Rs.{$valueAmount}) has been ACTIVATED by Admin!"
                    );

                    $successMsg = "Successfully activated package '{$packageType}' (₹{$valueAmount}) for {$targetUser['full_name']} (#{$targetUser['id']}). 12-Level Commissions distributed!";

                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $errorMsg = "Activation failed: " . $e->getMessage();
                }
            }
        }
    }

    // 0B. Admin Free Batch ePIN Generation
    elseif ($action === 'admin_generate_epins') {
        $packageType = $_POST['package_type'] ?? '';
        $quantity = max(1, min(100, (int)($_POST['quantity'] ?? 1)));
        $valueAmount = WalletToEpinEngine::getPackageValue($packageType);

        if ($valueAmount <= 0) {
            $errorMsg = "Invalid package type selected.";
        } else {
            $generatedPins = [];
            $db->beginTransaction();

            try {
                for ($k = 0; $k < $quantity; $k++) {
                    $pinCode = '';
                    do {
                        $candidate = WalletToEpinEngine::generatePinCode(16);
                        $stmtCheck = $db->prepare("SELECT id FROM epins WHERE pin_code = ?");
                        $stmtCheck->execute([$candidate]);
                        if (!$stmtCheck->fetch()) {
                            $pinCode = $candidate;
                        }
                    } while (empty($pinCode));

                    $stmtInsert = $db->prepare("
                        INSERT INTO epins (pin_code, package_type, value_amount, created_by, creator_user_id, status)
                        VALUES (?, ?, ?, 'admin', ?, 'unused')
                    ");
                    $stmtInsert->execute([$pinCode, $packageType, $valueAmount, $user['id']]);
                    $generatedPins[] = $pinCode;
                }

                $db->commit();
                $successMsg = "Admin generated {$quantity} ePINs for package '{$packageType}' (₹{$valueAmount}) successfully!";

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errorMsg = "ePIN generation failed: " . $e->getMessage();
            }
        }
    }

    // 1. Update Affiliate Payout Rule (Level Matrix)
    elseif ($action === 'update_payout_rule') {
        $level = (int)($_POST['level'] ?? 0);
        $classicPts = (int)($_POST['classic_points'] ?? 0);
        $basicPts = (int)($_POST['basic_points'] ?? 0);
        $teamPts = (int)($_POST['team_points'] ?? 0);

        if ($level >= 1 && $level <= 12) {
            $stmt = $db->prepare("
                INSERT INTO affiliate_payout_rules (level, classic_points, basic_points, team_points)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE classic_points = VALUES(classic_points), basic_points = VALUES(basic_points), team_points = VALUES(team_points)
            ");

            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $db->prepare("
                    INSERT INTO affiliate_payout_rules (level, classic_points, basic_points, team_points)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT(level) DO UPDATE SET classic_points = excluded.classic_points, basic_points = excluded.basic_points, team_points = excluded.team_points
                ");
            }

            $stmt->execute([$level, $classicPts, $basicPts, $teamPts]);
            $successMsg = "Level {$level} affiliate payout rules updated successfully.";
        } else {
            $errorMsg = "Invalid level specified.";
        }
    }

    // 2. Update Existing Rank Definition
    elseif ($action === 'update_rank') {
        $rankId = (int)($_POST['rank_id'] ?? 0);
        $rankName = trim($_POST['rank_name'] ?? '');
        $reqCount = (int)($_POST['requirement_team_count'] ?? 5);
        $rankIncentive = (float)($_POST['rank_incentive'] ?? 0);
        $monthlyIncentive = (float)($_POST['monthly_incentive'] ?? 0);
        $durationMonths = (int)($_POST['monthly_duration_months'] ?? 10);

        if ($rankId >= 1 && !empty($rankName)) {
            $stmt = $db->prepare("
                UPDATE rank_definitions
                SET rank_name = ?, requirement_team_count = ?, rank_incentive = ?, monthly_incentive = ?, monthly_duration_months = ?
                WHERE rank_id = ?
            ");
            $stmt->execute([$rankName, $reqCount, $rankIncentive, $monthlyIncentive, $durationMonths, $rankId]);
            $successMsg = "Rank '{$rankName}' (ID: {$rankId}) updated successfully.";
        } else {
            $errorMsg = "Invalid rank ID or missing rank name.";
        }
    }

    // 3. Create New Rank Definition Scheme
    elseif ($action === 'create_rank') {
        $stmtMax = $db->query("SELECT MAX(rank_id) as max_id FROM rank_definitions");
        $maxRank = (int)($stmtMax->fetch()['max_id'] ?? 0);
        $newRankId = $maxRank + 1;

        $rankName = trim($_POST['rank_name'] ?? '');
        $reqCount = (int)($_POST['requirement_team_count'] ?? 5);
        $rankIncentive = (float)($_POST['rank_incentive'] ?? 0);
        $monthlyIncentive = (float)($_POST['monthly_incentive'] ?? 0);
        $durationMonths = (int)($_POST['monthly_duration_months'] ?? 10);

        if (!empty($rankName)) {
            $stmt = $db->prepare("
                INSERT INTO rank_definitions (rank_id, rank_name, requirement_team_count, rank_incentive, monthly_incentive, monthly_duration_months)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$newRankId, $rankName, $reqCount, $rankIncentive, $monthlyIncentive, $durationMonths]);
            $successMsg = "New Rank Scheme '{$rankName}' (Rank Tier {$newRankId}) created successfully!";
        } else {
            $errorMsg = "Rank name is required.";
        }
    }
}

// Fetch Stats for Cards
$totalUsers = (int)($db->query("SELECT COUNT(*) as cnt FROM users")->fetch()['cnt'] ?? 0);
$activeSubs = (int)($db->query("SELECT COUNT(*) as cnt FROM subscriptions WHERE status = 'active'")->fetch()['cnt'] ?? 0);
$totalRevenue = (float)($db->query("SELECT SUM(amount_paid) as total FROM subscriptions")->fetch()['total'] ?? 0.00);
$unusedEpins = (int)($db->query("SELECT COUNT(*) as cnt FROM epins WHERE status = 'unused'")->fetch()['cnt'] ?? 0);

// Fetch Master Payout Rules
$stmtRules = $db->query("SELECT * FROM affiliate_payout_rules ORDER BY level ASC");
$payoutRules = $stmtRules->fetchAll();

// Fetch Master Rank Definitions
$stmtRanks = $db->query("SELECT * FROM rank_definitions ORDER BY rank_id ASC");
$rankDefs = $stmtRanks->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POWERNET ASSOCIATE - Schemes Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="theme-light">

    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="fas fa-sitemap" style="color: var(--brand-magenta); font-size: 24px;"></i>
            <span style="font-weight: 700; font-size: 18px; color: var(--brand-magenta);">POWERNET</span>
            <button id="sidebar-close" class="mobile-sidebar-close" aria-label="Close sidebar">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="sidebar-user">
            <div style="position: relative;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background-color: var(--brand-magenta); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <span style="position: absolute; bottom: -2px; right: -2px; width: 12px; height: 12px; background: var(--success-color); border: 2px solid var(--bg-sidebar); border-radius: 50%;"></span>
            </div>
            <div style="overflow: hidden;">
                <div style="font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($user['full_name']) ?></div>
                <div style="font-size: 11px; color: var(--brand-gold-pure); text-transform: uppercase; letter-spacing: 0.5px;">Super Admin</div>
            </div>
        </div>

        <ul class="sidebar-menu">
            <li class="sidebar-menu-item">
                <a href="../dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a>
            </li>
            <li class="sidebar-menu-item active">
                <a href="schemes.php"><i class="fas fa-sliders"></i> Schemes Manager</a>
            </li>
            <li class="sidebar-menu-item" style="margin-top: auto;">
                <a href="../logout.php" style="color: var(--danger-color);"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
    </div>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Top Navbar -->
        <nav class="top-navbar">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="sidebar-toggle" class="hamburger-btn" aria-label="Toggle navigation menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="navbar-brand-mobile">
                    <i class="fas fa-sitemap" style="color: var(--brand-magenta); font-size: 20px;"></i>
                    <span style="font-weight: 700; font-size: 16px; color: var(--brand-magenta);">POWERNET</span>
                </div>
                <div class="search-form">
                    <i class="fas fa-search" style="opacity: 0.5;"></i>
                    <input type="text" placeholder="Search system...">
                </div>
            </div>

            <div class="top-navbar-right">
                <div style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-muted);">
                    <i class="far fa-clock"></i>
                    <span id="live-time"><?= date('H:i:s') ?></span>
                </div>
                <div style="position: relative; cursor: pointer;">
                    <i class="far fa-bell" style="font-size: 20px;"></i>
                    <span style="position: absolute; top: -5px; right: -5px; background: var(--brand-gold-pure); width: 8px; height: 8px; border-radius: 50%;"></span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text-primary);">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--brand-magenta); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px;">
                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                    </div>
                    <span class="fw-semibold small">Super Admin</span>
                </div>
            </div>
        </nav>

        <!-- Content Page -->
        <div class="content-page">
            <div class="page-heading">
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php" style="text-decoration: none; color: inherit;">Home</a></li>
                    <li><i class="fas fa-chevron-right" style="font-size: 10px; margin: 0 5px; opacity: 0.5;"></i></li>
                    <li style="color: var(--brand-gold-pure); font-weight: 500;">Admin Schemes</li>
                </ul>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                    <h1 class="gold-gradient-text" style="font-size: 32px; margin: 0;">Business Schemes Manager</h1>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#adminActivateModal">
                            <i class="fas fa-user-check me-1"></i> Activate User
                        </button>
                        <button class="btn btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#adminGenerateEpinModal">
                            <i class="fas fa-key me-1"></i> Batch ePINs
                        </button>
                        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#createRankModal">
                            <i class="fas fa-plus-circle me-1"></i> New Rank Scheme
                        </button>
                    </div>
                </div>
            </div>

            <!-- Stats Row -->
            <div class="card-group-row">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <h3>Total Members</h3>
                        <p><?= $totalUsers ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-cart-shopping"></i></div>
                    <div class="stat-content">
                        <h3>Active Subscriptions</h3>
                        <p><?= $activeSubs ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div>
                    <div class="stat-content">
                        <h3>Total Revenue</h3>
                        <p>₹<?= number_format($totalRevenue, 2) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-ticket"></i></div>
                    <div class="stat-content">
                        <h3>Unused ePINs</h3>
                        <p style="color: var(--warning-color);"><?= $unusedEpins ?></p>
                    </div>
                </div>
            </div>

            <?php if ($successMsg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($successMsg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-triangle-exclamation me-2"></i><?= htmlspecialchars($errorMsg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <ul class="nav nav-pills mb-4" id="schemeTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold px-4" id="ranks-tab" data-bs-toggle="tab" data-bs-target="#ranks-pane" type="button" role="tab">
                        <i class="fas fa-trophy me-2"></i>12-Tier Rank Schemes & Incentives
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold px-4" id="affiliate-tab" data-bs-toggle="tab" data-bs-target="#affiliate-pane" type="button" role="tab">
                        <i class="fas fa-sitemap me-2"></i>12-Level Affiliate Commission Matrix
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="schemeTabsContent">
                <!-- 1. Rank Definitions Manager Pane -->
                <div class="tab-pane fade show active" id="ranks-pane" role="tabpanel">
                    <div class="card p-4 border-0 shadow-sm" style="border-radius: 12px;">
                        <h5 class="fw-bold text-success mb-3">Rank Definitions & Reward Rules</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Rank ID</th>
                                        <th>Rank Name</th>
                                        <th>Required Downlines</th>
                                        <th>One-Time Incentive (₹)</th>
                                        <th>Monthly Incentive (₹)</th>
                                        <th>Duration (Months)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rankDefs as $rank): ?>
                                        <tr>
                                            <form method="POST" action="schemes.php">
                                                <input type="hidden" name="action" value="update_rank">
                                                <input type="hidden" name="rank_id" value="<?= $rank['rank_id'] ?>">
                                                <td><span class="badge bg-secondary fs-6">Tier <?= $rank['rank_id'] ?></span></td>
                                                <td>
                                                    <input type="text" name="rank_name" class="form-control form-control-sm fw-bold" value="<?= htmlspecialchars($rank['rank_name']) ?>" required>
                                                </td>
                                                <td>
                                                    <input type="number" name="requirement_team_count" class="form-control form-control-sm" value="<?= $rank['requirement_team_count'] ?>" required>
                                                </td>
                                                <td>
                                                    <input type="number" step="0.01" name="rank_incentive" class="form-control form-control-sm" value="<?= $rank['rank_incentive'] ?>" required>
                                                </td>
                                                <td>
                                                    <input type="number" step="0.01" name="monthly_incentive" class="form-control form-control-sm" value="<?= $rank['monthly_incentive'] ?>" required>
                                                </td>
                                                <td>
                                                    <input type="number" name="monthly_duration_months" class="form-control form-control-sm" value="<?= $rank['monthly_duration_months'] ?>" required>
                                                </td>
                                                <td>
                                                    <button type="submit" class="btn btn-primary btn-sm fw-semibold">
                                                        <i class="fas fa-floppy-disk me-1"></i> Save
                                                    </button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 2. Affiliate Commission Matrix Manager Pane -->
                <div class="tab-pane fade" id="affiliate-pane" role="tabpanel">
                    <div class="card p-4 border-0 shadow-sm" style="border-radius: 12px;">
                        <h5 class="fw-bold text-success mb-3">12-Level Affiliate Commission Matrix Rules</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Sponsor Level</th>
                                        <th>Classic Points (Voucher 600 / SIP 600)</th>
                                        <th>Basic Points (Voucher 300 / SIP 300)</th>
                                        <th>Team Points (Smart Recharge)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payoutRules as $rule): ?>
                                        <tr>
                                            <form method="POST" action="schemes.php">
                                                <input type="hidden" name="action" value="update_payout_rule">
                                                <input type="hidden" name="level" value="<?= $rule['level'] ?>">
                                                <td><span class="badge bg-success fs-6">Level <?= $rule['level'] ?></span></td>
                                                <td>
                                                    <input type="number" name="classic_points" class="form-control form-control-sm" value="<?= $rule['classic_points'] ?>" required>
                                                </td>
                                                <td>
                                                    <input type="number" name="basic_points" class="form-control form-control-sm" value="<?= $rule['basic_points'] ?>" required>
                                                </td>
                                                <td>
                                                    <input type="number" name="team_points" class="form-control form-control-sm" value="<?= $rule['team_points'] ?>" required>
                                                </td>
                                                <td>
                                                    <button type="submit" class="btn btn-success btn-sm fw-semibold">
                                                        <i class="fas fa-floppy-disk me-1"></i> Save Level
                                                    </button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal 1: Admin Direct User Package Activation -->
    <div class="modal fade" id="adminActivateModal" tabindex="-1" aria-labelledby="adminActivateModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" action="schemes.php">
            <input type="hidden" name="action" value="admin_activate_user">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title fw-bold" id="adminActivateModalLabel"><i class="fas fa-user-check me-2"></i>Manual User Package Activation</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <i class="fas fa-info-circle me-1"></i> Use this when money has been received offline / directly into company bank account to activate user packages and distribute affiliate commissions.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Target User Phone Number</label>
                    <input type="text" name="target_phone" class="form-control" placeholder="10-digit registered mobile number" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Subscription Package to Activate</label>
                    <select name="package_type" class="form-select" required>
                        <option value="voucher_600">Voucher 600 (Classic Tier - ₹600)</option>
                        <option value="voucher_300">Voucher 300 (Basic Tier - ₹300)</option>
                        <option value="smart_recharge">Smart Recharge (Team Tier - ₹100)</option>
                        <option value="sip_600">SIP 600 (₹600 | ₹100/mo x 10 Mos)</option>
                        <option value="sip_300">SIP 300 (₹300 | ₹50/mo x 10 Mos)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary fw-bold">Activate Subscription Now</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal 2: Admin Free Batch ePIN Generator -->
    <div class="modal fade" id="adminGenerateEpinModal" tabindex="-1" aria-labelledby="adminGenerateEpinModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" action="schemes.php">
            <input type="hidden" name="action" value="admin_generate_epins">
            <div class="modal-header bg-warning text-dark">
              <h5 class="modal-title fw-bold" id="adminGenerateEpinModalLabel"><i class="fas fa-key me-2"></i>Admin Free Batch ePIN Generator</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Select ePIN Voucher Package</label>
                    <select name="package_type" class="form-select" required>
                        <option value="voucher_600">Voucher 600 (₹600 Value)</option>
                        <option value="voucher_300">Voucher 300 (₹300 Value)</option>
                        <option value="smart_recharge">Smart Recharge (₹100 Value)</option>
                        <option value="sip_600">SIP 600 (₹600 Value)</option>
                        <option value="sip_300">SIP 300 (₹300 Value)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Number of ePINs to Generate</label>
                    <input type="number" name="quantity" class="form-control" value="5" min="1" max="100" required>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-warning fw-bold text-dark">Generate ePIN Batch</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal 3: Create New Business Rank Scheme -->
    <div class="modal fade" id="createRankModal" tabindex="-1" aria-labelledby="createRankModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" action="schemes.php">
            <input type="hidden" name="action" value="create_rank">
            <div class="modal-header bg-success text-white">
              <h5 class="modal-title fw-bold" id="createRankModalLabel"><i class="fas fa-plus-circle me-2"></i>Create New Rank Scheme Tier</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Rank Name</label>
                    <input type="text" name="rank_name" class="form-control" placeholder="e.g. Executive Director" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Required Downlines of Previous Rank</label>
                    <input type="number" name="requirement_team_count" class="form-control" value="5" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">One-Time Rank Incentive Bonus (₹)</label>
                    <input type="number" step="0.01" name="rank_incentive" class="form-control" value="15000000.00" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Monthly Incentive Amount (₹)</label>
                    <input type="number" step="0.01" name="monthly_incentive" class="form-control" value="1500000.00" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Monthly Duration (Months)</label>
                    <input type="number" name="monthly_duration_months" class="form-control" value="10" required>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success fw-bold">Create Scheme Tier</button>
            </div>
          </form>
        </div>
      </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const closeBtn = document.getElementById('sidebar-close');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
