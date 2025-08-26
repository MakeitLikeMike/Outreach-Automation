<?php

/**
 * EmailSearchQueue - Email Search Queue Management
 * 
 * Manages the email_search_queue table for background processing of email searches.
 * Handles queueing, prioritization, retry logic, and batch processing operations.
 * 
 * Features:
 * - Queue domains for background email search
 * - Prioritized processing (high, medium, low)
 * - Exponential backoff retry logic
 * - Batch processing for background jobs
 * - Status tracking and error handling
 * - Queue maintenance and cleanup
 */

class EmailSearchQueue {
    private $db;
    
    // Queue configuration
    const MAX_ATTEMPTS = 3;
    const RETRY_DELAYS = [60, 300, 900]; // 1min, 5min, 15min in seconds
    const DEFAULT_BATCH_SIZE = 10;
    const STUCK_JOB_TIMEOUT_MINUTES = 30;
    
    // Queue statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    
    // Priority levels
    const PRIORITY_HIGH = 'high';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_LOW = 'low';
    
    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    /**
     * Add domain to email search queue
     */
    public function queueDomain($domainId, $domain, $campaignId, $priority = self::PRIORITY_MEDIUM) {
        try {
            // Check if already queued
            if ($this->isDomainQueued($domainId)) {
                $this->logInfo("Domain $domain already queued, updating priority to $priority");
                return $this->updateQueuePriority($domainId, $priority);
            }
            
            $sql = "
                INSERT INTO email_search_queue (
                    domain_id, domain, campaign_id, status, priority, 
                    attempt_count, created_at
                ) VALUES (?, ?, ?, ?, ?, 0, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $domainId, $domain, $campaignId, 
                self::STATUS_PENDING, $priority
            ]);
            
            $queueId = $this->db->lastInsertId();
            
            $this->logInfo("Queued domain $domain for email search (Queue ID: $queueId, Priority: $priority)");
            
            return [
                'success' => true,
                'queue_id' => $queueId,
                'message' => 'Domain queued for email search'
            ];
            
        } catch (Exception $e) {
            $this->logError("Failed to queue domain $domain: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get next batch of domains for processing
     */
    public function getNextBatch($batchSize = self::DEFAULT_BATCH_SIZE) {
        try {
            $sql = "
                SELECT 
                    esq.id as queue_id,
                    esq.domain_id,
                    esq.domain,
                    esq.campaign_id,
                    esq.status,
                    esq.priority,
                    esq.attempt_count,
                    esq.max_attempts,
                    esq.last_attempt_at,
                    esq.next_retry_at,
                    esq.error_message,
                    td.status as domain_status,
                    td.email_search_status,
                    td.quality_score
                FROM email_search_queue esq
                JOIN target_domains td ON esq.domain_id = td.id
                WHERE esq.status = ?
                    AND (esq.next_retry_at IS NULL OR esq.next_retry_at <= NOW())
                    AND esq.attempt_count < esq.max_attempts
                    AND td.status = 'approved'
                ORDER BY 
                    CASE esq.priority 
                        WHEN 'high' THEN 1 
                        WHEN 'medium' THEN 2 
                        WHEN 'low' THEN 3 
                    END,
                    esq.created_at ASC
                LIMIT ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_PENDING, $batchSize]);
            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($batch)) {
                $this->logInfo("Retrieved batch of " . count($batch) . " domains for processing");
            }
            
            return $batch;
            
        } catch (Exception $e) {
            $this->logError("Failed to get next batch: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark queue item as processing
     */
    public function markAsProcessing($queueId) {
        try {
            $sql = "
                UPDATE email_search_queue SET 
                    status = ?,
                    processing_started_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND status = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $rowsAffected = $stmt->execute([self::STATUS_PROCESSING, $queueId, self::STATUS_PENDING]);
            
            if ($stmt->rowCount() > 0) {
                $this->logInfo("Marked queue item $queueId as processing");
                return true;
            } else {
                $this->logWarning("Failed to mark queue item $queueId as processing (may already be processing)");
                return false;
            }
            
        } catch (Exception $e) {
            $this->logError("Failed to mark queue item $queueId as processing: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Complete queue item successfully
     */
    public function markAsCompleted($queueId, $emailFound = null) {
        try {
            $sql = "
                UPDATE email_search_queue SET 
                    status = ?,
                    completed_at = NOW(),
                    updated_at = NOW(),
                    error_message = NULL,
                    api_response = ?
                WHERE id = ?
            ";
            
            $apiResponse = $emailFound ? json_encode(['email_found' => $emailFound]) : null;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_COMPLETED, $apiResponse, $queueId]);
            
            $this->logSuccess("Completed queue item $queueId" . ($emailFound ? " with email: $emailFound" : " (no email found)"));
            return true;
            
        } catch (Exception $e) {
            $this->logError("Failed to mark queue item $queueId as completed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle queue item failure with retry logic
     */
    public function markAsFailed($queueId, $errorMessage, $attemptNumber = null) {
        try {
            // Get current attempt count if not provided
            if ($attemptNumber === null) {
                $sql = "SELECT attempt_count FROM email_search_queue WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$queueId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $attemptNumber = ($result['attempt_count'] ?? 0) + 1;
            }
            
            $nextRetryAt = null;
            $status = self::STATUS_FAILED;
            
            // Calculate retry time if under max attempts
            if ($attemptNumber < self::MAX_ATTEMPTS) {
                $delaySeconds = self::RETRY_DELAYS[$attemptNumber - 1] ?? 900; // Default 15 minutes
                $nextRetryAt = date('Y-m-d H:i:s', time() + $delaySeconds);
                $status = self::STATUS_PENDING; // Will be retried
                
                $this->logInfo("Scheduling retry for queue item $queueId in $delaySeconds seconds (attempt $attemptNumber/" . self::MAX_ATTEMPTS . ")");
            } else {
                $this->logError("Queue item $queueId failed all " . self::MAX_ATTEMPTS . " attempts, marking as permanently failed");
            }
            
            $sql = "
                UPDATE email_search_queue SET 
                    status = ?,
                    attempt_count = ?,
                    last_attempt_at = NOW(),
                    next_retry_at = ?,
                    error_message = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$status, $attemptNumber, $nextRetryAt, $errorMessage, $queueId]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logError("Failed to mark queue item $queueId as failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reset stuck jobs that have been processing too long
     */
    public function resetStuckJobs() {
        try {
            $sql = "
                UPDATE email_search_queue SET 
                    status = ?,
                    processing_started_at = NULL,
                    error_message = CONCAT(COALESCE(error_message, ''), ' [Job was stuck and reset]'),
                    updated_at = NOW()
                WHERE status = ? 
                    AND processing_started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_PENDING, self::STATUS_PROCESSING, self::STUCK_JOB_TIMEOUT_MINUTES]);
            
            $resetCount = $stmt->rowCount();
            
            if ($resetCount > 0) {
                $this->logWarning("Reset $resetCount stuck jobs that were processing for more than " . self::STUCK_JOB_TIMEOUT_MINUTES . " minutes");
            }
            
            return $resetCount;
            
        } catch (Exception $e) {
            $this->logError("Failed to reset stuck jobs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if domain is already queued
     */
    public function isDomainQueued($domainId) {
        try {
            $sql = "
                SELECT COUNT(*) as count 
                FROM email_search_queue 
                WHERE domain_id = ? 
                    AND status IN (?, ?)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$domainId, self::STATUS_PENDING, self::STATUS_PROCESSING]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            $this->logError("Failed to check if domain $domainId is queued: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update queue priority for existing item
     */
    public function updateQueuePriority($domainId, $priority) {
        try {
            $sql = "
                UPDATE email_search_queue SET 
                    priority = ?,
                    updated_at = NOW()
                WHERE domain_id = ? 
                    AND status IN (?, ?)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$priority, $domainId, self::STATUS_PENDING, self::STATUS_PROCESSING]);
            
            if ($stmt->rowCount() > 0) {
                $this->logInfo("Updated priority to $priority for domain $domainId in queue");
                return ['success' => true, 'message' => 'Priority updated'];
            } else {
                return ['success' => false, 'message' => 'No active queue items found for domain'];
            }
            
        } catch (Exception $e) {
            $this->logError("Failed to update priority for domain $domainId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStatistics() {
        try {
            $sql = "
                SELECT 
                    status,
                    priority,
                    COUNT(*) as count
                FROM email_search_queue 
                GROUP BY status, priority
                ORDER BY 
                    CASE status 
                        WHEN 'pending' THEN 1 
                        WHEN 'processing' THEN 2 
                        WHEN 'completed' THEN 3 
                        WHEN 'failed' THEN 4 
                    END,
                    CASE priority 
                        WHEN 'high' THEN 1 
                        WHEN 'medium' THEN 2 
                        WHEN 'low' THEN 3 
                    END
            ";
            
            $stmt = $this->db->query($sql);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format results
            $stats = [
                'by_status' => [],
                'by_priority' => [],
                'total' => 0
            ];
            
            foreach ($results as $row) {
                $stats['by_status'][$row['status']] = ($stats['by_status'][$row['status']] ?? 0) + $row['count'];
                $stats['by_priority'][$row['priority']] = ($stats['by_priority'][$row['priority']] ?? 0) + $row['count'];
                $stats['total'] += $row['count'];
            }
            
            // Add queue health metrics
            $sql = "
                SELECT 
                    COUNT(*) as stuck_jobs
                FROM email_search_queue 
                WHERE status = ? 
                    AND processing_started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_PROCESSING, self::STUCK_JOB_TIMEOUT_MINUTES]);
            $stuckJobs = $stmt->fetch(PDO::FETCH_ASSOC)['stuck_jobs'];
            
            $stats['health'] = [
                'stuck_jobs' => $stuckJobs,
                'pending_ready' => $stats['by_status'][self::STATUS_PENDING] ?? 0,
                'processing' => $stats['by_status'][self::STATUS_PROCESSING] ?? 0,
                'failed_retryable' => $this->getRetryableFailedCount()
            ];
            
            return $stats;
            
        } catch (Exception $e) {
            $this->logError("Failed to get queue statistics: " . $e->getMessage());
            return [
                'by_status' => [],
                'by_priority' => [],
                'total' => 0,
                'health' => ['stuck_jobs' => 0, 'pending_ready' => 0, 'processing' => 0, 'failed_retryable' => 0]
            ];
        }
    }
    
    /**
     * Get count of failed items that can be retried
     */
    private function getRetryableFailedCount() {
        try {
            $sql = "
                SELECT COUNT(*) as count
                FROM email_search_queue 
                WHERE status = ? 
                    AND attempt_count < max_attempts
                    AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_PENDING]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] ?? 0;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Clean up old completed/failed queue items
     */
    public function cleanupOldItems($daysOld = 30) {
        try {
            $sql = "
                DELETE FROM email_search_queue 
                WHERE status IN (?, ?) 
                    AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_COMPLETED, self::STATUS_FAILED, $daysOld]);
            
            $deletedCount = $stmt->rowCount();
            
            if ($deletedCount > 0) {
                $this->logInfo("Cleaned up $deletedCount old queue items older than $daysOld days");
            }
            
            return $deletedCount;
            
        } catch (Exception $e) {
            $this->logError("Failed to cleanup old queue items: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get queue item details by ID
     */
    public function getQueueItem($queueId) {
        try {
            $sql = "
                SELECT 
                    esq.*,
                    td.domain as target_domain,
                    td.status as domain_status,
                    td.email_search_status,
                    td.contact_email,
                    c.name as campaign_name
                FROM email_search_queue esq
                JOIN target_domains td ON esq.domain_id = td.id
                JOIN campaigns c ON esq.campaign_id = c.id
                WHERE esq.id = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$queueId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->logError("Failed to get queue item $queueId: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Manually retry a failed queue item
     */
    public function retryQueueItem($queueId, $force = false) {
        try {
            $item = $this->getQueueItem($queueId);
            
            if (!$item) {
                throw new Exception("Queue item not found");
            }
            
            if (!$force && $item['attempt_count'] >= self::MAX_ATTEMPTS) {
                throw new Exception("Queue item has exceeded maximum attempts");
            }
            
            $sql = "
                UPDATE email_search_queue SET 
                    status = ?,
                    next_retry_at = NULL,
                    error_message = CONCAT(COALESCE(error_message, ''), ' [Manual retry initiated]'),
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::STATUS_PENDING, $queueId]);
            
            $this->logInfo("Manually retried queue item $queueId for domain {$item['domain']}");
            
            return [
                'success' => true,
                'message' => 'Queue item scheduled for retry'
            ];
            
        } catch (Exception $e) {
            $this->logError("Failed to retry queue item $queueId: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Logging methods
    private function logInfo($message) {
        error_log("[EmailSearchQueue][INFO] " . $message);
    }
    
    private function logSuccess($message) {
        error_log("[EmailSearchQueue][SUCCESS] " . $message);
    }
    
    private function logWarning($message) {
        error_log("[EmailSearchQueue][WARNING] " . $message);
    }
    
    private function logError($message) {
        error_log("[EmailSearchQueue][ERROR] " . $message);
    }
}
?>