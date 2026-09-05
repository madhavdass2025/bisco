<?php
// dashboard.php

require_once __DIR__ . '/includes/auth.php';
Auth::requireLogin();

$db = Database::getConnection();
$user = Auth::user();
$userId = (int)$user['id'];

// Rank Definitions Mapping
$rankNames = [
    0 => 'Member',
    1 => 'Promoter',
    2 => 'Senior Promoter',
    3 => 'Team Leader',
    4 => 'Team Manager',
    5 => 'Area Manager',
    6 => 'Zonal Manager',
    7 => 'Regional Manager',
    8 => 'State Head',
    9 => 'National Head',
    10 => 'Global Head',
    11 => 'Ambassador',
    12 => 'Crown Ambassador'
];

$currentRankLevel = (int)$user['rank_level'];
$currentRankName = $rankNames[$currentRankLevel] ?? 'Member';
$nextRankLevel = min(12, $currentRankLevel + 1);
$nextRankName = $rankNames[$nextRankLevel] ?? 'Max Rank Reached';

// Fetch user rank definition for next rank requirement
$stmtNextRank = $db->prepare("SELECT * FROM rank_definitions WHERE rank_id = ?");
$stmtNextRank->execute([$nextRankLevel]);
$nextRankDef = $stmtNextRank->fetch();

// Calculate progress percentage to next rank
$progressPercentage = 100;
$currentDownlineCount = 0;
$requiredDownlines = 5;

