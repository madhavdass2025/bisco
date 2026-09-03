<?php
// calculate_commissions.php

require_once __DIR__ . '/config/db.php';

class CommissionCalculator {

    /**
     * Calculates and distributes 12-level affiliate commissions + self earn bonus.
     *
     * @param int $userId ID of user purchasing/activating subscription
     * @param string $packageType 'smart_recharge', 'voucher_300', 'voucher_600', 'sip_300', 'sip_600'
     * @param PDO|null $pdo
     * @return array Summary of processed commissions
     */
    public static function processSubscriptionCommissions(int $userId, string $packageType, ?PDO $pdo = null): array {
        $db = $pdo ?: Database::getConnection();
        $inTransaction = $db->inTransaction();

        if (!$inTransaction) {
            $db->beginTransaction();
        }

        $logs = [];

        try {
            // 1. Self Earn Bonus: 300 Points
            $selfBonusPoints = 300;
            $selfBonusAmount = (float)$selfBonusPoints; // 1 Point = ₹1

            $stmtSelf = $db->prepare("INSERT INTO affiliate_commissions (beneficiary_id, source_user_id, level, points_earned, cash_amount) VALUES (?, ?, 0, ?, ?)");
            $stmtSelf->execute([$userId, $userId, $selfBonusPoints, $selfBonusAmount]);

            $stmtUpdateWallet = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmtUpdateWallet->execute([$selfBonusAmount, $userId]);

            $logs[] = [
                'level' => 0,
                'beneficiary_id' => $userId,
                'points' => $selfBonusPoints,
                'cash' => $selfBonusAmount,
                'type' => 'Self Earn Bonus'
            ];

            // 2. Fetch payout rules indexed by level
            $rulesStmt = $db->query("SELECT * FROM affiliate_payout_rules");
            $rulesData = $rulesStmt->fetchAll();
            $payoutRules = [];
            foreach ($rulesData as $rule) {
                $payoutRules[(int)$rule['level']] = $rule;
            }

            // 3. Traverse sponsor tree up to 12 levels
            $currentUserId = $userId;

            for ($level = 1; $level <= 12; $level++) {
                // Get sponsor of current user
                $stmtSponsor = $db->prepare("SELECT sponsor_id FROM users WHERE id = ?");
                $stmtSponsor->execute([$currentUserId]);
                $sponsorRow = $stmtSponsor->fetch();

                if (!$sponsorRow || empty($sponsorRow['sponsor_id'])) {
                    // No further sponsor in chain
                    break;
                }

                $sponsorId = (int)$sponsorRow['sponsor_id'];
                if ($sponsorId <= 0) {
                    break;
                }

                // Determine points to award based on package_type
                $rule = $payoutRules[$level] ?? null;
                $points = 0;

                if ($rule) {
                    if (in_array($packageType, ['voucher_600', 'sip_600'])) {
                        $points = (int)$rule['classic_points'];
                    } elseif (in_array($packageType, ['voucher_300', 'sip_300'])) {
                        $points = (int)$rule['basic_points'];
                    } else { // smart_recharge
                        $points = (int)$rule['team_points'];
                    }
                }

                if ($points > 0) {
                    $cashAmount = (float)$points;

                    $stmtComm = $db->prepare("INSERT INTO affiliate_commissions (beneficiary_id, source_user_id, level, points_earned, cash_amount) VALUES (?, ?, ?, ?, ?)");
                    $stmtComm->execute([$sponsorId, $userId, $level, $points, $cashAmount]);

                    $stmtUpdateWallet->execute([$cashAmount, $sponsorId]);

                    $logs[] = [
                        'level' => $level,
                        'beneficiary_id' => $sponsorId,
                        'points' => $points,
                        'cash' => $cashAmount
                    ];
                }

                // Move up sponsor chain
                $currentUserId = $sponsorId;
            }

            if (!$inTransaction) {
                $db->commit();
            }

            return [
                'success' => true,
                'user_id' => $userId,
                'package_type' => $packageType,
                'commissions_awarded' => $logs
            ];

        } catch (Exception $e) {
            if (!$inTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

// CLI Execution Handler
if (php_sapi_name() === 'cli' && basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    $userId = isset($argv[1]) ? (int)$argv[1] : 0;
    $packageType = isset($argv[2]) ? $argv[2] : 'voucher_600';

    if ($userId <= 0) {
        echo "Usage: php calculate_commissions.php <user_id> [package_type]\n";
        exit(1);
    }

    try {
        $res = CommissionCalculator::processSubscriptionCommissions($userId, $packageType);
        echo "Commissions calculated successfully:\n";
        print_r($res);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
