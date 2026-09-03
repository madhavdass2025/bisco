-- POWERNET ASSOCIATE - BISCO
-- Database Schema for Production Environment

CREATE DATABASE IF NOT EXISTS `powernet_bisco` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `powernet_bisco`;

-- 1. users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sponsor_id` INT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(15) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `rank_level` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Member, 1: Promoter ... 12: Crown Ambassador',
  `wallet_balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sponsor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. affiliate_payout_rules (Master Reference Table)
CREATE TABLE IF NOT EXISTS `affiliate_payout_rules` (
  `level` TINYINT PRIMARY KEY COMMENT 'Levels 1 to 12',
  `classic_points` INT NOT NULL,
  `basic_points` INT NOT NULL,
  `team_points` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed affiliate_payout_rules
INSERT INTO `affiliate_payout_rules` (`level`, `classic_points`, `basic_points`, `team_points`) VALUES
(1, 500, 250, 25),
(2, 200, 100, 10),
(3, 100, 50, 8),
(4, 50, 25, 6),
(5, 20, 10, 4),
(6, 20, 10, 2),
(7, 20, 10, 1),
(8, 10, 10, 1),
(9, 10, 10, 1),
(10, 10, 10, 1),
(11, 10, 10, 1),
(12, 10, 5, 1)
ON DUPLICATE KEY UPDATE
  `classic_points` = VALUES(`classic_points`),
  `basic_points` = VALUES(`basic_points`),
  `team_points` = VALUES(`team_points`);

-- 3. rank_definitions (Master Reference Table)
CREATE TABLE IF NOT EXISTS `rank_definitions` (
  `rank_id` TINYINT PRIMARY KEY COMMENT '1 to 12',
  `rank_name` VARCHAR(50) NOT NULL,
  `requirement_team_count` INT NOT NULL DEFAULT 5 COMMENT 'Required count of downlines at rank_id - 1',
  `rank_incentive` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'One-time bonus',
  `monthly_incentive` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly payout',
  `monthly_duration_months` INT NOT NULL DEFAULT 10 COMMENT 'Default 10 months'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed rank_definitions
INSERT INTO `rank_definitions` (`rank_id`, `rank_name`, `requirement_team_count`, `rank_incentive`, `monthly_incentive`, `monthly_duration_months`) VALUES
(1, 'Promoter', 5, 500.00, 0.00, 0),
(2, 'Senior Promoter', 5, 1000.00, 500.00, 10),
(3, 'Team Leader', 5, 5000.00, 1000.00, 10),
(4, 'Team Manager', 5, 10000.00, 2000.00, 10),
(5, 'Area Manager', 5, 25000.00, 4000.00, 10),
(6, 'Zonal Manager', 5, 50000.00, 8000.00, 10),
(7, 'Regional Manager', 5, 100000.00, 10000.00, 10),
(8, 'State Head', 5, 500000.00, 25000.00, 10),
(9, 'National Head', 5, 1000000.00, 50000.00, 10),
(10, 'Global Head', 5, 2500000.00, 100000.00, 10),
(11, 'Ambassador', 5, 5000000.00, 500000.00, 10),
(12, 'Crown Ambassador', 5, 10000000.00, 1000000.00, 10)
ON DUPLICATE KEY UPDATE
  `rank_name` = VALUES(`rank_name`),
  `requirement_team_count` = VALUES(`requirement_team_count`),
  `rank_incentive` = VALUES(`rank_incentive`),
  `monthly_incentive` = VALUES(`monthly_incentive`),
  `monthly_duration_months` = VALUES(`monthly_duration_months`);

-- 4. epins table
CREATE TABLE IF NOT EXISTS `epins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pin_code` VARCHAR(30) NOT NULL UNIQUE,
  `package_type` ENUM('smart_recharge', 'voucher_300', 'voucher_600', 'sip_300', 'sip_600') NOT NULL,
  `value_amount` DECIMAL(10, 2) NOT NULL,
  `created_by` ENUM('admin', 'user_wallet') NOT NULL,
  `creator_user_id` INT NULL,
  `used_by_user_id` INT NULL,
  `status` ENUM('unused', 'used', 'cancelled') NOT NULL DEFAULT 'unused',
  `used_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`used_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. subscriptions table
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `package_type` ENUM('smart_recharge', 'voucher_300', 'voucher_600', 'sip_300', 'sip_600') NOT NULL,
  `payment_method` ENUM('razorpay', 'epin', 'wallet') NOT NULL,
  `amount_paid` DECIMAL(10, 2) NOT NULL,
  `monthly_return_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `total_months` INT NOT NULL DEFAULT 1,
  `months_paid` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `start_date` DATE NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. affiliate_commissions table
CREATE TABLE IF NOT EXISTS `affiliate_commissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `beneficiary_id` INT NOT NULL,
  `source_user_id` INT NOT NULL,
  `level` TINYINT NOT NULL COMMENT '0 for Self, 1-12 for Sponsor levels',
  `points_earned` INT NOT NULL,
  `cash_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`beneficiary_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`source_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. rank_payout_logs table
CREATE TABLE IF NOT EXISTS `rank_payout_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `rank_id` TINYINT NOT NULL,
  `payout_type` ENUM('one_time_rank', 'monthly_incentive') NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `payout_date` DATE NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