if ($currentRankLevel < 12) {
    if ($nextRankLevel === 1) {
        $stmtDirect = $db->prepare("
            SELECT COUNT(DISTINCT u.id) as cnt
            FROM users u
            INNER JOIN subscriptions s ON u.id = s.user_id
            WHERE u.sponsor_id = ? AND s.status = 'active'
        ");
        $stmtDirect->execute([$userId]);
        $currentDownlineCount = (int)$stmtDirect->fetch()['cnt'];
    } else {
        $reqRank = $nextRankLevel - 1;
        $queue = [$userId];
        $visited = [$userId => true];
        $cnt = 0;
        while (!empty($queue)) {
            $p = array_shift($queue);
            $stmtC = $db->prepare("SELECT id, rank_level FROM users WHERE sponsor_id = ?");
            $stmtC->execute([$p]);
            foreach ($stmtC->fetchAll() as $c) {
                if (!isset($visited[$c['id']])) {
                    $visited[$c['id']] = true;
                    if ((int)$c['rank_level'] >= $reqRank) {
                        $cnt++;
                    }
                    $queue[] = $c['id'];
                }
            }
        }
        $currentDownlineCount = $cnt;
    }
    $requiredDownlines = (int)($nextRankDef['requirement_team_count'] ?? 5);
    $progressPercentage = min(100, round(($currentDownlineCount / max(1, $requiredDownlines)) * 100));
}

// Fetch Active Subscriptions
$stmtSubs = $db->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY id DESC");
$stmtSubs->execute([$userId]);
$subscriptions = $stmtSubs->fetchAll();

// Fetch Recent Commissions Log
$stmtComms = $db->prepare("
    SELECT c.*, u.full_name as source_name
    FROM affiliate_commissions c
    LEFT JOIN users u ON c.source_user_id = u.id
    WHERE c.beneficiary_id = ?
    ORDER BY c.id DESC LIMIT 15
");
$stmtComms->execute([$userId]);
$commissions = $stmtComms->fetchAll();

// Fetch User's Created ePINs
$stmtEpins = $db->prepare("SELECT * FROM epins WHERE creator_user_id = ? ORDER BY id DESC LIMIT 15");
$stmtEpins->execute([$userId]);
$myEpins = $stmtEpins->fetchAll();

// Total Commission Cash Earned
$stmtTotalComm = $db->prepare("SELECT SUM(cash_amount) as total_cash, SUM(points_earned) as total_pts FROM affiliate_commissions WHERE beneficiary_id = ?");
$stmtTotalComm->execute([$userId]);
$commTotals = $stmtTotalComm->fetch();
$totalCashEarned = (float)($commTotals['total_cash'] ?? 0.00);
$totalPointsEarned = (int)($commTotals['total_pts'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POWERNET ASSOCIATE - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Razorpay Checkout JS -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <style>
        .tree-container { overflow-x: auto; padding: 20px 0; }
        .tree ul { padding-top: 20px; position: relative; display: flex; justify-content: center; }
        .tree li { float: left; text-align: center; list-style-type: none; position: relative; padding: 20px 5px 0 5px; }
        .tree li::before, .tree li::after{ content: ''; position: absolute; top: 0; right: 50%; border-top: 2px solid #ccc; width: 50%; height: 20px; }
        .tree li::after{ right: auto; left: 50%; border-left: 2px solid #ccc; }
        .tree li:only-child::after, .tree li:only-child::before { display: none; }
        .tree li:only-child{ padding-top: 0;}
        .tree li:first-child::before, .tree li:last-child::after{ border: 0 none; }
        .tree li:last-child::before{ border-right: 2px solid #ccc; border-radius: 0 5px 0 0; }
        .tree li:first-child::after{ border-radius: 5px 0 0 0; }
        .tree ul ul::before{ content: ''; position: absolute; top: 0; left: 50%; border-left: 2px solid #ccc; width: 0; height: 20px; }
        .tree-node-card { border: 2px solid var(--brand-magenta); background: #ffffff; padding: 8px 12px; text-decoration: none; color: #333; font-size: 12px; display: inline-block; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); min-width: 110px; }
        .tree-node-card:hover { background: #e9f5ed; }
        .progress-bar-custom { background: linear-gradient(90deg, var(--brand-secondary), var(--brand-gold-pure)); }
    </style>
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
                <div style="font-size: 11px; color: var(--brand-gold-pure); text-transform: uppercase; letter-spacing: 0.5px;"><?= htmlspecialchars($currentRankName) ?></div>
            </div>
        </div>

        <ul class="sidebar-menu">
            <li class="sidebar-menu-item active">
                <a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a>
            </li>
            <li class="sidebar-menu-item">
                <a href="#network-tree"><i class="fas fa-diagram-project"></i> Network Tree</a>
            </li>
            <li class="sidebar-menu-item">
                <a href="#epin-section"><i class="fas fa-key"></i> ePIN Engine</a>
            </li>
            <li class="sidebar-menu-item">
                <a href="#packages-section"><i class="fas fa-cart-shopping"></i> Buy Package</a>
            </li>
            <?php if (!empty($user['is_admin']) || (int)$user['id'] === 1): ?>
                <li class="sidebar-menu-item">
                    <a href="admin/schemes.php" style="color: var(--brand-gold-pure); fw-bold"><i class="fas fa-sliders"></i> Schemes Manager</a>
                </li>
            <?php endif; ?>

            <li class="sidebar-menu-item" style="margin-top: auto;">
                <a href="logout.php" style="color: var(--danger-color);"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
                    <span class="fw-semibold small"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </div>
        </nav>

        <!-- Content Page -->
        <div class="content-page">
            <div class="page-heading">
                <ul class="breadcrumb">
                    <li><a href="dashboard.php" style="text-decoration: none; color: inherit;">Home</a></li>
                    <li><i class="fas fa-chevron-right" style="font-size: 10px; margin: 0 5px; opacity: 0.5;"></i></li>
                    <li style="color: var(--brand-gold-pure); font-weight: 500;">Promoter Dashboard</li>
                </ul>
                <h1 class="gold-gradient-text" style="font-size: 32px; margin-top: 10px;">Network Overview</h1>
            </div>

            <div id="alertBox"></div>

            <!-- Stats Cards Row -->
            <div class="card-group-row">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-content">
                        <h3>Wallet Balance</h3>
                        <p>₹<?= number_format((float)$user['wallet_balance'], 2) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div class="stat-content">
                        <h3>Current Rank</h3>
                        <p style="font-size: 18px;"><?= htmlspecialchars($currentRankName) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div>
                    <div class="stat-content">
                        <h3>Total Earnings</h3>
                        <p>₹<?= number_format($totalCashEarned, 2) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-content">
                        <h3>Total Points</h3>
                        <p><?= number_format($totalPointsEarned) ?> Pts</p>
                    </div>
                </div>
            </div>

            <!-- Rank Progression Progress Card -->
            <div class="card p-4 mb-4 border-0 shadow-sm" style="border-radius: 12px;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="fw-bold mb-0 text-brand-primary"><i class="fas fa-chart-line me-2 text-warning"></i>12-Tier Rank Progression</h5>
                        <small class="text-muted">Target Next Rank: <strong class="text-success"><?= htmlspecialchars($nextRankName) ?></strong></small>
                    </div>
                    <span class="badge bg-success px-3 py-2 fs-6"><?= $progressPercentage ?>% Qualified</span>
                </div>
                <div class="progress my-2" style="height: 18px; border-radius: 10px;">
                    <div class="progress-bar progress-bar-custom progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?= $progressPercentage ?>%;" aria-valuenow="<?= $progressPercentage ?>" aria-valuemin="0" aria-valuemax="100">
                        <?= $progressPercentage ?>%
                    </div>
                </div>
                <div class="d-flex justify-content-between text-muted small mt-1">
                    <span>Current Qualified Network Downlines: <strong><?= $currentDownlineCount ?></strong></span>
                    <span>Required Downlines for <?= htmlspecialchars($nextRankName) ?>: <strong><?= $requiredDownlines ?></strong></span>
                </div>
            </div>

            <!-- Operations Section -->
            <div class="row g-4 mb-4">
                <!-- Package Activation (Razorpay / ePIN) -->
                <div class="col-md-6" id="packages-section">
                    <div class="card p-4 border-0 shadow-sm h-100" style="border-radius: 12px;">
                        <h5 class="fw-bold text-success mb-3"><i class="fas fa-cart-shopping me-2"></i>Activate Package / Subscription</h5>
                        <form id="purchaseForm">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Select Subscription Package</label>
                                <select id="packageSelect" class="form-select" required>
                                    <option value="voucher_600" data-amount="600">Voucher 600 (Classic Tier - ₹600)</option>
                                    <option value="voucher_300" data-amount="300">Voucher 300 (Basic Tier - ₹300)</option>
                                    <option value="smart_recharge" data-amount="100">Smart Recharge (Team Tier - ₹100)</option>
                                    <option value="sip_600" data-amount="600">SIP 600 (₹600 | ₹100/mo x 10 Mos)</option>
                                    <option value="sip_300" data-amount="300">SIP 300 (₹300 | ₹50/mo x 10 Mos)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Payment Gateway Method</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="payment_method" id="payRazorpay" value="razorpay" checked>
                                    <label class="form-check-label fw-bold" for="payRazorpay">
                                        <i class="fas fa-credit-card text-primary me-1"></i> Razorpay Online Gateway (UPI / Card)
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="payment_method" id="payEpin" value="epin">
                                    <label class="form-check-label fw-bold" for="payEpin">
                                        <i class="fas fa-key text-success me-1"></i> Electronic PIN (ePIN)
                                    </label>
                                </div>
                            </div>

                            <div id="epinInputGroup" class="mb-3 d-none">
                                <label class="form-label fw-semibold">Enter 16-Character ePIN Code</label>
                                <input type="text" id="epinCodeInput" class="form-control" placeholder="e.g. PN3A8F2K910L4M5N">
                            </div>

                            <button type="button" id="btnActivatePackage" class="btn btn-success text-white fw-bold w-100 py-2">
                                <i class="fas fa-bolt me-1"></i> Proceed & Activate
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Wallet to ePIN Generator Engine -->
                <div class="col-md-6" id="epin-section">
                    <div class="card p-4 border-0 shadow-sm h-100" style="border-radius: 12px;">
                        <h5 class="fw-bold text-success mb-3"><i class="fas fa-gear me-2"></i>Earning Wallet to ePIN Generator Engine</h5>
                        <p class="text-muted small">Convert your earned wallet balance into ePIN vouchers to register or upgrade new downline members.</p>
                        <form id="genEpinForm">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Select ePIN Voucher Package</label>
                                <select id="genPackageSelect" class="form-select" required>
                                    <option value="voucher_600">Voucher 600 (Deducts ₹600 from Wallet)</option>
                                    <option value="voucher_300">Voucher 300 (Deducts ₹300 from Wallet)</option>
                                    <option value="smart_recharge">Smart Recharge (Deducts ₹100 from Wallet)</option>
                                    <option value="sip_600">SIP 600 (Deducts ₹600 from Wallet)</option>
                                    <option value="sip_300">SIP 300 (Deducts ₹300 from Wallet)</option>
                                </select>
                            </div>
                            <button type="button" id="btnGenerateEpin" class="btn btn-warning text-dark fw-bold w-100 py-2">
                                <i class="fas fa-plus-circle me-1"></i> Generate ePIN Now
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Network Tree Card -->
            <div class="card p-4 border-0 shadow-sm mb-4" id="network-tree" style="border-radius: 12px;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-0 text-success"><i class="fas fa-diagram-project me-2"></i>12-Level Network Tree Visualization</h5>
                        <small class="text-muted">Interactive downline structure tree showing user ranks and active subscriptions.</small>
                    </div>
                    <button id="btnRefreshTree" class="btn btn-outline-success btn-sm"><i class="fas fa-arrows-rotate me-1"></i> Refresh Tree</button>
                </div>
                <div class="tree-container">
                    <div class="tree" id="treeRoot">
                        <div class="text-center py-4"><div class="spinner-border text-success" role="status"></div></div>
                    </div>
                </div>
            </div>

            <!-- Tables Row -->
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card p-4 border-0 shadow-sm" style="border-radius: 12px;">
                        <h5 class="fw-bold text-success mb-3"><i class="fas fa-receipt me-2"></i>Recent Commission Earnings</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Level</th>
                                        <th>Source Downline</th>
                                        <th>Points</th>
                                        <th>Cash Earned</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($commissions)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">No commission logs yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($commissions as $comm): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($comm['level'] == 0): ?>
                                                        <span class="badge bg-warning text-dark">Self Bonus</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">L-<?= $comm['level'] ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($comm['source_name'] ?: ('User #' . $comm['source_user_id'])) ?></td>
                                                <td><strong><?= $comm['points_earned'] ?> Pts</strong></td>
                                                <td class="text-success fw-bold">+₹<?= number_format((float)$comm['cash_amount'], 2) ?></td>
                                                <td><?= date('M d, H:i', strtotime($comm['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card p-4 border-0 shadow-sm" style="border-radius: 12px;">
                        <h5 class="fw-bold text-success mb-3"><i class="fas fa-ticket me-2"></i>My Generated ePINs</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ePIN Code</th>
                                        <th>Package</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($myEpins)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">No ePINs generated yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($myEpins as $pin): ?>
                                            <tr>
                                                <td><code class="fw-bold text-dark"><?= htmlspecialchars($pin['pin_code']) ?></code></td>
                                                <td><?= htmlspecialchars($pin['package_type']) ?></td>
                                                <td>₹<?= number_format((float)$pin['value_amount'], 2) ?></td>
                                                <td>
                                                    <?php if ($pin['status'] === 'unused'): ?>
                                                        <span class="badge bg-success">Unused</span>
                                                    <?php elseif ($pin['status'] === 'used'): ?>
                                                        <span class="badge bg-secondary">Used</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Cancelled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= date('M d, H:i', strtotime($pin['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar Toggle
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

    // Payment radios
    const payMethodRadios = document.getElementsByName('payment_method');
    const epinGroup = document.getElementById('epinInputGroup');

    payMethodRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'epin') {
                epinGroup.classList.remove('d-none');
            } else {
                epinGroup.classList.add('d-none');
            }
        });
    });

    function showAlert(type, msg) {
        const box = document.getElementById('alertBox');
        box.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show shadow-sm" role="alert">
                ${msg}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
    }

    // Activate Package Handler
    document.getElementById('btnActivatePackage').addEventListener('click', async function() {
        const method = document.querySelector('input[name="payment_method"]:checked').value;
        const pkgSelect = document.getElementById('packageSelect');
        const pkgType = pkgSelect.value;
        const amount = pkgSelect.options[pkgSelect.selectedIndex].getAttribute('data-amount');

        if (method === 'epin') {
            const epinCode = document.getElementById('epinCodeInput').value.trim();
            if (!epinCode) {
                showAlert('danger', 'Please enter a valid ePIN code.');
                return;
            }

            try {
                const response = await fetch('redeem_epin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pin_code: epinCode })
                });
                const data = await response.json();
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('danger', data.message);
                }
            } catch (err) {
                showAlert('danger', 'An error occurred while redeeming ePIN.');
            }
        } else if (method === 'razorpay') {
            const options = {
                "key": "rzp_test_mock_key",
                "amount": amount * 100,
                "currency": "INR",
                "name": "POWERNET ASSOCIATE - BISCO",
                "description": "Package Subscription: " + pkgType,
                "handler": async function (response) {
                    try {
                        const cbRes = await fetch('razorpay_callback.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                package_type: pkgType,
                                amount: amount,
                                razorpay_payment_id: response.razorpay_payment_id || 'pay_mock_' + Date.now(),
                                razorpay_order_id: response.razorpay_order_id || 'order_mock_' + Date.now(),
                                razorpay_signature: response.razorpay_signature || 'mock_signature'
                            })
                        });
                        const cbData = await cbRes.json();
                        if (cbData.success) {
                            showAlert('success', cbData.message);
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showAlert('danger', cbData.message);
                        }
                    } catch (e) {
                        showAlert('danger', 'Error processing Razorpay callback.');
                    }
                },
                "prefill": {
                    "name": "<?= htmlspecialchars($user['full_name']) ?>",
                    "contact": "<?= htmlspecialchars($user['phone']) ?>"
                },
                "theme": { "color": "#006837" }
            };

            try {
                const rzp = new Razorpay(options);
                rzp.open();
            } catch (err) {
                if (confirm("Simulate Razorpay Payment completion for test environment?")) {
                    const cbRes = await fetch('razorpay_callback.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            package_type: pkgType,
                            amount: amount,
                            razorpay_payment_id: 'pay_sim_' + Date.now(),
                            razorpay_order_id: 'order_sim_' + Date.now(),
                            razorpay_signature: 'mock_signature'
                        })
                    });
                    const cbData = await cbRes.json();
                    if (cbData.success) {
                        showAlert('success', cbData.message);
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', cbData.message);
                    }
                }
            }
        }
    });

    // Generate ePIN Handler
    document.getElementById('btnGenerateEpin').addEventListener('click', async function() {
        const pkgType = document.getElementById('genPackageSelect').value;

        try {
            const res = await fetch('wallet_to_epin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ package_type: pkgType })
            });
            const data = await res.json();
            if (data.success) {
                showAlert('success', `${data.message} Code: <strong>${data.pin_code}</strong>`);
                setTimeout(() => location.reload(), 2000);
            } else {
                showAlert('danger', data.message);
            }
        } catch (e) {
            showAlert('danger', 'Error generating ePIN.');
        }
    });

    // Load Tree Function
    async function loadNetworkTree() {
        const treeRoot = document.getElementById('treeRoot');
        try {
            const res = await fetch('api/tree.php');
            const data = await res.json();

            if (!data.success) {
                treeRoot.innerHTML = '<div class="text-danger">Failed to load network tree.</div>';
                return;
            }

            function renderNode(node) {
                let html = `<li>`;
                html += `
                    <div class="tree-node-card">
                        <div class="fw-bold text-success">${node.full_name}</div>
                        <div class="badge bg-warning text-dark my-1">${node.rank_name}</div>
                        <div class="text-muted extra-small">Phone: ${node.phone}</div>
                        <div class="text-success extra-small fw-semibold">Subs: ${node.active_subs} Active</div>
                    </div>
                `;
                if (node.children && node.children.length > 0) {
                    html += `<ul>`;
                    node.children.forEach(child => {
                        html += renderNode(child);
                    });
                    html += `</ul>`;
                }
                html += `</li>`;
                return html;
            }

            let rootHtml = `<ul><li>`;
            rootHtml += `
                <div class="tree-node-card border-warning bg-light">
                    <div class="fw-bold text-success">${data.user.full_name} (YOU)</div>
                    <div class="badge bg-success my-1">${data.user.rank_name}</div>
                    <div class="text-muted extra-small">Root Level</div>
                </div>
            `;

            if (data.tree && data.tree.length > 0) {
                rootHtml += `<ul>`;
                data.tree.forEach(child => {
                    rootHtml += renderNode(child);
                });
                rootHtml += `</ul>`;
            } else {
                rootHtml += `<div class="p-2 text-muted small mt-2">No downline members in your network yet.</div>`;
            }

            rootHtml += `</li></ul>`;
            treeRoot.innerHTML = rootHtml;

        } catch (e) {
            treeRoot.innerHTML = '<div class="text-danger">Error rendering network tree visualization.</div>';
        }
    }

    document.getElementById('btnRefreshTree').addEventListener('click', loadNetworkTree);
    loadNetworkTree();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
