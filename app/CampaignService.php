<?php
class CampaignService {
    private $campaign;
    private $targetDomain;
    private $api;
    private $emailTemplate;
    private $db;

    public function __construct() {
        $this->campaign = new Campaign();
        $this->targetDomain = new TargetDomain();
        $this->api = new ApiIntegration();
        $this->emailTemplate = new EmailTemplate();
        $this->db = getDatabase();
    }

    /**
     * Get paginated campaigns list
     */
    public function listPaged(int $page = 1, int $perPage = 25): array {
        $offset = max(0, ($page - 1) * $perPage);
        
        // Use the existing Campaign class method but add pagination
        $allCampaigns = $this->campaign->getAll();
        $total = count($allCampaigns);
        
        // Simple array slice for pagination
        $campaigns = array_slice($allCampaigns, $offset, $perPage);
        
        return [
            'campaigns' => $campaigns,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => max(1, ceil($total / $perPage))
        ];
    }

    /**
     * Get all email templates
     */
    public function getEmailTemplates(): array {
        return $this->emailTemplate->getAll();
    }

    /**
     * Create new campaign with background processing
     */
    public function createCampaign(array $data): array {
        try {
            $name = $data['name'] ?? '';
            $competitor_urls = $data['competitor_urls'] ?? '';
            $owner_email = $data['owner_email'] ?? '';
            $automation_sender_email = $data['automation_sender_email'] ?? 'teamoutreach41@gmail.com';
            $automation_mode = $data['automation_mode'] ?? 'template';
            $email_template_id = $data['email_template_id'] ?? null;
            $auto_send = isset($data['auto_send']) ? 1 : 0;
            
            // Process automation settings
            $automation_settings = [
                'auto_domain_analysis' => isset($data['auto_domain_analysis']) ? 1 : 0,
                'auto_email_search' => isset($data['auto_email_search']) ? 1 : 0,
                'auto_reply_monitoring' => isset($data['auto_reply_monitoring']) ? 1 : 0,
                'auto_lead_forwarding' => isset($data['auto_lead_forwarding']) ? 1 : 0
            ];
            
            // Process smart scheduling settings
            $schedule_settings = [
                'enable_smart_scheduling' => isset($data['enable_smart_scheduling']) ? 1 : 0,
                'schedule_mode' => $data['schedule_mode'] ?? 'optimized',
                'batch_size' => (int)($data['batch_size'] ?? 50),
                'delay_between_batches' => (int)($data['delay_between_batches'] ?? 300),
                'max_sends_per_day' => (int)($data['max_sends_per_day'] ?? 200),
                'respect_business_hours' => isset($data['respect_business_hours']) ? 1 : 0,
                'avoid_holidays' => isset($data['avoid_holidays']) ? 1 : 0,
                'timezone_optimization' => isset($data['timezone_optimization']) ? 1 : 0
            ];

            // Create campaign with error handling for database compatibility
            try {
                $id = $this->campaign->create(
                    $name, 
                    $competitor_urls, 
                    $owner_email, 
                    $email_template_id, 
                    $automation_mode, 
                    $auto_send, 
                    $automation_settings,
                    $schedule_settings,
                    $automation_sender_email
                );
            } catch (Exception $e) {
                // Fallback for older database schema
                if (strpos($e->getMessage(), 'Unknown column') !== false) {
                    $id = $this->campaign->createBasic($name, $competitor_urls);
                } else {
                    throw $e;
                }
            }

            // Queue background processing if competitor URLs provided
            if (!empty($competitor_urls)) {
                try {
                    // Define APP_ROOT if not defined
                    if (!defined('APP_ROOT')) {
                        define('APP_ROOT', dirname(__DIR__));
                    }
                    
                    // Check if BackgroundJobProcessor class exists, if not require it
                    if (!class_exists('BackgroundJobProcessor')) {
                        $processorPath = APP_ROOT . '/classes/BackgroundJobProcessor.php';
                        if (file_exists($processorPath)) {
                            require_once $processorPath;
                        } else {
                            throw new Exception("BackgroundJobProcessor class file not found at: $processorPath");
                        }
                    }
                    
                    $processor = new BackgroundJobProcessor();
                    
                    // Queue the job
                    $processor->queueJob('fetch_backlinks', $id, null, [
                        'competitor_urls' => $competitor_urls
                    ], 10);
                    
                    // Immediately trigger processing if possible
                    $this->triggerImmediateProcessing($processor);
                    
                    $message = "Campaign created successfully! 🚀 Automated processing has started. You'll receive qualified leads directly in your inbox as they're found.";
                    
                } catch (Exception $e) {
                    error_log("Background processing queue failed: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    
                    // Provide more specific error message
                    $errorDetails = $this->getBackgroundProcessingErrorDetails($e);
                    $message = "Campaign created successfully! Background processing setup encountered an issue: {$errorDetails}. You can start processing manually from the campaign view.";
                }
            } else {
                $message = "Campaign created successfully! Add competitor URLs to begin automated outreach.";
            }

            return ['success' => true, 'message' => $message, 'id' => $id];

        } catch (Exception $e) {
            error_log("Campaign creation failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update existing campaign
     */
    public function updateCampaign(int $campaignId, array $data): array {
        try {
            $name = $data['name'] ?? '';
            $competitor_urls = $data['competitor_urls'] ?? '';
            $owner_email = $data['owner_email'] ?? '';
            $status = $data['status'] ?? 'active';
            $email_template_id = $data['email_template_id'] ?? null;

            $this->campaign->update($campaignId, $name, $competitor_urls, $owner_email, $status, $email_template_id);
            
            return ['success' => true, 'message' => 'Campaign updated successfully!'];
        } catch (Exception $e) {
            error_log("Campaign update failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete campaign
     */
    public function deleteCampaign(int $campaignId): array {
        try {
            $this->campaign->delete($campaignId);
            return ['success' => true, 'message' => 'Campaign deleted successfully!'];
        } catch (Exception $e) {
            error_log("Campaign deletion failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bulk delete campaigns
     */
    public function bulkDeleteCampaigns(array $campaignIds): array {
        if (empty($campaignIds)) {
            return ['success' => false, 'error' => 'No campaigns selected for deletion'];
        }

        $deletedCount = 0;
        $errors = [];

        foreach ($campaignIds as $id) {
            if (is_numeric($id)) {
                try {
                    $this->campaign->delete($id);
                    $deletedCount++;
                } catch (Exception $e) {
                    $errors[] = "Failed to delete campaign ID $id: " . $e->getMessage();
                }
            }
        }

        if ($deletedCount > 0) {
            $message = "$deletedCount campaign(s) deleted successfully!";
            if (!empty($errors)) {
                $message .= " However, some deletions failed: " . implode(", ", $errors);
            }
            return ['success' => true, 'message' => $message];
        } else {
            return ['success' => false, 'error' => "No campaigns were deleted. " . implode(", ", $errors)];
        }
    }

    /**
     * Get campaign by ID
     */
    public function getCampaignById(int $campaignId): ?array {
        return $this->campaign->getById($campaignId);
    }

    /**
     * Get campaign statistics
     */
    public function getCampaignStats(int $campaignId): array {
        return $this->campaign->getStats($campaignId);
    }

    /**
     * Get domains for campaign (including rejected domains to show full status)
     */
    public function getCampaignDomains(int $campaignId): array {
        return $this->targetDomain->getByCampaign($campaignId, null, false); // Don't exclude rejected domains
    }

    /**
     * Trigger immediate background processing for faster user experience
     */
    private function triggerImmediateProcessing($processor) {
        try {
            // Process one job immediately to kickstart the pipeline
            $processed = $processor->processSingleJob();
            if ($processed) {
                error_log("Successfully triggered immediate background processing");
            }
        } catch (Exception $e) {
            error_log("Immediate processing trigger failed: " . $e->getMessage());
            // Don't re-throw - this is just an optimization
        }
    }

    /**
     * Get detailed error information for background processing failures
     */
    private function getBackgroundProcessingErrorDetails($exception) {
        $message = $exception->getMessage();
        
        // Check for common issues and provide helpful guidance
        if (strpos($message, 'background_jobs') !== false) {
            return "Database table 'background_jobs' missing. Please run database migration.";
        }
        
        if (strpos($message, 'BackgroundJobProcessor') !== false) {
            return "Background processor not available. Please check file permissions.";
        }
        
        if (strpos($message, 'Connection refused') !== false || strpos($message, 'database') !== false) {
            return "Database connection issue. Please check database configuration.";
        }
        
        // Return first 100 characters of error for debugging
        return substr($message, 0, 100) . (strlen($message) > 100 ? '...' : '');
    }
}