<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'config/database.php';

require_once 'classes/Campaign.php';

$database = new Database();
$db = $database->getConnection();





require_once 'classes/TargetDomain.php';
require_once 'classes/ApiIntegration.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();
$api = new ApiIntegration();

// Check API configuration status
$apiStatus = $api->testDataForSEOConnection();
$tombaConfigured = false;
try {
    $api->getTombaUsage();
    $tombaConfigured = true;
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'credentials not configured') === false) {
        $tombaConfigured = true; // Other error, assume configured
    }
}

$action = $_GET['action'] ?? 'list';
$domainId = $_GET['id'] ?? null;
$campaignId = $_GET['campaign_id'] ?? null;
$message = '';
$error = '';

if ($_POST) {
    try {
        if ($action === 'analyze_domain') {
            $domains_input = $_POST['domains'];
            $campaign_id = $_POST['campaign_id'];
            
            try {
                $domains_list = processDomainsList($domains_input);
                $processed = 0;
                $errors = [];
                $debug_info = [];
                
                if (empty($domains_list)) {
                    $error = "No valid domains found in input. Please check domain format.";
                } else {
                    $debug_info[] = "Found " . count($domains_list) . " domains to process: " . implode(', ', $domains_list);
                    
                    foreach ($domains_list as $domain) {
                        try {
                            $debug_info[] = "Processing domain: $domain";
                            
                            // Check if domain already exists for this campaign
                            if ($targetDomain->domainExists($campaign_id, $domain)) {
                                $debug_info[] = "Skipped $domain - already exists in campaign";
                                continue;
                            }
                            
                            $debug_info[] = "Getting metrics for $domain";
                            
                            // Initialize default metrics structure
                            $metrics = [
                                'domain_rating' => 0,
                                'organic_traffic' => 0,
                                'organic_keywords' => 0,
                                'referring_domains' => 0,
                                'backlinks' => 0,
                                'contact_email' => null
                            ];
                            
                            // Try to get DataForSEO metrics
                            try {
                                $dataforseo_metrics = $api->getDomainMetrics($domain);
                                if (!empty($dataforseo_metrics)) {
                                    $metrics = array_merge($metrics, $dataforseo_metrics);
                                    $debug_info[] = "Got DataForSEO metrics: DR=" . ($metrics['domain_rating'] ?? 'N/A') . ", Traffic=" . ($metrics['organic_traffic'] ?? 'N/A');
                                } else {
                                    $debug_info[] = "DataForSEO metrics returned empty - using defaults";
                                }
                            } catch (Exception $e) {
                                $debug_info[] = "DataForSEO metrics failed: " . $e->getMessage() . " - using defaults";
                                // Check if it's a credentials issue
                                if (strpos($e->getMessage(), 'credentials not configured') !== false || 
                                    strpos($e->getMessage(), 'cURL Error') !== false) {
                                    $errors[] = "DataForSEO API not configured. Please set up credentials in Settings.";
                                }
                            }
                            
                            // Try to find email
                            try {
                                $email = $api->findEmail($domain);
                                $metrics['contact_email'] = $email;
                                $debug_info[] = "Email found: " . ($email ?: 'None');
                            } catch (Exception $e) {
                                $debug_info[] = "Email search failed: " . $e->getMessage();
                                if (strpos($e->getMessage(), 'Tomba API credentials not configured') !== false) {
                                    $errors[] = "Tomba API not configured. Please set up credentials in Settings.";
                                }
                                $metrics['contact_email'] = null;
                            }
                            
                            $quality_score = calculateQualityScore($metrics);
                            $metrics['quality_score'] = $quality_score;
                            $debug_info[] = "Quality score: $quality_score";
                            
                            // Check quality and quantity reviews
                            $passes_quality = passesQualityReview($metrics);
                            $passes_quantity = passesQuantityReview($metrics);
                            $debug_info[] = "Quality check: " . ($passes_quality ? 'PASS' : 'FAIL') . ", Quantity check: " . ($passes_quantity ? 'PASS' : 'FAIL');
                            
                            // Always create the domain record for review, regardless of quality
                            $id = $targetDomain->create($campaign_id, $domain, $metrics);
                            $processed++;
                            $debug_info[] = "Created domain record with ID: $id";
                            
                            // Only queue for outreach if it passes quality/quantity and has email
                            if ($passes_quality && $passes_quantity && $email && $quality_score >= 75) {
                                queueForOutreach($campaign_id, $id);
                                $debug_info[] = "Queued for outreach";
                                
                                // Trigger immediate automated outreach
                                triggerImmediateAutomatedOutreach($id, $campaign_id, $email);
                                $debug_info[] = "Triggered immediate automated outreach";
                            }
                            
                        } catch (Exception $e) {
                            $errors[] = "Error analyzing $domain: " . $e->getMessage();
                            $debug_info[] = "ERROR for $domain: " . $e->getMessage();
                        }
                    }
                }
                
                if ($processed > 0) {
                    $message = "Successfully processed $processed domain(s)!";
                    if (!empty($errors)) {
                        $message .= " Some domains had errors.";
                    }
                } else {
                    $error = "No domains were processed successfully.";
                    if (!empty($errors)) {
                        $error .= "<br><br>Errors:<br>" . implode('<br>', $errors);
                    }
                }
                
                // Add debug info in development
                if (isset($_GET['debug']) || empty($processed)) {
                    $error .= "<br><br>Debug Info:<br>" . implode('<br>', $debug_info);
                }
                
            } catch (Exception $e) {
                $error = "Error processing domains: " . $e->getMessage();
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$campaigns = $campaign->getAll();
$domains = [];
$selectedCampaign = null;

if ($campaignId) {
    $domains = $targetDomain->getByCampaign($campaignId);
    $selectedCampaign = $campaign->getById($campaignId);
} else {
    $domains = $targetDomain->getQualityFiltered();
}

$currentDomain = null;
if ($domainId && $action === 'view') {
    $currentDomain = $targetDomain->getById($domainId);
}

function processDomainsList($input) {
    // Handle multiple domains separated by comma, newline, or space
    $domains = preg_split('/[\s,\n\r]+/', trim($input));
    $cleaned_domains = [];
    $debug = [];
    
    foreach ($domains as $domain) {
        $original_domain = $domain;
        $domain = trim($domain);
        if (empty($domain)) continue;
        
        // Remove protocol if present
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        
        // Remove www. if present
        $domain = preg_replace('/^www\./', '', $domain);
        
        // Remove trailing slash and path
        $domain = preg_replace('/\/.*$/', '', $domain);
        $domain = rtrim($domain, '/');
        
        // More lenient domain validation
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*[a-zA-Z0-9]\.[a-zA-Z]{2,}$/', $domain) || 
            filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $cleaned_domains[] = strtolower($domain);
            $debug[] = "✓ '$original_domain' → '$domain'";
        } else {
            $debug[] = "✗ '$original_domain' → '$domain' (invalid format)";
        }
    }
    
    // Store debug info for troubleshooting
    if (isset($_GET['debug'])) {
        error_log("Domain processing debug: " . implode(', ', $debug));
    }
    
    return array_unique($cleaned_domains);
}

function passesQualityReview($metrics) {
    $quality_score = $metrics['quality_score'] ?? 0;
    $min_quality = 40; // Minimum quality score required
    
    return $quality_score >= $min_quality;
}

function passesQuantityReview($metrics) {
    // Basic quantity checks
    $domain_rating = $metrics['domain_rating'] ?? 0;
    $organic_traffic = $metrics['organic_traffic'] ?? 0;
    $referring_domains = $metrics['referring_domains'] ?? 0;
    
    // Must have at least one meaningful metric
    return ($domain_rating >= 10 || $organic_traffic >= 1000 || $referring_domains >= 10);
}

function queueForOutreach($campaign_id, $domain_id) {
    require_once 'classes/EmailQueue.php';
    $emailQueue = new EmailQueue();
    
    try {
        // Queue emails for this specific domain if it has contact email
        $emailQueue->queueCampaignEmails($campaign_id, null, [$domain_id]);
    } catch (Exception $e) {
        // Log error but don't fail the entire process
        error_log("Failed to queue outreach for domain $domain_id: " . $e->getMessage());
    }
}

function calculateQualityScore($metrics) {
    $score = 0;
    
    // Core metrics (20 points each)
    if (($metrics['domain_rating'] ?? 0) >= 30) $score += 20;
    if (($metrics['organic_traffic'] ?? 0) >= 5000) $score += 20;
    if (($metrics['referring_domains'] ?? 0) >= 100) $score += 20;
    if (($metrics['ranking_keywords'] ?? 0) >= 100) $score += 20;
    
    // Advanced quality metrics (5 points each)
    if (($metrics['status_pages_200'] ?? 0) >= 80) $score += 5;
    if (($metrics['homepage_traffic_percentage'] ?? 100) <= 70) $score += 5;
    if (($metrics['backlink_diversity_score'] ?? 0) >= 30) $score += 5;
    
    // Bonus points for exceptional metrics
    if (($metrics['domain_rating'] ?? 0) >= 70) $score += 5;
    
    return min($score, 100); // Cap at 100
}

function triggerImmediateAutomatedOutreach($domain_id, $campaign_id, $found_email) {
    try {
        require_once 'classes/AutomatedOutreach.php';
        $automatedOutreach = new AutomatedOutreach();
        
        // Trigger immediate outreach for this specific domain
        $result = $automatedOutreach->triggerImmediateOutreach($domain_id, $campaign_id, $found_email);
        
        if ($result['success']) {
            error_log("Successfully triggered immediate outreach for domain $domain_id with email $found_email");
        } else {
            error_log("Failed to trigger immediate outreach for domain $domain_id: " . $result['error']);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Exception in triggerImmediateAutomatedOutreach for domain $domain_id: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Analysis - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Enhanced Campaign Filter Styling */
        .filter-section-enhanced {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-header {
            display: flex;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
            min-width: 120px;
        }

        .filter-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
        }

        .campaign-select {
            min-width: 250px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }

        .campaign-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .campaign-select:hover {
            border-color: #4facfe;
        }

        .btn-outline {
            background: white;
            border: 1px solid #dee2e6;
            color: #6c757d;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .btn-outline:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
            color: #495057;
            text-decoration: none;
        }

        .btn-outline i {
            margin-right: 4px;
        }

        @media (max-width: 768px) {
            .filter-section-enhanced {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }
            
            .filter-header {
                min-width: auto;
            }
            
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .campaign-select {
                min-width: auto;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <h1>Domain Analysis</h1>
            </div>
        </header>

        <div style="padding: 2rem;">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($action === 'view' && $currentDomain): ?>
                <div class="content-grid" style="grid-template-columns: 2fr 1fr;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-globe"></i> Domain Details</h3>
                            <div class="actions">
                                <a href="https://<?php echo $currentDomain['domain']; ?>" target="_blank" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-external-link-alt"></i> Visit Site
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="detail-item">
                                <strong>Domain:</strong> 
                                <a href="https://<?php echo $currentDomain['domain']; ?>" target="_blank">
                                    <?php echo htmlspecialchars($currentDomain['domain']); ?>
                                </a>
                            </div>
                            <div class="detail-item">
                                <strong>Campaign:</strong> <?php echo htmlspecialchars($currentDomain['campaign_name']); ?>
                            </div>
                            <div class="detail-item">
                                <strong>Status:</strong> 
                                <span class="status status-<?php echo $currentDomain['status']; ?>" id="domain-status">
                                    <?php echo ucfirst($currentDomain['status']); ?>
                                </span>
                                <?php if (isset($currentDomain['email_search_status'])): ?>
                                    <br><small class="text-muted">Email Search: 
                                        <span id="email-search-status"><?php echo ucfirst($currentDomain['email_search_status']); ?></span>
                                        <?php if ($currentDomain['email_search_attempts'] > 0): ?>
                                            (<?php echo $currentDomain['email_search_attempts']; ?> attempts)
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="detail-item">
                                <strong>Quality Score:</strong> 
                                <span class="quality-score score-<?php echo $currentDomain['quality_score'] >= 75 ? 'high' : ($currentDomain['quality_score'] >= 50 ? 'medium' : 'low'); ?>">
                                    <?php echo $currentDomain['quality_score']; ?>/100
                                </span>
                            </div>
                            <div class="detail-item">
                                <strong>Contact Email:</strong> 
                                <?php if ($currentDomain['contact_email']): ?>
                                    <a href="mailto:<?php echo $currentDomain['contact_email']; ?>">
                                        <?php echo $currentDomain['contact_email']; ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not found</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-bar"></i> Metrics</h3>
                        </div>
                        <div class="card-body">
                            <div class="metric-item">
                                <div class="metric-label">Domain Rating</div>
                                <div class="metric-value"><?php echo $currentDomain['domain_rating']; ?></div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-label">Organic Traffic</div>
                                <div class="metric-value"><?php echo number_format($currentDomain['organic_traffic']); ?></div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-label">Referring Domains</div>
                                <div class="metric-value"><?php echo number_format($currentDomain['referring_domains']); ?></div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-label">Ranking Keywords</div>
                                <div class="metric-value"><?php echo number_format($currentDomain['ranking_keywords']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle"></i> Automated Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="status-info">
                            <div class="status-explanation">
                                <p><strong>Status Determination:</strong> This domain's status is automatically determined by the system based on:</p>
                                <ul>
                                    <li><strong>Quality Review:</strong> Domain rating, traffic metrics, referring domains</li>
                                    <li><strong>Quantity Review:</strong> Minimum thresholds for meaningful metrics</li>
                                    <li><strong>Email Availability:</strong> Contact email found for outreach</li>
                                </ul>
                                
                                <?php if ($currentDomain['status'] === 'approved'): ?>
                                    <div class="alert alert-success mt-3">
                                        <i class="fas fa-check-circle"></i> 
                                        <strong>Approved:</strong> This domain meets all quality criteria and is eligible for outreach.
                                    </div>
                                <?php elseif ($currentDomain['status'] === 'rejected'): ?>
                                    <div class="alert alert-error mt-3">
                                        <i class="fas fa-times-circle"></i> 
                                        <strong>Rejected:</strong> This domain doesn't meet the quality or quantity thresholds.
                                    </div>
                                <?php elseif ($currentDomain['status'] === 'contacted'): ?>
                                    <div class="alert alert-info mt-3">
                                        <i class="fas fa-paper-plane"></i> 
                                        <strong>Contacted:</strong> Outreach email has been sent to this domain.
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mt-3">
                                        <i class="fas fa-clock"></i> 
                                        <strong>Pending:</strong> Domain is still being processed and evaluated.
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="actions mt-4">
                                <?php if ($currentDomain['status'] === 'pending'): ?>
                                    <button onclick="approveDomain(<?php echo $currentDomain['id']; ?>)" class="btn btn-success" id="approve-btn">
                                        <i class="fas fa-check"></i> Approve Domain
                                    </button>
                                    <button onclick="rejectDomain(<?php echo $currentDomain['id']; ?>)" class="btn btn-danger" id="reject-btn">
                                        <i class="fas fa-times"></i> Reject Domain
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($currentDomain['status'] === 'approved' && (!isset($currentDomain['contact_email']) || empty($currentDomain['contact_email']))): ?>
                                    <button onclick="triggerEmailSearch(<?php echo $currentDomain['id']; ?>)" class="btn btn-primary" id="email-search-btn">
                                        <i class="fas fa-search"></i> Search Email
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (isset($currentDomain['email_search_status']) && $currentDomain['email_search_status'] === 'failed'): ?>
                                    <button onclick="retryEmailSearch(<?php echo $currentDomain['id']; ?>)" class="btn btn-warning" id="retry-email-btn">
                                        <i class="fas fa-redo"></i> Retry Email Search
                                    </button>
                                <?php endif; ?>
                                
                                <a href="domains.php<?php echo $campaignId ? '?campaign_id=' . $campaignId : ''; ?>" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to List
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action === 'analyze'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-search"></i> Add New Target Domains</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="domains.php?action=analyze_domain">
                            <div class="form-group">
                                <label for="campaign_id">Select Campaign *</label>
                                <select id="campaign_id" name="campaign_id" class="form-control" required>
                                    <option value="">Choose a campaign...</option>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>" <?php echo $campaignId == $camp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($camp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="domains">Target Domains to Add *</label>
                                <textarea id="domains" name="domains" class="form-control" rows="5" required 
                                          placeholder="Enter multiple domains (one per line or comma-separated):&#10;example.com&#10;https://another-site.com&#10;www.third-site.com"></textarea>
                                <small class="help-text">
                                    Enter domains with or without HTTP/HTTPS and www. Multiple formats supported:
                                    <br>• One per line: example.com
                                    <br>• Comma-separated: site1.com, site2.com  
                                    <br>• With protocol: https://example.com
                                    <br>• With www: www.example.com
                                </small>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Add Target Domains
                                </button>
                                <a href="domains.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                
                <!-- API Configuration Status -->
                <?php if (!$apiStatus['success'] || !$tombaConfigured): ?>
                <div class="card mb-3" style="border-left: 4px solid #ffc107; background-color: #fff3cd;">
                    <div class="card-body">
                        <div class="alert alert-warning" style="margin: 0; border: none; background: transparent;">
                            <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                            <strong style="color: #856404;">API Configuration Required for Full Functionality:</strong>
                            <ul style="margin: 0.5rem 0; padding-left: 2rem; color: #856404;">
                                <?php if (!$apiStatus['success']): ?>
                                    <li>DataForSEO API not configured - Domain metrics will be unavailable</li>
                                <?php endif; ?>
                                <?php if (!$tombaConfigured): ?>
                                    <li>Tomba API not configured - Email finding will be unavailable</li>
                                <?php endif; ?>
                            </ul>
                            <div style="margin-top: 1rem;">
                                <a href="settings.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-cog"></i> Configure APIs in Settings
                                </a>
                                <a href="domain_analysis_debug.php" class="btn btn-outline btn-sm ml-2">
                                    <i class="fas fa-bug"></i> Run Diagnostics
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Campaign Filter Card - Always Visible -->
                <?php if (!empty($campaigns)): ?>
                <div class="card mb-3" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 1px solid #dee2e6;">
                    <div class="card-body py-3">
                        <div class="filter-section-enhanced">
                            <div class="filter-header">
                                <i class="fas fa-filter" style="color: #4facfe; margin-right: 8px;"></i>
                                <strong style="color: #495057;">Campaign Filter:</strong>
                            </div>
                            <div class="filter-controls">
                                <select id="campaign-filter" class="form-control campaign-select" onchange="filterByCampaign(this.value)">
                                    <option value="">All Campaigns</option>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>" <?php echo $campaignId == $camp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($camp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($campaignId): ?>
                                    <a href="domains.php" class="btn btn-outline btn-sm ml-2">
                                        <i class="fas fa-times"></i> Clear Filter
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-globe"></i> 
                            <?php if ($selectedCampaign): ?>
                                Domains for "<?php echo htmlspecialchars($selectedCampaign['name']); ?>"
                            <?php else: ?>
                                Target Domains by Campaign
                            <?php endif ?>
                        </h3>
                        <div class="actions">
                            <a href="domains.php?action=analyze<?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Target Domains
                            </a>
                        </div>
                    </div>
                    <div class="card-body">

                        <?php if (!empty($domains)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Domain</th>
                                        <th>Campaign</th>
                                        <th>DR</th>
                                        <th>Traffic</th>
                                        <th>Domains</th>
                                        <th>Keywords</th>
                                        <th>Quality</th>
                                        <th>Status</th>
                                        <th>Contact</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($domains as $domain): ?>
                                        <tr class="clickable-row" onclick="window.location.href='domains.php?action=view&id=<?php echo $domain['id']; ?><?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>'">
                                            <td>
                                                <strong><?php echo htmlspecialchars($domain['domain']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if (isset($domain['campaign_name'])): ?>
                                                    <a href="campaigns.php?action=view&id=<?php echo $domain['campaign_id']; ?>">
                                                        <?php echo htmlspecialchars($domain['campaign_name']); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="domain-rating"><?php echo $domain['domain_rating']; ?></span>
                                            </td>
                                            <td><?php echo number_format($domain['organic_traffic']); ?></td>
                                            <td><?php echo number_format($domain['referring_domains']); ?></td>
                                            <td><?php echo number_format($domain['ranking_keywords']); ?></td>
                                            <td>
                                                <span class="quality-score score-<?php echo $domain['quality_score'] >= 75 ? 'high' : ($domain['quality_score'] >= 50 ? 'medium' : 'low'); ?>">
                                                    <?php echo $domain['quality_score']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status status-<?php echo $domain['status']; ?>">
                                                    <?php echo ucfirst($domain['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($domain['contact_email']): ?>
                                                    <i class="fas fa-check text-success"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times text-muted"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-globe"></i>
                                <p>
                                    <?php if ($campaignId): ?>
                                        No domains found for this campaign.
                                    <?php else: ?>
                                        No quality domains found. Start by creating a campaign and analyzing competitor backlinks.
                                    <?php endif; ?>
                                </p>
                                <a href="domains.php?action=analyze<?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Add Target Domains
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        function filterByCampaign(campaignId) {
            if (campaignId) {
                window.location.href = 'domains.php?campaign_id=' + campaignId;
            } else {
                window.location.href = 'domains.php';
            }
        }
        
        // Domain Operations Functions
        function approveDomain(domainId) {
            if (!confirm('Are you sure you want to approve this domain? This will trigger automatic email search.')) {
                return;
            }
            
            performDomainOperation('approve_domain', domainId, 'Approving domain and searching for email...');
        }
        
        function rejectDomain(domainId) {
            if (!confirm('Are you sure you want to reject this domain?')) {
                return;
            }
            
            performDomainOperation('reject_domain', domainId, 'Rejecting domain...');
        }
        
        function triggerEmailSearch(domainId) {
            performDomainOperation('trigger_email_search', domainId, 'Searching for email address...');
        }
        
        function retryEmailSearch(domainId) {
            if (!confirm('Retry email search for this domain?')) {
                return;
            }
            
            performDomainOperation('retry_email_search', domainId, 'Retrying email search...');
        }
        
        function performDomainOperation(action, domainId, loadingMessage) {
            // Show loading state
            showLoading(loadingMessage);
            
            // Disable action buttons
            disableActionButtons();
            
            // Make AJAX request
            fetch('api/domain_operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=${action}&domain_id=${domainId}`
            })
            .then(response => {
                // First get response as text for debugging
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (error) {
                        console.error('JSON parsing failed:', error);
                        console.error('Response text:', text);
                        console.error('Response status:', response.status);
                        console.error('Response headers:', [...response.headers.entries()]);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                });
            })
            .then(data => {
                hideLoading();
                enableActionButtons();
                
                if (data.success) {
                    showAlert('success', data.message);
                    
                    // Update UI based on action
                    updateUIAfterOperation(action, data);
                    
                    // Reload page after a delay to show updated status
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showAlert('error', data.message || 'Operation failed');
                }
            })
            .catch(error => {
                hideLoading();
                enableActionButtons();
                showAlert('error', 'Network error: ' + error.message);
                console.error('Error:', error);
            });
        }
        
        function updateUIAfterOperation(action, data) {
            const statusSpan = document.getElementById('domain-status');
            const emailSearchSpan = document.getElementById('email-search-status');
            
            switch(action) {
                case 'approve_domain':
                    if (statusSpan) {
                        statusSpan.textContent = 'Approved';
                        statusSpan.className = 'status status-approved';
                    }
                    if (emailSearchSpan && data.data && data.data.email_search_triggered) {
                        emailSearchSpan.textContent = 'Searching';
                    }
                    break;
                    
                case 'reject_domain':
                    if (statusSpan) {
                        statusSpan.textContent = 'Rejected';
                        statusSpan.className = 'status status-rejected';
                    }
                    break;
                    
                case 'trigger_email_search':
                case 'retry_email_search':
                    if (emailSearchSpan) {
                        if (data.data && data.data.email) {
                            emailSearchSpan.textContent = 'Found';
                        } else {
                            emailSearchSpan.textContent = 'Searching';
                        }
                    }
                    break;
            }
        }
        
        function showLoading(message) {
            // Create or update loading overlay
            let overlay = document.getElementById('loading-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'loading-overlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.5);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    z-index: 9999;
                    color: white;
                    font-size: 18px;
                `;
                document.body.appendChild(overlay);
            }
            overlay.innerHTML = `<div><i class="fas fa-spinner fa-spin"></i> ${message}</div>`;
            overlay.style.display = 'flex';
        }
        
        function hideLoading() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }
        
        function disableActionButtons() {
            const buttons = document.querySelectorAll('#approve-btn, #reject-btn, #email-search-btn, #retry-email-btn');
            buttons.forEach(btn => btn.disabled = true);
        }
        
        function enableActionButtons() {
            const buttons = document.querySelectorAll('#approve-btn, #reject-btn, #email-search-btn, #retry-email-btn');
            buttons.forEach(btn => btn.disabled = false);
        }
        
        function showAlert(type, message) {
            // Create alert element
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                ${message}
            `;
            
            // Insert at top of main content
            const mainContent = document.querySelector('.main-content');
            const firstChild = mainContent.querySelector('header').nextElementSibling;
            mainContent.insertBefore(alert, firstChild);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 5000);
        }
    </script>
</body>
</html>