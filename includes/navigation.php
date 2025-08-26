<?php
// Get current page name for active navigation highlighting
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Include timezone manager and auth
require_once __DIR__ . '/../classes/TimezoneManager.php';
require_once __DIR__ . '/../auth.php';

// Get current user info
$currentUser = $auth->getCurrentUser();

// Navigation items - EXACTLY the same across ALL pages
$nav_items = [
    'index' => ['icon' => 'fas fa-dashboard', 'label' => 'Dashboard', 'href' => 'index.php'],
    'campaigns' => ['icon' => 'fas fa-bullhorn', 'label' => 'Campaigns', 'href' => 'campaigns.php'],
    'quick_outreach' => ['icon' => 'fas fa-rocket', 'label' => 'Quick Outreach', 'href' => 'quick_outreach.php'],
    'domain_analyzer' => ['icon' => 'fas fa-search', 'label' => 'Domain Analyzer', 'href' => 'domain_analyzer.php'],
    'domains' => ['icon' => 'fas fa-globe', 'label' => 'Domain Analysis', 'href' => 'domains.php'],
    'templates' => ['icon' => 'fas fa-file-text', 'label' => 'Email Templates', 'href' => 'templates.php'],
    'analytics_dashboard' => ['icon' => 'fas fa-chart-bar', 'label' => 'Analytics', 'href' => 'analytics_dashboard.php'],
    'settings' => ['icon' => 'fas fa-cog', 'label' => 'Settings', 'href' => 'settings.php'],
    'admin' => ['icon' => 'fas fa-shield-alt', 'label' => 'Admin Panel', 'href' => 'admin.php']
];
?>
<nav class="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-envelope"></i> Auto Outreach</h2>
    </div>
    <ul class="nav-menu">
        <?php foreach ($nav_items as $key => $item): ?>
            <?php 
            // Hide admin panel for non-admin users
            if ($key === 'admin' && (!$currentUser || $currentUser['role'] !== 'admin')) {
                continue;
            }
            ?>
            <li>
                <a href="<?php echo $item['href']; ?>" 
                   class="<?php echo ($current_page === $key || ($key === 'index' && $current_page === 'index')) ? 'active' : ''; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    
    <!-- User section with improved layout -->
    <div class="user-section">
        <div class="user-profile">
            <div class="user-avatar">
                <i class="fas fa-user-circle"></i>
            </div>
            <div class="user-details">
                <div class="user-name">
                    <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                </div>
                <div class="user-role">
                    <?php echo htmlspecialchars($currentUser['role'] ?? 'User'); ?>
                </div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</nav>

<?php echo TimezoneManager::getTimezoneDetectionScript(); ?>