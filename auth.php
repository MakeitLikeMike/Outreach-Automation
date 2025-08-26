<?php
require_once 'config/database.php';

class AuthManager {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function authenticate($username, $password) {
        try {
            // Get user with password hash
            $user = $this->db->fetchOne(
                'SELECT id, username, email, password_hash, role, full_name, status, last_login FROM users WHERE username = ? AND status = ?',
                [$username, 'active']
            );
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Update last login
                $this->db->execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
                
                // Create session
                $this->createSession($user);
                
                return [
                    'success' => true,
                    'user' => $user,
                    'redirect' => 'index.php'
                ];
            }
            
            return ['success' => false, 'message' => 'Invalid username or password'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Authentication error: ' . $e->getMessage()];
        }
    }
    
    public function createSession($user) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_authenticated'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['login_time'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
    }
    
    public function isAuthenticated() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['user_authenticated']) && $_SESSION['user_authenticated'] === true;
    }
    
    public function isAdmin() {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return $_SESSION['role'] === 'admin';
    }
    
    public function requireAuth($redirectUrl = 'login.php') {
        if (!$this->isAuthenticated()) {
            header("Location: $redirectUrl");
            exit();
        }
    }
    
    public function requireAdmin($redirectUrl = 'login.php') {
        if (!$this->isAdmin()) {
            header("Location: $redirectUrl");
            exit();
        }
    }
    
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name'],
            'email' => $_SESSION['email'],
            'login_time' => $_SESSION['login_time']
        ];
    }
    
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public function checkSessionTimeout($timeoutMinutes = 120) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        if (isset($_SESSION['login_time'])) {
            $sessionAge = time() - $_SESSION['login_time'];
            if ($sessionAge > ($timeoutMinutes * 60)) {
                $this->logout();
                return false;
            }
        }
        
        return true;
    }
}

// Initialize auth manager
$auth = new AuthManager();

// Session timeout check - only redirect if we were previously authenticated
if (isset($_SESSION['user_authenticated']) && !$auth->checkSessionTimeout()) {
    if (!headers_sent()) {
        header('Location: login.php?expired=1');
        exit();
    }
}

// Legacy SimpleAuth class for backward compatibility
class SimpleAuth {
    private static $authManager = null;
    
    private static function getAuthManager() {
        if (self::$authManager === null) {
            self::$authManager = new AuthManager();
        }
        return self::$authManager;
    }
    
    public static function login($username, $password) {
        $result = self::getAuthManager()->authenticate($username, $password);
        return $result['success'];
    }
    
    public static function logout() {
        self::getAuthManager()->logout();
    }
    
    public static function isLoggedIn() {
        return self::getAuthManager()->isAuthenticated();
    }
    
    public static function requireLogin() {
        self::getAuthManager()->requireAuth();
    }
    
    public static function getUsername() {
        $user = self::getAuthManager()->getCurrentUser();
        return $user ? $user['username'] : null;
    }
}
?>