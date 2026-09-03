<?php
// redeem_epin.php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/calculate_commissions.php';
require_once __DIR__ . '/includes/sms_helper.php';

class EpinRedeemer {

    /**
     * Redeems an ePIN, activates/creates subscription, and triggers 12-level commission matrix.
     */
    public static function redeem(int $userId, string $pinCode): array {
        $db = Database::getConnection();
        $pinCode = trim(strtoupper($pinCode));

        if (empty($pinCode)) {
            return ['success' => false, 'message' => 'ePIN code is required.'];
        }

        $db->beginTransaction();

        try {
            // Find unused ePIN
            $stmtEpin = $db->prepare("SELECT * FROM epins WHERE pin_code = ? AND status = 'unused' FOR UPDATE");

            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmtEpin = $db->prepare("SELECT * FROM epins WHERE pin_code = ? AND status = 'unused'");
            }

            $stmtEpin->execute([$pinCode]);
            $epin = $stmtEpin->fetch();

            if (!$epin) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Invalid or already used ePIN code.'];
            }

            $packageType = $epin['package_type'];
            $amountPaid = (float)$epin['value_amount'];

            // Calculate monthly return and duration defaults based on package
            $monthlyReturn = 0.00;
            $totalMonths = 1;

            if ($packageType === 'sip_300') {
                $monthlyReturn = 50.00;
                $totalMonths = 10;
            } elseif ($packageType === 'sip_600') {
                $monthlyReturn = 100.00;
                $totalMonths = 10;
            }

            // Mark ePIN as used
            $now = date('Y-m-d H:i:s');
            $stmtMark = $db->prepare("UPDATE epins SET status = 'used', used_by_user_id = ?, used_at = ? WHERE id = ?");
            $stmtMark->execute([$userId, $now, $epin['id']]);

            // Create Subscription
            $today = date('Y-m-d');
            $stmtSub = $db->prepare("
                INSERT INTO subscriptions (user_id, package_type, payment_method, amount_paid, monthly_return_amount, total_months, months_paid, status, start_date)
                VALUES (?, ?, 'epin', ?, ?, ?, 0, 'active', ?)
            ");
            $stmtSub->execute([$userId, $packageType, $amountPaid, $monthlyReturn, $totalMonths, $today]);
            $subId = (int)$db->lastInsertId();

            // Trigger 12-level commission calculator matrix
            $commResult = CommissionCalculator::processSubscriptionCommissions($userId, $packageType, $db);

            $db->commit();

            // Get user phone for SMS
            $stmtUser = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();
            if ($user) {
                SMSHelper::sendSMS(
                    $user['phone'],
                    "Dear {$user['full_name']}, ePIN {$pinCode} redeemed successfully! Subscription package '{$packageType}' is now ACTIVE."
                );
            }

            return [
                'success' => true,
                'message' => 'ePIN redeemed and subscription activated successfully!',
                'subscription_id' => $subId,
                'package_type' => $packageType,
                'commissions' => $commResult
            ];

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'message' => 'Redemption failed: ' . $e->getMessage()];
        }
    }
}

// HTTP POST Request Handler
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) == 'redeem_epin.php') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/includes/auth.php';

    if (!Auth::check()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $pinCode = $input['pin_code'] ?? '';
    $user = Auth::user();

    $result = EpinRedeemer::redeem($user['id'], $pinCode);
    echo json_encode($result);
    exit;
}
