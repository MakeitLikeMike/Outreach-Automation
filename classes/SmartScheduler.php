<?php
/**
 * Smart Campaign Scheduler
 * Intelligent scheduling system with time zone optimization and automated sequencing
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GMassIntegration.php';

class SmartScheduler {
    private $db;
    private $gmass;
    private $timezoneCache = [];
    
    // Optimal sending times by timezone (24-hour format)
    private $optimalSendingTimes = [
        'business_hours' => ['start' => 9, 'end' => 17],
        'peak_engagement' => [10, 14, 16], // 10 AM, 2 PM, 4 PM
        'avoid_times' => [
            'early_morning' => ['start' => 0, 'end' => 8],
            'late_evening' => ['start' => 19, 'end' => 23],
            'weekend_morning' => ['saturday' => [0, 10], 'sunday' => [0, 12]]
        ]
    ];
    
    // Holiday calendar (simplified - in production would use an API)
    private $holidays = [
        'US' => ['2024-12-25', '2024-01-01', '2024-07-04', '2024-11-28'],
        'UK' => ['2024-12-25', '2024-01-01', '2024-05-27', '2024-08-26'],
        'international' => ['2024-12-25', '2024-01-01']
    ];
    
    public function __construct() {
        $this->db = new Database();
        $this->gmass = new GMassIntegration();
        $this->createSchedulerTables();
    }
    
    /**
     * Schedule a campaign with intelligent timing
     */
    public function scheduleCampaign($campaignId, $scheduleSettings = []) {
        $defaults = [
            'send_time_optimization' => true,
            'timezone_aware' => true,
            'respect_business_hours' => true,
            'avoid_holidays' => true,
            'batch_size' => 50,
            'delay_between_batches' => 300, // 5 minutes
            'follow_up_sequences' => [],
            'max_sends_per_day' => 200
        ];
        
        $settings = array_merge($defaults, $scheduleSettings);
        
        // Get campaign details
        $campaign = $this->db->fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
        if (!$campaign) {
            throw new Exception("Campaign not found: {$campaignId}");
        }
        
        // Get target domains for the campaign
        $domains = $this->db->fetchAll(
            "SELECT * FROM target_domains WHERE campaign_id = ? AND status = 'qualified'",
            [$campaignId]
        );
        
        if (empty($domains)) {
            throw new Exception("No qualified domains found for campaign: {$campaignId}");
        }
        
        // Create schedule entries
        $scheduleId = $this->createScheduleEntry($campaignId, $settings);
        
        // Process domains and create optimized sending schedule
        $scheduledJobs = [];
        $currentDate = new DateTime();
        $batchCount = 0;
        
        foreach (array_chunk($domains, $settings['batch_size']) as $batch) {
            $batchCount++;
            
            // Calculate optimal send time for this batch
            $optimalTime = $this->calculateOptimalSendTime($batch, $currentDate, $settings);
            
            // Create scheduled job for this batch
            $jobId = $this->createScheduledJob([
                'schedule_id' => $scheduleId,
                'campaign_id' => $campaignId,
                'batch_number' => $batchCount,
                'scheduled_time' => $optimalTime,
                'domain_ids' => array_column($batch, 'id'),
                'status' => 'scheduled',
                'job_type' => 'email_batch'
            ]);
            
            $scheduledJobs[] = $jobId;
            
            // Add delay for next batch
            $currentDate->add(new DateInterval('PT' . $settings['delay_between_batches'] . 'S'));
        }
        
        // Schedule follow-up sequences if configured
        if (!empty($settings['follow_up_sequences'])) {
            $this->scheduleFollowUpSequences($scheduleId, $campaignId, $settings['follow_up_sequences']);
        }
        
        return [
            'schedule_id' => $scheduleId,
            'total_batches' => $batchCount,
            'total_domains' => count($domains),
            'scheduled_jobs' => $scheduledJobs,
            'estimated_completion' => $currentDate->format('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Calculate optimal send time for a batch of domains
     */
    private function calculateOptimalSendTime($domains, $baseTime, $settings) {
        if (!$settings['send_time_optimization']) {
            return $baseTime->format('Y-m-d H:i:s');
        }
        
        $timezoneGroups = [];
        
        // Group domains by timezone
        foreach ($domains as $domain) {
            $timezone = $this->detectTimezone($domain['domain']);
            if (!isset($timezoneGroups[$timezone])) {
                $timezoneGroups[$timezone] = [];
            }
            $timezoneGroups[$timezone][] = $domain;
        }
        
        // Find the timezone with the most domains (primary timezone for this batch)
        $primaryTimezone = array_keys($timezoneGroups, max($timezoneGroups))[0];
        
        // Calculate optimal time in primary timezone
        $optimalTime = clone $baseTime;
        $optimalTime->setTimezone(new DateTimeZone($primaryTimezone));
        
        // Adjust to business hours if enabled
        if ($settings['respect_business_hours']) {
            $hour = (int)$optimalTime->format('H');
            $dayOfWeek = (int)$optimalTime->format('w'); // 0 = Sunday
            
            // If it's weekend or outside business hours, adjust
            if ($dayOfWeek == 0 || $dayOfWeek == 6 || 
                $hour < $this->optimalSendingTimes['business_hours']['start'] || 
                $hour > $this->optimalSendingTimes['business_hours']['end']) {
                
                $optimalTime = $this->adjustToBusinessHours($optimalTime);
            }
        }
        
        // Avoid holidays if enabled
        if ($settings['avoid_holidays']) {
            $optimalTime = $this->avoidHolidays($optimalTime, $primaryTimezone);
        }
        
        // Convert back to server timezone
        $optimalTime->setTimezone(new DateTimeZone(date_default_timezone_get()));
        
        return $optimalTime->format('Y-m-d H:i:s');
    }
    
    /**
     * Detect timezone from domain
     */
    private function detectTimezone($domain) {
        if (isset($this->timezoneCache[$domain])) {
            return $this->timezoneCache[$domain];
        }
        
        // Try to detect from TLD
        $tld = substr($domain, strrpos($domain, '.') + 1);
        $timezoneMap = [
            'com' => 'America/New_York', // Default to US Eastern
            'co.uk' => 'Europe/London',
            'fr' => 'Europe/Paris',
            'de' => 'Europe/Berlin',
            'jp' => 'Asia/Tokyo',
            'au' => 'Australia/Sydney',
            'ca' => 'America/Toronto',
            'in' => 'Asia/Kolkata'
        ];
        
        $timezone = $timezoneMap[$tld] ?? 'UTC';
        
        // Cache the result
        $this->timezoneCache[$domain] = $timezone;
        
        // Store in database for future reference
        $this->db->execute(
            "UPDATE target_domains SET detected_timezone = ? WHERE domain = ?",
            [$timezone, $domain]
        );
        
        return $timezone;
    }
    
    /**
     * Adjust time to business hours
     */
    private function adjustToBusinessHours($dateTime) {
        $hour = (int)$dateTime->format('H');
        $dayOfWeek = (int)$dateTime->format('w');
        
        // If weekend, move to next Monday
        if ($dayOfWeek == 0) { // Sunday
            $dateTime->modify('+1 day');
        } elseif ($dayOfWeek == 6) { // Saturday
            $dateTime->modify('+2 days');
        }
        
        // Adjust hour to business hours
        $businessStart = $this->optimalSendingTimes['business_hours']['start'];
        $businessEnd = $this->optimalSendingTimes['business_hours']['end'];
        
        if ($hour < $businessStart) {
            $dateTime->setTime($businessStart, 0, 0);
        } elseif ($hour > $businessEnd) {
            $dateTime->modify('+1 day');
            $dateTime->setTime($businessStart, 0, 0);
            // Check if next day is weekend
            if ((int)$dateTime->format('w') == 6) {
                $dateTime->modify('+2 days');
            } elseif ((int)$dateTime->format('w') == 0) {
                $dateTime->modify('+1 day');
            }
        }
        
        return $dateTime;
    }
    
    /**
     * Avoid holidays
     */
    private function avoidHolidays($dateTime, $timezone) {
        $dateStr = $dateTime->format('Y-m-d');
        
        // Check if date is a holiday
        $isHoliday = in_array($dateStr, $this->holidays['international']) ||
                     in_array($dateStr, $this->holidays['US']); // Default to US holidays
        
        if ($isHoliday) {
            // Move to next business day
            do {
                $dateTime->modify('+1 day');
                $dayOfWeek = (int)$dateTime->format('w');
            } while ($dayOfWeek == 0 || $dayOfWeek == 6 || 
                     in_array($dateTime->format('Y-m-d'), $this->holidays['international']));
        }
        
        return $dateTime;
    }
    
    /**
     * Schedule follow-up sequences
     */
    private function scheduleFollowUpSequences($scheduleId, $campaignId, $sequences) {
        foreach ($sequences as $sequenceIndex => $sequence) {
            $followUpDelay = $sequence['delay_days'] ?? 7;
            $followUpTime = new DateTime();
            $followUpTime->add(new DateInterval('P' . $followUpDelay . 'D'));
            
            $this->createScheduledJob([
                'schedule_id' => $scheduleId,
                'campaign_id' => $campaignId,
                'batch_number' => 0,
                'scheduled_time' => $followUpTime->format('Y-m-d H:i:s'),
                'domain_ids' => [], // Will be populated based on response status
                'status' => 'scheduled',
                'job_type' => 'follow_up',
                'sequence_data' => json_encode($sequence)
            ]);
        }
    }
    
    /**
     * Process scheduled jobs (called by background processor)
     */
    public function processScheduledJobs() {
        $currentTime = date('Y-m-d H:i:s');
        
        $readyJobs = $this->db->fetchAll(
            "SELECT * FROM scheduled_jobs 
             WHERE status = 'scheduled' 
             AND scheduled_time <= ? 
             ORDER BY scheduled_time ASC",
            [$currentTime]
        );
        
        $processed = 0;
        
        foreach ($readyJobs as $job) {
            try {
                $this->processScheduledJob($job);
                $processed++;
            } catch (Exception $e) {
                $this->markJobFailed($job['id'], $e->getMessage());
            }
        }
        
        return $processed;
    }
    
    /**
     * Process individual scheduled job
     */
    private function processScheduledJob($job) {
        $this->markJobInProgress($job['id']);
        
        switch ($job['job_type']) {
            case 'email_batch':
                $this->processEmailBatch($job);
                break;
            case 'follow_up':
                $this->processFollowUp($job);
                break;
            default:
                throw new Exception("Unknown job type: " . $job['job_type']);
        }
        
        $this->markJobCompleted($job['id']);
    }
    
    /**
     * Process email batch
     */
    private function processEmailBatch($job) {
        $domainIds = json_decode($job['domain_ids'], true);
        
        // Get email template for campaign
        $template = $this->db->fetchOne(
            "SELECT et.* FROM email_templates et 
             JOIN campaigns c ON c.template_id = et.id 
             WHERE c.id = ?",
            [$job['campaign_id']]
        );
        
        if (!$template) {
            throw new Exception("No template found for campaign: " . $job['campaign_id']);
        }
        
        // Process each domain in the batch
        foreach ($domainIds as $domainId) {
            $domain = $this->db->fetchOne("SELECT * FROM target_domains WHERE id = ?", [$domainId]);
            
            if ($domain) {
                // Personalize email content
                $personalizedSubject = str_replace('{domain}', $domain['domain'], $template['subject']);
                $personalizedBody = str_replace('{domain}', $domain['domain'], $template['body']);
                
                // Add to email queue
                $this->db->execute(
                    "INSERT INTO email_queue (campaign_id, target_domain_id, template_id, recipient_email, subject, body, status, priority, scheduled_job_id) 
                     VALUES (?, ?, ?, ?, ?, ?, 'queued', 'normal', ?)",
                    [
                        $job['campaign_id'],
                        $domainId,
                        $template['id'],
                        $domain['contact_email'],
                        $personalizedSubject,
                        $personalizedBody,
                        $job['id']
                    ]
                );
            }
        }
    }
    
    /**
     * Process follow-up
     */
    private function processFollowUp($job) {
        $sequenceData = json_decode($job['sequence_data'], true);
        
        // Get domains that need follow-up based on response status
        $targetCondition = $sequenceData['target_condition'] ?? 'no_response';
        
        $sql = "SELECT DISTINCT td.* 
                FROM target_domains td 
                JOIN email_queue eq ON td.id = eq.target_domain_id 
                LEFT JOIN email_replies er ON eq.id = er.original_email_id 
                WHERE eq.campaign_id = ? 
                AND eq.status = 'sent'";
        
        $params = [$job['campaign_id']];
        
        switch ($targetCondition) {
            case 'no_response':
                $sql .= " AND er.id IS NULL";
                break;
            case 'negative_response':
                $sql .= " AND er.classification = 'negative'";
                break;
            case 'neutral_response':
                $sql .= " AND er.classification = 'neutral'";
                break;
        }
        
        $domains = $this->db->fetchAll($sql, $params);
        
        // Schedule follow-up emails for these domains
        foreach ($domains as $domain) {
            $personalizedSubject = str_replace('{domain}', $domain['domain'], $sequenceData['subject']);
            $personalizedBody = str_replace('{domain}', $domain['domain'], $sequenceData['body']);
            
            $this->db->execute(
                "INSERT INTO email_queue (campaign_id, target_domain_id, recipient_email, subject, body, status, priority, scheduled_job_id) 
                 VALUES (?, ?, ?, ?, ?, 'queued', 'high', ?)",
                [
                    $job['campaign_id'],
                    $domain['id'],
                    $domain['contact_email'],
                    $personalizedSubject,
                    $personalizedBody,
                    $job['id']
                ]
            );
        }
    }
    
    /**
     * Get campaign schedule status
     */
    public function getCampaignScheduleStatus($campaignId) {
        $schedule = $this->db->fetchOne(
            "SELECT * FROM campaign_schedules WHERE campaign_id = ? ORDER BY created_at DESC LIMIT 1",
            [$campaignId]
        );
        
        if (!$schedule) {
            return ['status' => 'not_scheduled'];
        }
        
        $jobs = $this->db->fetchAll(
            "SELECT status, COUNT(*) as count 
             FROM scheduled_jobs 
             WHERE schedule_id = ? 
             GROUP BY status",
            [$schedule['id']]
        );
        
        $jobStats = [];
        foreach ($jobs as $job) {
            $jobStats[$job['status']] = $job['count'];
        }
        
        return [
            'schedule_id' => $schedule['id'],
            'status' => $schedule['status'],
            'created_at' => $schedule['created_at'],
            'settings' => json_decode($schedule['settings'], true),
            'job_statistics' => $jobStats,
            'progress' => $this->calculateScheduleProgress($schedule['id'])
        ];
    }
    
    /**
     * Get scheduling recommendations
     */
    public function getSchedulingRecommendations($campaignId) {
        $campaign = $this->db->fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
        $domains = $this->db->fetchAll(
            "SELECT * FROM target_domains WHERE campaign_id = ? AND status = 'qualified'",
            [$campaignId]
        );
        
        $recommendations = [];
        
        // Analyze domain timezones
        $timezones = [];
        foreach ($domains as $domain) {
            $tz = $this->detectTimezone($domain['domain']);
            $timezones[$tz] = ($timezones[$tz] ?? 0) + 1;
        }
        
        arsort($timezones);
        $primaryTimezone = array_key_first($timezones);
        
        $recommendations[] = [
            'type' => 'timezone_optimization',
            'title' => 'Primary Timezone Detected',
            'message' => "Most domains ({$timezones[$primaryTimezone]}) are in {$primaryTimezone}. Consider scheduling during business hours in this timezone.",
            'priority' => 'medium'
        ];
        
        // Check for high-DA domains
        $highDaDomains = array_filter($domains, fn($d) => $d['domain_authority'] >= 60);
        if (count($highDaDomains) > 0) {
            $recommendations[] = [
                'type' => 'high_priority_domains',
                'title' => 'High Authority Domains',
                'message' => count($highDaDomains) . " high-authority domains detected. Consider prioritizing these in early batches.",
                'priority' => 'high'
            ];
        }
        
        // Batch size recommendation
        $totalDomains = count($domains);
        $recommendedBatchSize = min(max(10, $totalDomains / 10), 100);
        
        $recommendations[] = [
            'type' => 'batch_optimization',
            'title' => 'Optimal Batch Size',
            'message' => "For {$totalDomains} domains, recommended batch size is {$recommendedBatchSize} emails per batch.",
            'priority' => 'low'
        ];
        
        return $recommendations;
    }
    
    /**
     * Database setup and helper methods
     */
    private function createSchedulerTables() {
        $tables = [
            'campaign_schedules' => "CREATE TABLE IF NOT EXISTS campaign_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                status ENUM('active', 'paused', 'completed', 'cancelled') DEFAULT 'active',
                settings JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
            )",
            'scheduled_jobs' => "CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schedule_id INT NOT NULL,
                campaign_id INT NOT NULL,
                batch_number INT DEFAULT 0,
                scheduled_time TIMESTAMP NOT NULL,
                domain_ids JSON,
                status ENUM('scheduled', 'in_progress', 'completed', 'failed') DEFAULT 'scheduled',
                job_type VARCHAR(50) DEFAULT 'email_batch',
                sequence_data JSON,
                error_message TEXT,
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_scheduled_time (scheduled_time),
                INDEX idx_status (status),
                FOREIGN KEY (schedule_id) REFERENCES campaign_schedules(id)
            )"
        ];
        
        foreach ($tables as $tableName => $sql) {
            try {
                $this->db->execute($sql);
            } catch (Exception $e) {
                // Table might already exist
            }
        }
        
        // Add timezone column to target_domains if not exists
        try {
            $this->db->execute("ALTER TABLE target_domains ADD COLUMN detected_timezone VARCHAR(50) DEFAULT NULL");
        } catch (Exception $e) {
            // Column might already exist
        }
        
        // Add scheduled_job_id to email_queue if not exists
        try {
            $this->db->execute("ALTER TABLE email_queue ADD COLUMN scheduled_job_id INT DEFAULT NULL");
        } catch (Exception $e) {
            // Column might already exist
        }
    }
    
    private function createScheduleEntry($campaignId, $settings) {
        $this->db->execute(
            "INSERT INTO campaign_schedules (campaign_id, settings) VALUES (?, ?)",
            [$campaignId, json_encode($settings)]
        );
        
        return $this->db->lastInsertId();
    }
    
    private function createScheduledJob($jobData) {
        $this->db->execute(
            "INSERT INTO scheduled_jobs (schedule_id, campaign_id, batch_number, scheduled_time, domain_ids, status, job_type, sequence_data) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $jobData['schedule_id'],
                $jobData['campaign_id'],
                $jobData['batch_number'],
                $jobData['scheduled_time'],
                json_encode($jobData['domain_ids']),
                $jobData['status'],
                $jobData['job_type'],
                json_encode($jobData['sequence_data'] ?? [])
            ]
        );
        
        return $this->db->lastInsertId();
    }
    
    private function markJobInProgress($jobId) {
        $this->db->execute(
            "UPDATE scheduled_jobs SET status = 'in_progress', started_at = NOW() WHERE id = ?",
            [$jobId]
        );
    }
    
    private function markJobCompleted($jobId) {
        $this->db->execute(
            "UPDATE scheduled_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?",
            [$jobId]
        );
    }
    
    private function markJobFailed($jobId, $errorMessage) {
        $this->db->execute(
            "UPDATE scheduled_jobs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?",
            [$errorMessage, $jobId]
        );
    }
    
    private function calculateScheduleProgress($scheduleId) {
        $stats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_jobs,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_jobs,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_jobs
             FROM scheduled_jobs 
             WHERE schedule_id = ?",
            [$scheduleId]
        );
        
        $totalJobs = $stats['total_jobs'] ?: 1;
        $completedJobs = $stats['completed_jobs'] ?: 0;
        
        return [
            'percentage' => ($completedJobs / $totalJobs) * 100,
            'completed' => $completedJobs,
            'total' => $totalJobs,
            'failed' => $stats['failed_jobs'] ?: 0
        ];
    }
}
?>