<?php
// Load configuration loader for hosting compatibility
require_once __DIR__ . '/config_loader.php';

class Database {
    private $host;
    private $username;
    private $password;
    private $database;
    private $connection;
    
    public function __construct() {
        $this->loadConfig();
        $this->connect();
    }
    
    private function loadConfig() {
        // Use hosting-compatible configuration loader
        $config = ConfigLoader::getDatabaseConfig();
        $this->host = $config['host'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->database = $config['database'];
    }
    
    private function connect() {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            // Set timezone to match PHP timezone
            $phpTimezone = date_default_timezone_get();
            $mysqlTimezone = $phpTimezone === 'Europe/Berlin' ? '+01:00' : '+00:00';
            $this->connection->exec("SET time_zone = '$mysqlTimezone'");
            
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            // Convert array parameters to strings to avoid array to string conversion warnings
            $cleanParams = [];
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $cleanParams[$key] = json_encode($value);
                } else {
                    // Ensure string values are preserved as-is
                    $cleanParams[$key] = $value;
                }
            }
            $stmt->execute($cleanParams);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function execute($sql, $params = []) {
        return $this->query($sql, $params)->rowCount();
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    // Test connection for hosting setup
    public function testConnection() {
        try {
            $result = $this->fetchOne("SELECT 1 as test");
            return $result['test'] === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>