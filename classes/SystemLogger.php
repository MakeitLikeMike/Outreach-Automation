<?php

class SystemLogger {
    private $logFile;
    private $correlationId;
    
    public function __construct($logFile = null) {
        $this->logFile = $logFile ?? __DIR__ . '/../logs/system.log';
        $this->correlationId = uniqid('req_', true);
        $this->ensureLogDirectory();
    }
    
    private function ensureLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public function logInfo($context, $message, $data = []) {
        $this->writeLog('INFO', $context, $message, $data);
    }
    
    public function logError($context, $message, $data = []) {
        $this->writeLog('ERROR', $context, $message, $data);
    }
    
    public function logDebug($context, $message, $data = []) {
        $this->writeLog('DEBUG', $context, $message, $data);
    }
    
    public function logWarning($context, $message, $data = []) {
        $this->writeLog('WARNING', $context, $message, $data);
    }
    
    private function writeLog($level, $context, $message, $data = []) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'correlation_id' => $this->correlationId,
            'context' => $context,
            'message' => $message,
            'data' => $data,
            'memory_usage' => memory_get_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ];
        
        $logLine = json_encode($logEntry) . "\n";
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    public function getCorrelationId() {
        return $this->correlationId;
    }
    
    public function setCorrelationId($id) {
        $this->correlationId = $id;
    }
    
    // Quick outreach specific logging
    public function logQuickOutreachFlow($step, $data = []) {
        $this->logInfo('QUICK_OUTREACH', "Step: {$step}", $data);
    }
    
    // Email sending specific logging
    public function logEmailSending($step, $data = []) {
        $this->logInfo('EMAIL_SENDING', "Step: {$step}", $data);
    }
    
    // Token refresh specific logging
    public function logTokenRefresh($step, $data = []) {
        $this->logInfo('TOKEN_REFRESH', "Step: {$step}", $data);
    }
    
    // Pipeline automation specific logging
    public function logPipelineStep($step, $data = []) {
        $this->logInfo('PIPELINE', "Step: {$step}", $data);
    }
    
    // Database operation logging
    public function logDatabaseOperation($operation, $table, $data = []) {
        $this->logInfo('DATABASE', "Operation: {$operation} on {$table}", $data);
    }
}
?>