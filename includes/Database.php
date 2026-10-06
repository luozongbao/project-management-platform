<?php
/**
 * Database Connection and Utility Functions
 */

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            // Set timezone to UTC for consistent datetime storage
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage());
            // Surface the underlying PDO message so schema-drift bugs
            // (Unknown column …, Unknown table …) are visible to callers
            // without requiring a log tail. The wrapper Exception still
            // starts with the generic prefix that older callers (and
            // test suites) match on.
            throw new Exception("Database query failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Database execute failed: " . $e->getMessage());
            // See query() above — surface the underlying PDO message so
            // the next "Database execute failed: Unknown column 'X' in '…'"
            // surfaces immediately.
            throw new Exception("Database execute failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}
?>