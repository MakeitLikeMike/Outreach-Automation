<?php
/**
 * Application Configuration Helper
 * Provides portable configuration methods to avoid hardcoded paths
 */

class AppConfig {
    private static $config = null;
    
    /**
     * Get application configuration
     */
    public static function get($key = null, $default = null) {
        if (self::$config === null) {
            self::loadConfig();
        }
        
        if ($key === null) {
            return self::$config;
        }
        
        return self::$config[$key] ?? $default;
    }
    
    /**
     * Load configuration from environment and defaults
     */
    private static function loadConfig() {
        // Load environment variables
        self::loadEnvFile();
        
        // Detect base URL automatically
        $baseUrl = self::detectBaseUrl();
        
        self::$config = [
            // Database
            'db_host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost',
            'db_username' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root',
            'db_password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
            'db_database' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'outreach_automation',
            
            // Application URLs
            'app_url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: $baseUrl,
            'base_url' => $baseUrl,
            'gmail_redirect_uri' => $_ENV['GMAIL_REDIRECT_URI'] ?? getenv('GMAIL_REDIRECT_URI') ?: $baseUrl . '/gmail_callback.php',
            
            // API Keys
            'dataforseo_login' => $_ENV['DATAFORSEO_LOGIN'] ?? getenv('DATAFORSEO_LOGIN') ?: '',
            'dataforseo_password' => $_ENV['DATAFORSEO_PASSWORD'] ?? getenv('DATAFORSEO_PASSWORD') ?: '',
            'tomba_api_key' => $_ENV['TOMBA_API_KEY'] ?? getenv('TOMBA_API_KEY') ?: '',
            'chatgpt_api_key' => $_ENV['CHATGPT_API_KEY'] ?? getenv('CHATGPT_API_KEY') ?: '',
            'chatgpt_model' => $_ENV['CHATGPT_MODEL'] ?? getenv('CHATGPT_MODEL') ?: 'gpt-3.5-turbo',
            
            // Gmail OAuth
            'gmail_client_id' => $_ENV['GMAIL_CLIENT_ID'] ?? getenv('GMAIL_CLIENT_ID') ?: '',
            'gmail_client_secret' => $_ENV['GMAIL_CLIENT_SECRET'] ?? getenv('GMAIL_CLIENT_SECRET') ?: '',
            
            // Security
            'csrf_secret_key' => $_ENV['CSRF_SECRET_KEY'] ?? getenv('CSRF_SECRET_KEY') ?: 'change-this-secret-key',
            'session_secret' => $_ENV['SESSION_SECRET'] ?? getenv('SESSION_SECRET') ?: 'change-this-session-secret',
            
            // Application Settings
            'app_env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production',
            'app_debug' => filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
            'app_timezone' => $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'UTC',
        ];
    }
    
    /**
     * Auto-detect the base URL of the application
     */
    private static function detectBaseUrl() {
        // Check if we have APP_URL in environment
        $envUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (!empty($envUrl)) {
            return rtrim($envUrl, '/');
        }
        
        // Auto-detect from HTTP request
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['PHP_SELF'] ?? '/');
        
        // Clean up path
        $path = str_replace('\\', '/', $path); // Convert Windows paths
        $path = rtrim($path, '/');
        
        return $protocol . '://' . $host . $path;
    }
    
    /**
     * Load .env file if it exists
     */
    private static function loadEnvFile() {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
    }
    
    /**
     * Get full URL for a path
     */
    public static function url($path = '') {
        $baseUrl = self::get('base_url');
        $path = ltrim($path, '/');
        return $baseUrl . ($path ? '/' . $path : '');
    }
    
    /**
     * Check if we're in debug mode
     */
    public static function isDebug() {
        return self::get('app_debug', false);
    }
    
    /**
     * Get database configuration array
     */
    public static function getDatabaseConfig() {
        return [
            'host' => self::get('db_host'),
            'username' => self::get('db_username'),
            'password' => self::get('db_password'),
            'database' => self::get('db_database')
        ];
    }
}

// Backward compatibility helper functions
function getBaseUrl() {
    return AppConfig::get('base_url');
}

function getAppUrl($path = '') {
    return AppConfig::url($path);
}
?>