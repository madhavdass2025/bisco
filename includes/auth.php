<?php
// includes/auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

class Auth {
    public static function register(string $fullName, string $phone, string $password, ?int $sponsorId = null): array {
        $db = Database::getConnection();

        // Check if phone already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Phone number is already registered.'];
        }

        // Check if sponsor exists if provided
        if ($sponsorId !== null) {
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$sponsorId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => 'Sponsor ID does not exist.'];
            }
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (sponsor_id, full_name, phone, password, rank_level, wallet_balance) VALUES (?, ?, ?, ?, 0, 0.00)");
        $stmt->execute([$sponsorId, $fullName, $phone, $hashedPassword]);
        $userId = (int)$db->lastInsertId();

        if ($userId === 0) {
            $stmtGet = $db->prepare("SELECT id FROM users WHERE phone = ?");
            $stmtGet->execute([$phone]);
            $row = $stmtGet->fetch();
            $userId = (int)($row['id'] ?? 0);
        }

        return [
            'success' => true,
            'message' => 'Registration successful.',
            'user_id' => $userId
        ];
    }

    public static function login(string $phone, string $password): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Invalid phone number or password.'];
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['phone'] = $user['phone'];

        return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
    }

    public static function user(): ?array {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public static function check(): bool {
        return self::user() !== null;
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function logout(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
