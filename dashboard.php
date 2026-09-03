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
        // Direct active sales count
        $stmtDirect = $db->prepare("
            SELECT COUNT(DISTINCT u.id) as cnt
            FROM users u
            INNER JOIN subscriptions s ON u.id = s.user_id
            WHERE u.sponsor_id = ? AND s.status = 'active'
        ");
        $stmtDirect->execute([$userId]);
        $currentDownlineCount = (int)$stmtDirect->fetch()['cnt'];
    } else {
        // Count downlines with rank >= (nextRankLevel - 1)
        $reqRank = $nextRankLevel - 1;
        // Recursive helper to count qualified downlines
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
    <title>Dashboard - POWERNET ASSOCIATE - BISCO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Razorpay Checkout JS -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <style>
        :root {
            --primary-green: #006837;
            --secondary-green: #39B54A;
            --accent-gold: #F7941E;
            --dark-bg: #0f2719;
        }
        body {
            background-color: #f4f7f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand-custom {
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #ffffff !important;
        }
        .bg-brand-primary {
            background-color: var(--primary-green) !important;
        }
        .text-brand-primary {
            color: var(--primary-green) !important;
        }
        .text-brand-accent {
            color: var(--accent-gold) !important;
        }
        .card-stat {
            border: none;
            border-radius: 12px;
            transition: transform 0.2s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .card-stat:hover {
            transform: translateY(-3px);
        }
        .progress-bar-custom {
            background: linear-gradient(90deg, var(--secondary-green), var(--accent-gold));
        }
        /* Network Tree Styles */
        .tree-container {
            overflow-x: auto;
            padding: 20px 0;
        }
        .tree ul {
            padding-top: 20px;
            position: relative;
            transition: all 0.5s;
            display: flex;
            justify-content: center;
        }
        .tree li {
            float: left; text-align: center;
            list-style-type: none;
            position: relative;
            padding: 20px 5px 0 5px;
            transition: all 0.5s;
        }
        .tree li::before, .tree li::after{
            content: '';
            position: absolute; top: 0; right: 50%;
            border-top: 2px solid #ccc;
            width: 50%; height: 20px;
        }
        .tree li::after{
            right: auto; left: 50%;
            border-left: 2px solid #ccc;
        }
        .tree li:only-child::after, .tree li:only-child::before {
            display: none;
        }
        .tree li:only-child{ padding-top: 0;}
        .tree li:first-child::before, .tree li:last-child::after{
            border: 0 none;
        }
        .tree li:last-child::before{
            border-right: 2px solid #ccc;
            border-radius: 0 5px 0 0;
        }
        .tree li:first-child::after{
            border-radius: 5px 0 0 0;
        }
        .tree ul ul::before{
            content: '';
            position: absolute; top: 0; left: 50%;
            border-left: 2px solid #ccc;
            width: 0; height: 20px;
        }
        .tree-node-card {
            border: 2px solid var(--primary-green);
            background: #ffffff;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            font-size: 12px;
            display: inline-block;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            min-width: 110px;
        }
        .tree-node-card:hover {
            background: #e9f5ed;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg bg-brand-primary navbar-dark shadow-sm py-3">
    <div class="container">
        <a class="navbar-brand navbar-brand-custom" href="dashboard.php">
            <i class="bi bi-diagram-3-fill me-2 text-warning"></i>POWERNET ASSOCIATE <span class="badge bg-warning text-dark ms-2">BISCO</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link active" href="#overview"><i class="bi bi-speedometer2 me-1"></i> Overview</a></li>
                <li class="nav-item"><a class="nav-link" href="#network-tree"><i class="bi bi-diagram-3 me-1"></i> Network Tree</a></li>
                <li class="nav-item"><a class="nav-link" href="#epin-section"><i class="bi bi-ticket-perforated me-1"></i> ePIN Engine</a></li>
                <li class="nav-item"><a class="nav-link" href="#packages-section"><i class="bi bi-cart-check me-1"></i> Buy Package</a></li>
            </ul>
            <div class="d-flex align-items-center text-white">
                <div class="me-3 text-end d-none d-md-block">
                    <div class="fw-bold"><?= htmlspecialchars($user['full_name']) ?></div>
                    <small class="text-warning">ID: #<?= $user['id'] ?> | Sponsor: #<?= $user['sponsor_id'] ?: 'None' ?></small>
                </div>
                <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="container my-4" id="overview">
    <!-- Alert Box -->
    <div id="alertBox"></div>

    <!-- Stats Cards Row -->
    <div class="row g-3 mb-4">
        <!-- Wallet Balance Card -->
        <div class="col-md-3">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle p-3 bg-success bg-opacity-10 text-success me-3">
                        <i class="bi bi-wallet2 fs-3"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-semibold">Wallet Balance</small>
                        <h3 class="mb-0 fw-bold text-brand-primary">₹<?= number_format((float)$user['wallet_balance'], 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rank Level Card -->
        <div class="col-md-3">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle p-3 bg-warning bg-opacity-10 text-warning me-3">
                        <i class="bi bi-trophy-fill fs-3"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-semibold">Current Rank</small>
                        <h4 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($currentRankName) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Commission Card -->
        <div class="col-md-3">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle p-3 bg-primary bg-opacity-10 text-primary me-3">
                        <i class="bi bi-cash-stack fs-3"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-semibold">Total Earnings</small>
                        <h3 class="mb-0 fw-bold text-primary">₹<?= number_format($totalCashEarned, 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Points Card -->
        <div class="col-md-3">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle p-3 bg-info bg-opacity-10 text-info me-3">
                        <i class="bi bi-star-fill fs-3"></i>
                    </div>
                    <div>
                        <small class="text-muted fw-semibold">Total Points</small>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($totalPointsEarned) ?> Pts</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rank Progression Progress Bar Card -->
    <div class="card card-stat bg-white p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h5 class="fw-bold mb-0"><i class="bi bi-graph-up-arrow me-2 text-warning"></i>12-Tier Rank Progression</h5>
                <small class="text-muted">Target Next Rank: <strong class="text-brand-primary"><?= htmlspecialchars($nextRankName) ?></strong></small>
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

    <!-- Packages & ePIN Operations Section -->
    <div class="row g-4 mb-4">
        <!-- Package Activation (Razorpay / ePIN) -->
        <div class="col-md-6" id="packages-section">
            <div class="card card-stat bg-white p-4 h-100">
                <h5 class="fw-bold text-brand-primary mb-3"><i class="bi bi-bag-check-fill me-2"></i>Activate Package / Subscription</h5>
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
                                <i class="bi bi-credit-card-2-front text-primary me-1"></i> Razorpay Online Gateway (UPI / Card / NetBanking)
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="payment_method" id="payEpin" value="epin">
                            <label class="form-check-label fw-bold" for="payEpin">
                                <i class="bi bi-ticket-perforated text-success me-1"></i> Electronic PIN (ePIN)
                            </label>
                        </div>
                    </div>

                    <div id="epinInputGroup" class="mb-3 d-none">
                        <label class="form-label fw-semibold">Enter 16-Character ePIN Code</label>
                        <input type="text" id="epinCodeInput" class="form-control" placeholder="e.g. PN3A8F2K910L4M5N">
                    </div>

                    <button type="button" id="btnActivatePackage" class="btn bg-brand-primary text-white fw-bold w-100 py-2">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Proceed & Activate
                    </button>
                </form>
            </div>
        </div>

        <!-- Wallet to ePIN Generator Engine -->
        <div class="col-md-6" id="epin-section">
            <div class="card card-stat bg-white p-4 h-100">
                <h5 class="fw-bold text-brand-primary mb-3"><i class="bi bi-gear-wide-connected me-2"></i>Earning Wallet to ePIN Generator Engine</h5>
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
                        <i class="bi bi-plus-circle-fill me-1"></i> Generate ePIN Now
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 12-Level Interactive Network Tree Visualization -->
    <div class="card card-stat bg-white p-4 mb-4" id="network-tree">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-brand-primary"><i class="bi bi-diagram-3-fill me-2"></i>12-Level Network Tree Visualization</h5>
                <small class="text-muted">Interactive downline structure tree showing user ranks and active subscriptions.</small>
            </div>
            <button id="btnRefreshTree" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Refresh Tree</button>
        </div>
        <div class="tree-container">
            <div class="tree" id="treeRoot">
                <div class="text-center py-4"><div class="spinner-border text-success" role="status"></div></div>
            </div>
        </div>
    </div>

    <!-- Recent Logs Row -->
    <div class="row g-4">
        <!-- Commission Earnings History -->
        <div class="col-md-6">
            <div class="card card-stat bg-white p-4">
                <h5 class="fw-bold text-brand-primary mb-3"><i class="bi bi-receipt me-2"></i>Recent Commission Earnings</h5>
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

        <!-- Generated ePINs History -->
        <div class="col-md-6">
            <div class="card card-stat bg-white p-4">
                <h5 class="fw-bold text-brand-primary mb-3"><i class="bi bi-ticket-detailed me-2"></i>My Generated ePINs</h5>
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

<script class="code-script">
document.addEventListener('DOMContentLoaded', function() {
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

    // Helper Alert Function
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
            // Razorpay Checkout JS Integration
            const options = {
                "key": "rzp_test_mock_key",
                "amount": amount * 100, // Amount in paise
                "currency": "INR",
                "name": "POWERNET ASSOCIATE - BISCO",
                "description": "Package Subscription: " + pkgType,
                "handler": async function (response) {
                    // Send to razorpay_callback.php
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
                // Fallback for test environment where Razorpay JS SDK script might be blocked/offline
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

    // Fetch and Build 12-Level Interactive Network Tree
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
                        <div class="fw-bold text-brand-primary">${node.full_name}</div>
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
                    <div class="fw-bold text-brand-primary">${data.user.full_name} (YOU)</div>
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
