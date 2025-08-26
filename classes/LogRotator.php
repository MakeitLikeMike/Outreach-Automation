<?php

class LogRotator {
    private $maxLines;
    private $logFile;
    
    public function __construct($logFile, $maxLines = 800) {
        $this->logFile = $logFile;
        $this->maxLines = $maxLines;
    }
    
    public function rotateIfNeeded() {
        if (!file_exists($this->logFile)) {
            return;
        }
        
        $lineCount = $this->countLines($this->logFile);
        
        if ($lineCount > $this->maxLines) {
            $this->rotateLogs();
        }
    }
    
    private function countLines($file) {
        $count = 0;
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return 0;
        }
        
        while (($line = fgets($handle)) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }
    
    private function rotateLogs() {
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }
        
        // Keep only the last 600 lines (leaving room for 200 more before next rotation)
        $keepLines = (int)($this->maxLines * 0.75); // Keep 75% = 600 lines
        $linesToKeep = array_slice($lines, -$keepLines);
        
        // Write back to file
        file_put_contents($this->logFile, implode(PHP_EOL, $linesToKeep) . PHP_EOL);
        
        // Log the rotation
        $timestamp = date('Y-m-d H:i:s');
        $rotationMsg = "[$timestamp] 🔄 Log rotated - kept last $keepLines lines" . PHP_EOL;
        file_put_contents($this->logFile, $rotationMsg, FILE_APPEND);
    }
    
    public function logMessage($message) {
        // Check if rotation is needed before logging
        $this->rotateIfNeeded();
        
        // Add timestamp if not already present
        if (!preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $message)) {
            $timestamp = date('Y-m-d H:i:s');
            $message = "[$timestamp] $message";
        }
        
        file_put_contents($this->logFile, $message . PHP_EOL, FILE_APPEND);
    }
}
?>