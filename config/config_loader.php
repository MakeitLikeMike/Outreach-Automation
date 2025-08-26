<?php
/**
 * Configuration Loader for BlueHost Compatibility
 * 
 * This file automatically detects the hosting environment and loads
 * the appropriate configuration files
 */

class ConfigLoader {
    private static $loaded = false;
    
    public static function load() {
        if (self::$loaded) {
            return;
        }
        
        // Load environment variables
        self::loadEnvironment();
        
        // Set timezone
        $timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
        date_default_timezone_set($timezone);
        
        // Set error reporting based on environment
        if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
            error_reporting(0);
            ini_set('display_errors', 0);
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        
        // Set PHP configurations for hosting
        self::setPhpConfig();
        
        self::$loaded = true;
    }
    
    private static function loadEnvironment() {
        $envFile = __DIR__ . '/../.env';
        
        if (!file_exists($envFile)) {
            // Provide helpful error message
            $setupUrl = 'hosting_setup.php';
            die("
                <h2>Configuration Missing</h2>
                <p>The .env configuration file is missing.</p>
                <p><strong>For BlueHost deployment:</strong></p>
                <ol>
                    <li>Copy <code>.env.bluehost</code> to <code>.env</code></li>
                    <li>Update database credentials in .env file</li>
                    <li>Visit <a href='$setupUrl'>$setupUrl</a> to complete setup</li>
                </ol>
            ");
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
        
        // Validate required environment variables
        self::validateRequiredVars();
    }
    
    private static function validateRequiredVars() {
        $required = [
            'DB_HOST' => 'Database host',
            'DB_USERNAME' => 'Database username',
            'DB_DATABASE' => 'Database name'
        ];
        
        $missing = [];
        foreach ($required as $key => $description) {
            if (empty($_ENV[$key])) {
                $missing[] = "$key ($description)";
            }
        }
        
        if (!empty($missing)) {
            die("
                <h2>Configuration Incomplete</h2>
                <p>The following required environment variables are missing:</p>
                <ul><li>" . implode('</li><li>', $missing) . "</li></ul>
                <p>Please update your .env file with the correct values.</p>
            ");
        }
    }
    
    private static function setPhpConfig() {
        // Memory and execution limits for hosting
        $memoryLimit = $_ENV['PHP_MEMORY_LIMIT'] ?? '256M';
        $executionTime = $_ENV['PHP_EXECUTION_TIME'] ?? '300';
        
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', $memoryLimit);
            @ini_set('max_execution_time', $executionTime);
            @ini_set('session.gc_maxlifetime', $_ENV['SESSION_LIFETIME'] ?? '3600');
        }
        
        // Start session if not started (only if headers not sent)
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
    
    public static function getDatabaseConfig() {
        return [
            'host' => $_ENV['DB_HOST'],
            'username' => $_ENV['DB_USERNAME'],
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'database' => $_ENV['DB_DATABASE']
        ];
    }
    
    public static function getApiConfig() {
        return [
            'dataforseo' => [
                'login' => $_ENV['DATAFORSEO_LOGIN'] ?? '',
                'password' => $_ENV['DATAFORSEO_PASSWORD'] ?? ''
            ],
            'tomba' => [
                'api_key' => $_ENV['TOMBA_API_KEY'] ?? ''
            ],
            'openai' => [
                'api_key' => $_ENV['CHATGPT_API_KEY'] ?? '',
                'model' => $_ENV['CHATGPT_MODEL'] ?? 'gpt-3.5-turbo'
            ],
            'gmail' => [
                'client_id' => $_ENV['GMAIL_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GMAIL_CLIENT_SECRET'] ?? '',
                'redirect_uri' => $_ENV['GMAIL_REDIRECT_URI'] ?? ''
            ]
        ];
    }
    
    public static function getAppConfig() {
        return [
            'env' => $_ENV['APP_ENV'] ?? 'production',
            'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            'url' => $_ENV['APP_URL'] ?? '',
            'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC'
        ];
    }
    
    public static function isProduction() {
        return ($_ENV['APP_ENV'] ?? 'production') === 'production';
    }
    
    public static function isDebug() {
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }
}

// Auto-load configuration
ConfigLoader::load();
?>