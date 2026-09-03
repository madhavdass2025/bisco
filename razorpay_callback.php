<?php
// razorpay_callback.php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/calculate_commissions.php';
require_once __DIR__ . '/includes/sms_helper.php';

class RazorpayGateway {
    private static string $keySecret = 'MOCK_RAZORPAY_SECRET';

    public static function verifySignature(string $orderId, string $paymentId, string $signature): bool {
        // In local/test mode with mock data
        if ($signature === 'mock_signature' || getenv('RAZORPAY_TEST_MODE') !== 'false') {
            return true;
        }

        $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, self::$keySecret);
        return hash_equals($expectedSignature, $signature);
    }

    public static function processPayment(int $userId, string $packageType, float $amount, string $paymentId, string $orderId, string $signature): array {
        $db = Database::getConnection();

        if (!self::verifySignature($orderId, $paymentId, $signature)) {
            return ['success' => false, 'message' => 'Invalid Razorpay signature verification.'];
        }

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
                VALUES (?, ?, 'razorpay', ?, ?, ?, 0, 'active', ?)
            ");
            $stmtSub->execute([$userId, $packageType, $amount, $monthlyReturn, $totalMonths, $today]);
            $subId = (int)$db->lastInsertId();

            // Execute 12-level affiliate commissions
            $commResult = CommissionCalculator::processSubscriptionCommissions($userId, $packageType, $db);

            $db->commit();

            // Send SMS notification
            $stmtUser = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();
            if ($user) {
                SMSHelper::sendSMS(
                    $user['phone'],
                    "Dear {$user['full_name']}, online payment of Rs.{$amount} via Razorpay was successful! Subscription package '{$packageType}' is ACTIVE."
                );
            }

            return [
                'success' => true,
                'message' => 'Razorpay payment processed and subscription activated successfully.',
                'subscription_id' => $subId,
                'package_type' => $packageType,
                'payment_id' => $paymentId,
                'commissions' => $commResult
            ];

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'message' => 'Payment processing failed: ' . $e->getMessage()];
        }
    }
}

// HTTP Callback / Webhook / Fetch API Handler
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) == 'razorpay_callback.php') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/includes/auth.php';

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    // Webhook event handling
    if (isset($input['event']) && $input['event'] === 'payment.captured') {
        $payload = $input['payload']['payment']['entity'] ?? [];
        $notes = $payload['notes'] ?? [];
        $userId = (int)($notes['user_id'] ?? 0);
        $packageType = $notes['package_type'] ?? 'voucher_300';
        $amount = (float)(($payload['amount'] ?? 0) / 100);
        $paymentId = $payload['id'] ?? 'pay_webhook';
        $orderId = $payload['order_id'] ?? 'order_webhook';
        $signature = 'mock_signature';

        $res = RazorpayGateway::processPayment($userId, $packageType, $amount, $paymentId, $orderId, $signature);
        echo json_encode($res);
        exit;
    }

    // Direct JS Checkout callback endpoint
    if (!Auth::check()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $user = Auth::user();
    $packageType = $input['package_type'] ?? 'voucher_300';
    $amount = (float)($input['amount'] ?? 300.00);
    $paymentId = $input['razorpay_payment_id'] ?? ('pay_' . uniqid());
    $orderId = $input['razorpay_order_id'] ?? ('order_' . uniqid());
    $signature = $input['razorpay_signature'] ?? 'mock_signature';

    $result = RazorpayGateway::processPayment($user['id'], $packageType, $amount, $paymentId, $orderId, $signature);
    echo json_encode($result);
    exit;
}
