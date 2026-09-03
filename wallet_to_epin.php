<?php
// wallet_to_epin.php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/sms_helper.php';

class WalletToEpinEngine {

    public static function getPackageValue(string $packageType): float {
        $packages = [
            'smart_recharge' => 100.00,
            'voucher_300'    => 300.00,
            'voucher_600'    => 600.00,
            'sip_300'        => 300.00,
            'sip_600'        => 600.00,
        ];

        return $packages[$packageType] ?? 0.00;
    }

    public static function generatePinCode(int $length = 16): string {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pin = 'PN'; // Prefix POWERNET
        for ($i = 0; $i < $length - 2; $i++) {
            $pin .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $pin;
    }

    /**
     * Deducts amount from wallet and creates a unique ePIN inside PDO transaction.
     */
    public static function createEpinFromWallet(int $userId, string $packageType): array {
        $db = Database::getConnection();

        $valueAmount = self::getPackageValue($packageType);
        if ($valueAmount <= 0) {
            return ['success' => false, 'message' => 'Invalid package type selected.'];
        }

        $db->beginTransaction();

        try {
            // Get user with row lock or transaction check
            $stmtUser = $db->prepare("SELECT id, full_name, phone, wallet_balance FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();

            if (!$user) {
                $db->rollBack();
                return ['success' => false, 'message' => 'User not found.'];
            }

            $currentBalance = (float)$user['wallet_balance'];
            if ($currentBalance < $valueAmount) {
                $db->rollBack();
                return ['success' => false, 'message' => "Insufficient wallet balance. Available: ₹" . number_format($currentBalance, 2) . ", Required: ₹" . number_format($valueAmount, 2)];
            }

            // Generate unique pin code
            $pinCode = '';
            do {
                $candidate = self::generatePinCode(16);
                $stmtCheck = $db->prepare("SELECT id FROM epins WHERE pin_code = ?");
                $stmtCheck->execute([$candidate]);
                if (!$stmtCheck->fetch()) {
                    $pinCode = $candidate;
                }
            } while (empty($pinCode));

            // Deduct wallet balance
            $stmtDeduct = $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $stmtDeduct->execute([$valueAmount, $userId]);

            // Insert ePIN record
            $stmtInsert = $db->prepare("
                INSERT INTO epins (pin_code, package_type, value_amount, created_by, creator_user_id, status)
                VALUES (?, ?, ?, 'user_wallet', ?, 'unused')
            ");
            $stmtInsert->execute([$pinCode, $packageType, $valueAmount, $userId]);
            $epinId = (int)$db->lastInsertId();

            $db->commit();

            // Send SMS alert
            SMSHelper::sendSMS(
                $user['phone'],
                "Dear {$user['full_name']}, your ePIN {$pinCode} for package {$packageType} (Rs.{$valueAmount}) was generated successfully. Wallet Balance: Rs." . number_format($currentBalance - $valueAmount, 2)
            );

            return [
                'success' => true,
                'message' => 'ePIN generated successfully.',
                'epin_id' => $epinId,
                'pin_code' => $pinCode,
                'package_type' => $packageType,
                'value_amount' => $valueAmount,
                'remaining_balance' => $currentBalance - $valueAmount
            ];

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to generate ePIN: ' . $e->getMessage()];
        }
    }
}

// Request Handler for Web / API
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['PHP_SELF']) == 'wallet_to_epin.php') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/includes/auth.php';

    if (!Auth::check()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $packageType = $input['package_type'] ?? '';
    $user = Auth::user();

    $result = WalletToEpinEngine::createEpinFromWallet($user['id'], $packageType);
    echo json_encode($result);
    exit;
}
