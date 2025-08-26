<?php
/**
 * Connection Pool Manager for GMass + IMAP System
 * Manages database and IMAP connection pools for performance optimization
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/IMAPMonitor.php';

class ConnectionPool {
    private $dbPool = [];
    private $imapPool = [];
    private $maxDbConnections = 10;
    private $maxImapConnections = 3;
    private $connectionTimeout = 300; // 5 minutes
    private $lastCleanup = 0;
    private $logFile;
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->logFile = __DIR__ . '/../logs/connection_pool.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->log("Connection pool initialized", 'INFO');
        
        // Register shutdown function to cleanup connections
        register_shutdown_function([$this, 'cleanup']);
    }
    
    /**
     * Get database connection from pool
     */
    public function getDbConnection($identifier = 'default') {
        $this->cleanupExpiredConnections();
        
        // Check if we have a valid connection in the pool
        if (isset($this->dbPool[$identifier])) {
            $pooledConnection = $this->dbPool[$identifier];
            
            // Check if connection is still valid and not expired
            if ($this->isDbConnectionValid($pooledConnection) && 
                (time() - $pooledConnection['created']) < $this->connectionTimeout) {
                
                $pooledConnection['last_used'] = time();
                $this->log("Reusing database connection: {$identifier}", 'DEBUG');
                return $pooledConnection['connection'];
            } else {
                $this->closeDbConnection($identifier);
            }
        }
        
        // Create new connection if pool not full
        if (count($this->dbPool) < $this->maxDbConnections) {
            try {
                $connection = new Database();
                
                $this->dbPool[$identifier] = [
                    'connection' => $connection,
                    'created' => time(),
                    'last_used' => time(),
                    'identifier' => $identifier
                ];
                
                $this->log("Created new database connection: {$identifier}", 'INFO');
                return $connection;
                
            } catch (Exception $e) {
                $this->log("Failed to create database connection: " . $e->getMessage(), 'ERROR');
                throw $e;
            }
        }
        
        // Pool is full, find least recently used connection to replace
        $lru = $this->findLeastRecentlyUsedDb();
        if ($lru) {
            $this->closeDbConnection($lru);
            return $this->getDbConnection($identifier);
        }
        
        throw new Exception("Unable to obtain database connection from pool");
    }
    
    /**
     * Get IMAP connection from pool
     */
    public function getImapConnection($identifier = 'default') {
        $this->cleanupExpiredConnections();
        
        // Check if we have a valid connection in the pool
        if (isset($this->imapPool[$identifier])) {
            $pooledConnection = $this->imapPool[$identifier];
            
            // Check if connection is still valid and not expired
            if ($this->isImapConnectionValid($pooledConnection) && 
                (time() - $pooledConnection['created']) < $this->connectionTimeout) {
                
                $pooledConnection['last_used'] = time();
                $this->log("Reusing IMAP connection: {$identifier}", 'DEBUG');
                return $pooledConnection['connection'];
            } else {
                $this->closeImapConnection($identifier);
            }
        }
        
        // Create new connection if pool not full
        if (count($this->imapPool) < $this->maxImapConnections) {
            try {
                $connection = new IMAPMonitor();
                $connection->connect();
                
                $this->imapPool[$identifier] = [
                    'connection' => $connection,
                    'created' => time(),
                    'last_used' => time(),
                    'identifier' => $identifier
                ];
                
                $this->log("Created new IMAP connection: {$identifier}", 'INFO');
                return $connection;
                
            } catch (Exception $e) {
                $this->log("Failed to create IMAP connection: " . $e->getMessage(), 'ERROR');
                throw $e;
            }
        }
        
        // Pool is full, find least recently used connection to replace
        $lru = $this->findLeastRecentlyUsedImap();
        if ($lru) {
            $this->closeImapConnection($lru);
            return $this->getImapConnection($identifier);
        }
        
        throw new Exception("Unable to obtain IMAP connection from pool");
    }
    
    /**
     * Return connection to pool (mark as available)
     */
    public function returnConnection($identifier, $type = 'db') {
        if ($type === 'db' && isset($this->dbPool[$identifier])) {
            $this->dbPool[$identifier]['last_used'] = time();
            $this->log("Returned database connection to pool: {$identifier}", 'DEBUG');
        } elseif ($type === 'imap' && isset($this->imapPool[$identifier])) {
            $this->imapPool[$identifier]['last_used'] = time();
            $this->log("Returned IMAP connection to pool: {$identifier}", 'DEBUG');
        }
    }
    
    /**
     * Force close specific connection
     */
    public function closeConnection($identifier, $type = 'db') {
        if ($type === 'db') {
            $this->closeDbConnection($identifier);
        } else {
            $this->closeImapConnection($identifier);
        }
    }
    
    /**
     * Get pool statistics
     */
    public function getPoolStats() {
        return [
            'db_pool' => [
                'active_connections' => count($this->dbPool),
                'max_connections' => $this->maxDbConnections,
                'connections' => array_map(function($conn) {
                    return [
                        'identifier' => $conn['identifier'],
                        'created' => date('Y-m-d H:i:s', $conn['created']),
                        'last_used' => date('Y-m-d H:i:s', $conn['last_used']),
                        'age_seconds' => time() - $conn['created']
                    ];
                }, $this->dbPool)
            ],
            'imap_pool' => [
                'active_connections' => count($this->imapPool),
                'max_connections' => $this->maxImapConnections,
                'connections' => array_map(function($conn) {
                    return [
                        'identifier' => $conn['identifier'],
                        'created' => date('Y-m-d H:i:s', $conn['created']),
                        'last_used' => date('Y-m-d H:i:s', $conn['last_used']),
                        'age_seconds' => time() - $conn['created']
                    ];
                }, $this->imapPool)
            ],
            'settings' => [
                'connection_timeout' => $this->connectionTimeout,
                'max_db_connections' => $this->maxDbConnections,
                'max_imap_connections' => $this->maxImapConnections
            ]
        ];
    }
    
    /**
     * Test all pooled connections
     */
    public function testAllConnections() {
        $results = [
            'db_connections' => [],
            'imap_connections' => [],
            'healthy' => true
        ];
        
        // Test database connections
        foreach ($this->dbPool as $identifier => $pooledConn) {
            $isValid = $this->isDbConnectionValid($pooledConn);
            $results['db_connections'][$identifier] = [
                'valid' => $isValid,
                'age_seconds' => time() - $pooledConn['created'],
                'last_used_seconds_ago' => time() - $pooledConn['last_used']
            ];
            
            if (!$isValid) {
                $results['healthy'] = false;
            }
        }
        
        // Test IMAP connections  
        foreach ($this->imapPool as $identifier => $pooledConn) {
            $isValid = $this->isImapConnectionValid($pooledConn);
            $results['imap_connections'][$identifier] = [
                'valid' => $isValid,
                'age_seconds' => time() - $pooledConn['created'],
                'last_used_seconds_ago' => time() - $pooledConn['last_used']
            ];
            
            if (!$isValid) {
                $results['healthy'] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * Cleanup expired connections
     */
    private function cleanupExpiredConnections() {
        $now = time();
        
        // Only cleanup every 60 seconds to avoid overhead
        if ($now - $this->lastCleanup < 60) {
            return;
        }
        
        $this->lastCleanup = $now;
        $cleaned = 0;
        
        // Cleanup expired database connections
        foreach ($this->dbPool as $identifier => $pooledConn) {
            if (($now - $pooledConn['created']) > $this->connectionTimeout ||
                !$this->isDbConnectionValid($pooledConn)) {
                $this->closeDbConnection($identifier);
                $cleaned++;
            }
        }
        
        // Cleanup expired IMAP connections
        foreach ($this->imapPool as $identifier => $pooledConn) {
            if (($now - $pooledConn['created']) > $this->connectionTimeout ||
                !$this->isImapConnectionValid($pooledConn)) {
                $this->closeImapConnection($identifier);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->log("Cleaned up {$cleaned} expired connections", 'INFO');
        }
    }
    
    /**
     * Check if database connection is still valid
     */
    private function isDbConnectionValid($pooledConnection) {
        try {
            $db = $pooledConnection['connection'];
            return $db->testConnection();
        } catch (Exception $e) {
            $this->log("Database connection validation failed: " . $e->getMessage(), 'WARNING');
            return false;
        }
    }
    
    /**
     * Check if IMAP connection is still valid
     */
    private function isImapConnectionValid($pooledConnection) {
        try {
            $imap = $pooledConnection['connection'];
            return $imap->testConnection();
        } catch (Exception $e) {
            $this->log("IMAP connection validation failed: " . $e->getMessage(), 'WARNING');
            return false;
        }
    }
    
    /**
     * Find least recently used database connection
     */
    private function findLeastRecentlyUsedDb() {
        $lru = null;
        $oldestTime = time();
        
        foreach ($this->dbPool as $identifier => $pooledConn) {
            if ($pooledConn['last_used'] < $oldestTime) {
                $oldestTime = $pooledConn['last_used'];
                $lru = $identifier;
            }
        }
        
        return $lru;
    }
    
    /**
     * Find least recently used IMAP connection
     */
    private function findLeastRecentlyUsedImap() {
        $lru = null;
        $oldestTime = time();
        
        foreach ($this->imapPool as $identifier => $pooledConn) {
            if ($pooledConn['last_used'] < $oldestTime) {
                $oldestTime = $pooledConn['last_used'];
                $lru = $identifier;
            }
        }
        
        return $lru;
    }
    
    /**
     * Close specific database connection
     */
    private function closeDbConnection($identifier) {
        if (isset($this->dbPool[$identifier])) {
            unset($this->dbPool[$identifier]);
            $this->log("Closed database connection: {$identifier}", 'INFO');
        }
    }
    
    /**
     * Close specific IMAP connection
     */
    private function closeImapConnection($identifier) {
        if (isset($this->imapPool[$identifier])) {
            try {
                // Try to properly close IMAP connection
                $pooledConn = $this->imapPool[$identifier];
                // The IMAPMonitor class should have a close method
                if (method_exists($pooledConn['connection'], 'disconnect')) {
                    $pooledConn['connection']->disconnect();
                }
            } catch (Exception $e) {
                $this->log("Error closing IMAP connection: " . $e->getMessage(), 'WARNING');
            }
            
            unset($this->imapPool[$identifier]);
            $this->log("Closed IMAP connection: {$identifier}", 'INFO');
        }
    }
    
    /**
     * Close all connections and cleanup
     */
    public function cleanup() {
        $totalClosed = 0;
        
        // Close all database connections
        foreach (array_keys($this->dbPool) as $identifier) {
            $this->closeDbConnection($identifier);
            $totalClosed++;
        }
        
        // Close all IMAP connections
        foreach (array_keys($this->imapPool) as $identifier) {
            $this->closeImapConnection($identifier);
            $totalClosed++;
        }
        
        if ($totalClosed > 0) {
            $this->log("Connection pool cleanup completed. Closed {$totalClosed} connections.", 'INFO');
        }
    }
    
    /**
     * Log pool events
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Configure pool settings
     */
    public function configure($settings = []) {
        if (isset($settings['max_db_connections'])) {
            $this->maxDbConnections = max(1, min(50, (int)$settings['max_db_connections']));
        }
        
        if (isset($settings['max_imap_connections'])) {
            $this->maxImapConnections = max(1, min(10, (int)$settings['max_imap_connections']));
        }
        
        if (isset($settings['connection_timeout'])) {
            $this->connectionTimeout = max(60, min(3600, (int)$settings['connection_timeout']));
        }
        
        $this->log("Pool configuration updated: DB={$this->maxDbConnections}, IMAP={$this->maxImapConnections}, Timeout={$this->connectionTimeout}s", 'INFO');
    }
}
?>