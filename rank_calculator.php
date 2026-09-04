<?php
// rank_calculator.php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/sms_helper.php';

class RankCalculator {

    /**
     * Calculates team qualification and upgrades ranks for users.
     * Also processes monthly rank incentives.
     *
     * @param string|null $currentDate Format: Y-m-d
     * @return array
     */
    public static function processRanksAndPayouts(?string $currentDate = null): array {
        $db = Database::getConnection();
        $today = $currentDate ?: date('Y-m-d');

        // Fetch rank definitions
        $stmtDefs = $db->query("SELECT * FROM rank_definitions ORDER BY rank_id ASC");
        $rankDefs = [];
        foreach ($stmtDefs->fetchAll() as $r) {
            $rankDefs[(int)$r['rank_id']] = $r;
        }

        $upgrades = [];
        $monthlyPayouts = [];

        // 1. Process Rank Upgrades
        // Keep looping until no new upgrades occur to handle chain upgrades (e.g., direct downlines upgrade -> sponsor upgrades)
        $hasNewUpgrades = true;
        while ($hasNewUpgrades) {
            $hasNewUpgrades = false;

            // Fetch all users
            $stmtUsers = $db->query("SELECT id, full_name, phone, rank_level FROM users ORDER BY id ASC");
            $users = $stmtUsers->fetchAll();

            foreach ($users as $user) {
                $userId = (int)$user['id'];
                $currentRank = (int)$user['rank_level'];
                $nextRank = $currentRank + 1;

                if ($nextRank > 12) {
                    continue; // Max rank reached
                }

                $nextRankDef = $rankDefs[$nextRank] ?? null;
                if (!$nextRankDef) {
                    continue;
                }

                $reqCount = (int)$nextRankDef['requirement_team_count'];
                $qualified = false;

                if ($nextRank === 1) {
                    // Rank 1 (Promoter): Requires 5 Direct Sales (direct downlines who have at least 1 active subscription)
                    $stmtDirects = $db->prepare("
                        SELECT COUNT(DISTINCT u.id) as cnt
                        FROM users u
                        INNER JOIN subscriptions s ON u.id = s.user_id
                        WHERE u.sponsor_id = ? AND s.status = 'active'
                    ");
                    $stmtDirects->execute([$userId]);
                    $directCount = (int)$stmtDirects->fetch()['cnt'];

                    if ($directCount >= $reqCount) {
                        $qualified = true;
                    }
                } else {
                    // Ranks 2..12: Requires 5 team members (in downline network) having achieved rank_level >= (nextRank - 1)
                    $reqRankLevel = $nextRank - 1;
                    $teamRankCount = self::countDownlinesWithRank($db, $userId, $reqRankLevel);

                    if ($teamRankCount >= $reqCount) {
                        $qualified = true;
                    }
                }

                if ($qualified) {
                    // Perform Upgrade
                    $db->beginTransaction();
                    try {
                        // Update user rank and credit one-time rank incentive to wallet
                        $rankIncentive = (float)$nextRankDef['rank_incentive'];

                        $stmtUpd = $db->prepare("UPDATE users SET rank_level = ?, wallet_balance = wallet_balance + ? WHERE id = ?");
                        $stmtUpd->execute([$nextRank, $rankIncentive, $userId]);

                        // Log one-time rank payout
                        $stmtLog = $db->prepare("INSERT INTO rank_payout_logs (user_id, rank_id, payout_type, amount, payout_date) VALUES (?, ?, 'one_time_rank', ?, ?)");
                        $stmtLog->execute([$userId, $nextRank, $rankIncentive, $today]);

                        $db->commit();

                        $hasNewUpgrades = true;
                        $upgrades[] = [
                            'user_id' => $userId,
                            'old_rank' => $currentRank,
                            'new_rank' => $nextRank,
                            'rank_name' => $nextRankDef['rank_name'],
                            'incentive' => $rankIncentive
                        ];

                        SMSHelper::sendSMS(
                            $user['phone'],
                            "Congratulations {$user['full_name']}! You have achieved the rank of {$nextRankDef['rank_name']}! Bonus: Rs.{$rankIncentive} credited to wallet."
                        );

                    } catch (Exception $e) {
                        $db->rollBack();
                        throw $e;
                    }
                }
            }
        }

        // 2. Process Monthly Incentives
        // Check for users eligible for monthly payouts on or before $today
        $stmtActiveRankUsers = $db->query("SELECT id, full_name, phone, rank_level FROM users WHERE rank_level >= 2");
        $activeRankUsers = $stmtActiveRankUsers->fetchAll();

        foreach ($activeRankUsers as $user) {
            $userId = (int)$user['id'];
            $userRank = (int)$user['rank_level'];

            // Process monthly payouts for all ranks user has achieved that offer monthly incentives (ranks 2 to 12)
            for ($r = 2; $r <= $userRank; $r++) {
                $rDef = $rankDefs[$r] ?? null;
                if (!$rDef || (float)$rDef['monthly_incentive'] <= 0) {
                    continue;
                }

                $monthlyAmount = (float)$rDef['monthly_incentive'];
                $maxDuration = (int)$rDef['monthly_duration_months'];

                // Check how many monthly payouts already executed for this user & rank
                $stmtCount = $db->prepare("SELECT COUNT(*) as paid_months FROM rank_payout_logs WHERE user_id = ? AND rank_id = ? AND payout_type = 'monthly_incentive'");
                $stmtCount->execute([$userId, $r]);
                $paidMonths = (int)$stmtCount->fetch()['paid_months'];

                if ($paidMonths >= $maxDuration) {
                    continue; // Completed max duration
                }

                // Check if payout was already made this calendar month
                $currentMonthYear = date('Y-m', strtotime($today));
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

                if ($driver === 'sqlite') {
                    $stmtCheckMonth = $db->prepare("SELECT id FROM rank_payout_logs WHERE user_id = ? AND rank_id = ? AND payout_type = 'monthly_incentive' AND strftime('%Y-%m', payout_date) = ?");
                } else {
                    $stmtCheckMonth = $db->prepare("SELECT id FROM rank_payout_logs WHERE user_id = ? AND rank_id = ? AND payout_type = 'monthly_incentive' AND DATE_FORMAT(payout_date, '%Y-%m') = ?");
                }

                $stmtCheckMonth->execute([$userId, $r, $currentMonthYear]);
                if ($stmtCheckMonth->fetch()) {
                    continue; // Already paid this month
                }

                // Execute Monthly Payout
                $db->beginTransaction();
                try {
                    $stmtUpd = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                    $stmtUpd->execute([$monthlyAmount, $userId]);

                    $stmtLog = $db->prepare("INSERT INTO rank_payout_logs (user_id, rank_id, payout_type, amount, payout_date) VALUES (?, ?, 'monthly_incentive', ?, ?)");
                    $stmtLog->execute([$userId, $r, $monthlyAmount, $today]);

                    $db->commit();

                    $monthlyPayouts[] = [
                        'user_id' => $userId,
                        'rank_id' => $r,
                        'rank_name' => $rDef['rank_name'],
                        'amount' => $monthlyAmount,
                        'month_number' => $paidMonths + 1
                    ];

                    SMSHelper::sendSMS(
                        $user['phone'],
                        "Dear {$user['full_name']}, your monthly rank incentive of Rs.{$monthlyAmount} for {$rDef['rank_name']} (Month " . ($paidMonths + 1) . "/{$maxDuration}) has been credited!"
                    );

                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        }

        return [
            'success' => true,
            'upgrades' => $upgrades,
            'monthly_payouts' => $monthlyPayouts
        ];
    }

    /**
     * Recursively counts downline network users with rank_level >= $requiredRankLevel.
     */
    private static function countDownlinesWithRank(PDO $db, int $userId, int $requiredRankLevel): int {
        $count = 0;
        $queue = [$userId];
        $visited = [$userId => true];

        while (!empty($queue)) {
            $currentParentId = array_shift($queue);

            $stmt = $db->prepare("SELECT id, rank_level FROM users WHERE sponsor_id = ?");
            $stmt->execute([$currentParentId]);
            $children = $stmt->fetchAll();

            foreach ($children as $child) {
                $childId = (int)$child['id'];
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;

                if ((int)$child['rank_level'] >= $requiredRankLevel) {
                    $count++;
                }

                $queue[] = $childId;
            }
        }

        return $count;
    }
}

// CLI Execution Handler
if (php_sapi_name() === 'cli' && basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    $date = $argv[1] ?? date('Y-m-d');
    try {
        $res = RankCalculator::processRanksAndPayouts($date);
        echo "Rank & Payout Calculator executed successfully:\n";
        print_r($res);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
