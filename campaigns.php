<?php
require_once 'auth.php';
$auth->requireAuth();

// Router for campaigns - Memory optimized for shared hosting
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '30');

try {
    // Bootstrap application
    require_once __DIR__ . '/app/bootstrap.php';
    
    // Get action from URL
    $action = $_GET['action'] ?? 'index';
    
    // Route to appropriate handler based on action
    switch ($action) {
        case 'create':
        case 'new':
            // Show create form
            require __DIR__ . '/app/views/campaigns_create.php';
            break;

        case 'store':
            // Handle create form submission
            require __DIR__ . '/app/handlers/campaigns_store.php';
            break;

        case 'edit':
            // Show edit form
            require __DIR__ . '/app/views/campaigns_edit.php';
            break;

        case 'update':
            // Handle edit form submission
            require __DIR__ . '/app/handlers/campaigns_update.php';
            break;

        case 'delete':
            // Handle single delete
            require __DIR__ . '/app/handlers/campaigns_delete.php';
            break;

        case 'bulk_delete':
            // Handle bulk delete
            require __DIR__ . '/app/handlers/campaigns_bulk_delete.php';
            break;

        case 'view':
            // Show campaign details
            require __DIR__ . '/app/views/campaigns_view.php';
            break;

        case 'index':
        case 'list':
        default:
            // Show campaigns list (default)
            require __DIR__ . '/app/views/campaigns_list.php';
            break;
    }

} catch (Throwable $e) {
    // Log error and show user-friendly message
    error_log('[campaigns] ' . $e->getMessage());
    
    // Set appropriate HTTP status
    http_response_code(500);
    
    // For production, show generic error
    $errorMessage = 'An error occurred. Please try again later.';
    
    // For development/debugging, you can uncomment the line below
    // $errorMessage = $e->getMessage();
    
    // Show minimal error page
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Error - Outreach Automation</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
        <div style="padding: 2rem; text-align: center;">
            <h1>Oops!</h1>
            <p>' . htmlspecialchars($errorMessage) . '</p>
            <a href="campaigns.php" class="btn btn-primary">← Back to Campaigns</a>
        </div>
    </body>
    </html>';
}