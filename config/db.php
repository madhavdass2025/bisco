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
            $driver = getenv('DB_DRIVER') ?: 'sqlite';

            if ($driver === 'sqlite') {
                $dbPath = getenv('SQLITE_PATH') ?: __DIR__ . '/../database.sqlite';
                $isNew = !file_exists($dbPath);
                self::$instance = new PDO('sqlite:' . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$instance->exec("PRAGMA foreign_keys = ON;");

                if ($isNew) {
                    require_once __DIR__ . '/../init_db.php';
                    initDatabase();
                }
            } else {
                $host = getenv('DB_HOST') ?: '127.0.0.1';
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
                    die("<div style='padding:20px; font-family:sans-serif; color:#721c24; background-color:#f8d7da; border:1px solid #f5c6cb; border-radius:5px; margin:20px;'>".
                        "<h3>Database Connection Error</h3>".
                        "<p>Unable to connect to MySQL database: <strong>" . htmlspecialchars($e->getMessage()) . "</strong></p>".
                        "<p>Please verify your MySQL credentials in <code>config/db.php</code> or environment variables, or import <code>schema.sql</code> into phpMyAdmin on your hosting server.</p>".
                        "</div>");
                }
            }
        }
        return self::$instance;
    }

    public static function setConnection(PDO $pdo): void {
        self::$instance = $pdo;
    }
}
