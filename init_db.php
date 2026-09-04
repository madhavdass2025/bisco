<?php
// init_db.php

require_once __DIR__ . '/config/db.php';

function initDatabase() {
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $db = Database::getConnection();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sqliteSql = preg_replace('/CREATE DATABASE.*?;/i', '', $schema);
        $sqliteSql = preg_replace('/USE `.*?`;/i', '', $sqliteSql);
        $sqliteSql = preg_replace('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i', '', $sqliteSql);
        $sqliteSql = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSql);
        $sqliteSql = preg_replace('/TINYINT/i', 'INTEGER', $sqliteSql);
        $sqliteSql = preg_replace('/DATETIME/i', 'TEXT', $sqliteSql);
        $sqliteSql = preg_replace('/ENUM\(.*?\)/i', 'VARCHAR(50)', $sqliteSql);
        $sqliteSql = preg_replace('/INSERT INTO `affiliate_payout_rules`[\s\S]*?ON DUPLICATE KEY UPDATE.*?;/s', 'INSERT OR REPLACE INTO `affiliate_payout_rules` (`level`, `classic_points`, `basic_points`, `team_points`) VALUES (1, 500, 250, 25), (2, 200, 100, 10), (3, 100, 50, 8), (4, 50, 25, 6), (5, 20, 10, 4), (6, 20, 10, 2), (7, 20, 10, 1), (8, 10, 10, 1), (9, 10, 10, 1), (10, 10, 10, 1), (11, 10, 10, 1), (12, 10, 5, 1);', $sqliteSql);
        $sqliteSql = preg_replace('/INSERT INTO `rank_definitions`[\s\S]*?ON DUPLICATE KEY UPDATE.*?;/s', 'INSERT OR REPLACE INTO `rank_definitions` (`rank_id`, `rank_name`, `requirement_team_count`, `rank_incentive`, `monthly_incentive`, `monthly_duration_months`) VALUES (1, \'Promoter\', 5, 500.00, 0.00, 0), (2, \'Senior Promoter\', 5, 1000.00, 500.00, 10), (3, \'Team Leader\', 5, 5000.00, 1000.00, 10), (4, \'Team Manager\', 5, 10000.00, 2000.00, 10), (5, \'Area Manager\', 5, 25000.00, 4000.00, 10), (6, \'Zonal Manager\', 5, 50000.00, 8000.00, 10), (7, \'Regional Manager\', 5, 100000.00, 10000.00, 10), (8, \'State Head\', 5, 500000.00, 25000.00, 10), (9, \'National Head\', 5, 1000000.00, 50000.00, 10), (10, \'Global Head\', 5, 2500000.00, 100000.00, 10), (11, \'Ambassador\', 5, 5000000.00, 500000.00, 10), (12, \'Crown Ambassador\', 5, 10000000.00, 1000000.00, 10);', $sqliteSql);
        $sqliteSql = preg_replace('/INSERT INTO `users`[\s\S]*?ON DUPLICATE KEY UPDATE.*?;/s', 'INSERT OR REPLACE INTO `users` (`id`, `sponsor_id`, `full_name`, `phone`, `password`, `rank_level`, `wallet_balance`, `is_admin`) VALUES (1, NULL, \'System Super Admin\', \'9999999999\', \'$2y$10$Qwhe0kLjRG.wQSMoWt/NgONJzOWJr7dwkTBlDwrcEO5J1UVh/6jqi\', 12, 1000000.00, 1);', $sqliteSql);
        $sqliteSql = preg_replace('/FOR UPDATE/i', '', $sqliteSql);
        $sqliteSql = preg_replace('/COMMENT \'.*?\'/i', '', $sqliteSql);

        $db->exec($sqliteSql);
        echo "SQLite Database initialized and seeded successfully!\n";
    } else {
        $db->exec($schema);
        echo "MySQL Database initialized and seeded successfully!\n";
    }
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'] ?? '')) {
    initDatabase();
}
