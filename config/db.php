<?php
// config/db.php

// Display errors for debugging on live/shared host servers
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $driver = getenv('DB_DRIVER') ?: 'mysql';

            if ($driver === 'mysql') {
                // Default MySQL Database Configuration (Update for your hosting server/cPanel)
                $host = getenv('DB_HOST') ?: 'localhost';
                $port = getenv('DB_PORT') ?: '3306';
                $dbname = getenv('DB_NAME') ?: 'powernet_bisco';
                $username = getenv('DB_USER') ?: 'root';
                $password = getenv('DB_PASS') ?: '';

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                try {
                    self::$instance = new PDO($dsn, $username, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]);
                } catch (PDOException $e) {
                    die("<div style='padding:25px; font-family:Arial, sans-serif; color:#721c24; background-color:#f8d7da; border:1px solid #f5c6cb; border-radius:8px; max-width:700px; margin:40px auto; shadow: 0 4px 10px rgba(0,0,0,0.1);'>".
                        "<h2 style='margin-top:0;'>MySQL Database Connection Error</h2>".
                        "<p>Unable to connect to your MySQL database: <strong>" . htmlspecialchars($e->getMessage()) . "</strong></p>".
                        "<hr style='border:0; border-top:1px solid #f5c6cb; margin:15px 0;'>".
                        "<h4>How to fix on cPanel / Live Server:</h4>".
                        "<ol style='line-height:1.6;'>".
                        "<li>Open <code>config/db.php</code> in File Manager and enter your MySQL <b>Database Name</b>, <b>Username</b>, and <b>Password</b>.</li>".
                        "<li>Go to <b>cPanel -> phpMyAdmin</b>, select database <code>" . htmlspecialchars($dbname) . "</code>, and import <code>schema.sql</code>.</li>".
                        "</ol>".
                        "</div>");
                }
            } else {
                // SQLite fallback mode for CLI testing
                $dbPath = getenv('SQLITE_PATH') ?: __DIR__ . '/../database.sqlite';
                self::$instance = new PDO('sqlite:' . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$instance->exec("PRAGMA foreign_keys = ON;");

                $stmtCheck = self::$instance->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
                if (!$stmtCheck->fetch()) {
                    require_once __DIR__ . '/../init_db.php';
                    initDatabase();
                }
            }
        }
        return self::$instance;
    }

    public static function setConnection(PDO $pdo): void {
        self::$instance = $pdo;
    }
}
