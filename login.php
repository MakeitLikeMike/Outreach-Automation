<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_authenticated']) && $_SESSION['user_authenticated'] === true) {
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';

$error = '';
$success = '';

// Check for logout or session expired messages
if (isset($_GET['logged_out'])) {
    $success = 'You have been successfully logged out.';
} elseif (isset($_GET['expired'])) {
    $error = 'Your session has expired. Please log in again.';
}

// Handle login form submission
if ($_POST && isset($_POST['username']) && isset($_POST['password'])) {
    require_once 'auth.php';
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        $auth = new AuthManager();
        $result = $auth->authenticate($username, $password);
        
        if ($result['success']) {
            // Successful login - redirect to appropriate page
            $redirectUrl = $auth->isAdmin() ? 'admin.php' : 'index.php';
            if (!headers_sent()) {
                header("Location: $redirectUrl");
                exit();
            } else {
                echo "<script>window.location.href='$redirectUrl';</script>";
                exit();
            }
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AutoOutreach</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 100%;
            max-width: 400px;
            padding: 0;
            overflow: hidden;
        }

        .login-header {
            text-align: center;
            padding: 3rem 2.5rem 2rem 2.5rem;
            background: white;
        }

        .logo {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 24px;
            color: white;
        }

        .login-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
        }

        .login-subtitle {
            color: #64748b;
            font-size: 1rem;
            font-weight: 400;
        }

        .login-form {
            padding: 0 2.5rem 3rem 2.5rem;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .alert-success {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
            background: #ffffff;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .login-button {
            width: 100%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 0.5rem;
        }

        .login-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
        }

        .login-button:active {
            transform: translateY(0);
        }


        .footer-note {
            text-align: center;
            margin-top: 2rem;
            color: #9ca3af;
            font-size: 0.75rem;
        }

        /* Loading state */
        .login-button.loading {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .login-button.loading:hover {
            transform: none;
            box-shadow: none;
        }

        /* Responsive design */
        @media (max-width: 480px) {
            body {
                padding: 1rem;
            }
            
            .login-header {
                padding: 2rem 1.5rem 1.5rem 1.5rem;
            }
            
            .login-form {
                padding: 0 1.5rem 2rem 1.5rem;
            }
            
            .login-title {
                font-size: 1.5rem;
            }
        }

        /* Animation for smooth entrance */
        .login-container {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-envelope"></i>
            </div>
            <h1 class="login-title">Welcome back</h1>
            <p class="login-subtitle">Sign in to your AutoOutreach account</p>
        </div>

        <form class="login-form" method="POST" action="">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label" for="username">
                    <i class="fas fa-user" style="margin-right: 0.5rem; color: #6b7280;"></i>
                    Username
                </label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-input" 
                    placeholder="Enter your username" 
                    required 
                    autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="password">
                    <i class="fas fa-lock" style="margin-right: 0.5rem; color: #6b7280;"></i>
                    Password
                </label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-input" 
                    placeholder="Enter your password" 
                    required 
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="login-button" id="loginButton">
                <span class="button-text">Sign In</span>
                <span class="loading-text" style="display: none;">
                    <i class="fas fa-spinner fa-spin"></i> Signing in...
                </span>
            </button>


            <div class="footer-note">
                Secure login powered by AutoOutreach
            </div>
        </form>
    </div>

    <script>
        // Add loading state to form submission
        document.querySelector('.login-form').addEventListener('submit', function() {
            const button = document.getElementById('loginButton');
            const buttonText = button.querySelector('.button-text');
            const loadingText = button.querySelector('.loading-text');
            
            button.classList.add('loading');
            button.disabled = true;
            buttonText.style.display = 'none';
            loadingText.style.display = 'inline';
        });

        // Focus first input on page load
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            if (usernameInput && !usernameInput.value) {
                usernameInput.focus();
            }
        });

    </script>
</body>
</html>