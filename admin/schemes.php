<?php
// admin/schemes.php

require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();

$user = Auth::user();

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

    // 1. Update Affiliate Payout Rule (Level Matrix)
    if ($action === 'update_payout_rule') {
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
    <title>Business Schemes & Compensation Manager - POWERNET ASSOCIATE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #006837;
            --secondary-green: #39B54A;
            --accent-gold: #F7941E;
        }
        body {
            background-color: #f4f7f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .bg-brand-primary {
            background-color: var(--primary-green) !important;
        }
        .text-brand-primary {
            color: var(--primary-green) !important;
        }
        .card-stat {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-brand-primary navbar-dark shadow-sm py-3">
    <div class="container">
        <a class="navbar-brand fw-bold text-white" href="../dashboard.php">
            <i class="bi bi-diagram-3-fill me-2 text-warning"></i>POWERNET ASSOCIATE <span class="badge bg-warning text-dark ms-2">ADMIN PORTAL</span>
        </a>
        <div class="d-flex align-items-center text-white">
            <a href="../dashboard.php" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-speedometer2 me-1"></i> User Dashboard</a>
            <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-brand-primary mb-1"><i class="bi bi-sliders me-2"></i>Business Schemes & Compensation Manager</h3>
            <p class="text-muted mb-0">Create new business rank schemes or edit existing affiliate commission matrices and incentive rules.</p>
        </div>
        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#createRankModal">
            <i class="bi bi-plus-circle-fill me-1"></i> Create New Business Rank Scheme
        </button>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Nav Tabs -->
    <ul class="nav nav-pills mb-4" id="schemeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold px-4" id="ranks-tab" data-bs-toggle="tab" data-bs-target="#ranks-pane" type="button" role="tab">
                <i class="bi bi-trophy-fill me-2"></i>12-Tier Rank Schemes & Incentives
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold px-4" id="affiliate-tab" data-bs-toggle="tab" data-bs-target="#affiliate-pane" type="button" role="tab">
                <i class="bi bi-diagram-3-fill me-2"></i>12-Level Affiliate Commission Matrix
            </button>
        </li>
    </ul>

    <div class="tab-content" id="schemeTabsContent">
        <!-- 1. Rank Definitions Manager Pane -->
        <div class="tab-pane fade show active" id="ranks-pane" role="tabpanel">
            <div class="card card-stat bg-white p-4">
                <h5 class="fw-bold text-brand-primary mb-3">Rank Definitions & Reward Rules</h5>
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
                                                <i class="bi bi-save me-1"></i> Save Changes
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
            <div class="card card-stat bg-white p-4">
                <h5 class="fw-bold text-brand-primary mb-3">12-Level Affiliate Commission Matrix Rules</h5>
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
                                                <i class="bi bi-save me-1"></i> Save Level
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

<!-- Modal: Create New Business Rank Scheme -->
<div class="modal fade" id="createRankModal" tabindex="-1" aria-labelledby="createRankModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="schemes.php">
        <input type="hidden" name="action" value="create_rank">
        <div class="modal-header bg-brand-primary text-white">
          <h5 class="modal-title fw-bold" id="createRankModalLabel"><i class="bi bi-plus-circle-fill me-2"></i>Create New Rank Scheme Tier</h5>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
