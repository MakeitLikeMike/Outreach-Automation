<?php
/**
 * Reply Classifier - AI-powered email reply classification
 * Uses ChatGPT to analyze and classify incoming email replies
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ChatGPTIntegration.php';

class ReplyClassifier {
    private $db;
    private $chatgpt;
    private $logFile;
    
    // Classification categories
    const CATEGORIES = [
        'positive' => 'Interested in guest posting collaboration',
        'negative' => 'Not interested or declined',
        'neutral' => 'Neutral response, needs follow-up',
        'question' => 'Asked questions, requires response',
        'spam' => 'Spam or automated response',
        'bounce' => 'Delivery failure or bounce',
        'out_of_office' => 'Out of office auto-reply'
    ];
    
    // Confidence thresholds
    const HIGH_CONFIDENCE = 0.8;
    const MEDIUM_CONFIDENCE = 0.6;
    const LOW_CONFIDENCE = 0.4;
    
    public function __construct() {
        $this->db = new Database();
        $this->chatgpt = new ChatGPTIntegration();
        $this->logFile = __DIR__ . '/../logs/reply_classifier.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Classify a reply using AI
     */
    public function classifyReply($replyContent, $originalSubject, $senderEmail) {
        $this->log("🤖 Classifying reply from: {$senderEmail}");
        
        try {
            // First, try rule-based classification for obvious cases
            $ruleBasedResult = $this->ruleBasedClassification($replyContent, $originalSubject);
            
            if ($ruleBasedResult['confidence'] >= self::HIGH_CONFIDENCE) {
                $this->log("✅ Rule-based classification: {$ruleBasedResult['category']} (confidence: {$ruleBasedResult['confidence']})");
                return $ruleBasedResult;
            }
            
            // Use AI for complex classification
            $aiResult = $this->aiClassification($replyContent, $originalSubject, $senderEmail);
            
            // Combine rule-based and AI results for final decision
            $finalResult = $this->combineClassifications($ruleBasedResult, $aiResult);
            
            $this->log("✅ AI classification: {$finalResult['category']} (confidence: {$finalResult['confidence']})");
            
            return $finalResult;
            
        } catch (Exception $e) {
            $this->log("❌ Classification failed: " . $e->getMessage());
            
            // Return fallback classification
            return [
                'category' => 'neutral',
                'confidence' => 0.3,
                'sentiment_score' => 0.5,
                'key_phrases' => [],
                'requires_followup' => true,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Rule-based classification for obvious patterns
     */
    private function ruleBasedClassification($content, $subject) {
        $content = strtolower($content);
        $subject = strtolower($subject);
        
        // Out of office patterns
        $outOfOfficePatterns = [
            'out of office', 'away from office', 'currently out', 'automatic reply',
            'auto-reply', 'vacation', 'travelling', 'will be back'
        ];
        
        foreach ($outOfOfficePatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return [
                    'category' => 'out_of_office',
                    'confidence' => 0.95,
                    'sentiment_score' => 0.5,
                    'key_phrases' => [$pattern],
                    'requires_followup' => false
                ];
            }
        }
        
        // Positive interest patterns
        $positivePatterns = [
            'interested', 'sounds good', 'yes, i\'d like', 'that works',
            'please send', 'tell me more', 'love to collaborate',
            'happy to discuss', 'sounds great'
        ];
        
        $positiveScore = 0;
        $foundPositive = [];
        
        foreach ($positivePatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                $positiveScore += 0.2;
                $foundPositive[] = $pattern;
            }
        }
        
        if ($positiveScore >= 0.4) {
            return [
                'category' => 'positive',
                'confidence' => min(0.8, $positiveScore + 0.4),
                'sentiment_score' => min(1.0, $positiveScore + 0.6),
                'key_phrases' => $foundPositive,
                'requires_followup' => true
            ];
        }
        
        // Negative patterns
        $negativePatterns = [
            'not interested', 'no thank you', 'don\'t accept', 'not accepting',
            'decline', 'pass', 'not a fit', 'already have', 'remove me'
        ];
        
        foreach ($negativePatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return [
                    'category' => 'negative',
                    'confidence' => 0.85,
                    'sentiment_score' => 0.1,
                    'key_phrases' => [$pattern],
                    'requires_followup' => false
                ];
            }
        }
        
        // Default to low-confidence neutral
        return [
            'category' => 'neutral',
            'confidence' => 0.3,
            'sentiment_score' => 0.5,
            'key_phrases' => [],
            'requires_followup' => true
        ];
    }
    
    /**
     * AI-powered classification using ChatGPT
     */
    private function aiClassification($content, $originalSubject, $senderEmail) {
        $prompt = $this->buildClassificationPrompt($content, $originalSubject, $senderEmail);
        
        $response = $this->chatgpt->generateContent($prompt);
        
        if (!$response['success']) {
            throw new Exception("AI classification failed: " . $response['error']);
        }
        
        return $this->parseAiResponse($response['content']);
    }
    
    /**
     * Build classification prompt for ChatGPT
     */
    private function buildClassificationPrompt($content, $originalSubject, $senderEmail) {
        return "Analyze this email reply for guest post outreach classification:

ORIGINAL SUBJECT: {$originalSubject}
SENDER EMAIL: {$senderEmail}
REPLY CONTENT:
{$content}

Please classify this reply into one of these categories:
- positive: Interested in collaboration, wants to proceed
- negative: Not interested, declined, or rejected
- neutral: Unclear response, needs more information
- question: Asked questions, requires response
- spam: Spam or automated unwanted response
- out_of_office: Out of office auto-reply

For your classification, provide:
1. Category (one of the above)
2. Confidence score (0.0 to 1.0)
3. Sentiment score (0.0 = very negative, 1.0 = very positive)
4. Key phrases that influenced your decision
5. Whether follow-up is recommended (yes/no)

Please respond in this exact JSON format:
{
    \"category\": \"[category]\",
    \"confidence\": [0.0-1.0],
    \"sentiment_score\": [0.0-1.0],
    \"key_phrases\": [\"phrase1\", \"phrase2\"],
    \"requires_followup\": [true/false],
    \"reasoning\": \"Brief explanation of classification\"
}";
    }
    
    /**
     * Parse AI response into structured format
     */
    private function parseAiResponse($response) {
        try {
            // Try to extract JSON from the response
            $jsonStart = strpos($response, '{');
            $jsonEnd = strrpos($response, '}');
            
            if ($jsonStart !== false && $jsonEnd !== false) {
                $jsonStr = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
                $decoded = json_decode($jsonStr, true);
                
                if ($decoded && isset($decoded['category'])) {
                    // Validate and clean the response
                    return [
                        'category' => $this->validateCategory($decoded['category']),
                        'confidence' => max(0.0, min(1.0, floatval($decoded['confidence'] ?? 0.5))),
                        'sentiment_score' => max(0.0, min(1.0, floatval($decoded['sentiment_score'] ?? 0.5))),
                        'key_phrases' => is_array($decoded['key_phrases'] ?? []) ? $decoded['key_phrases'] : [],
                        'requires_followup' => boolval($decoded['requires_followup'] ?? true),
                        'reasoning' => $decoded['reasoning'] ?? ''
                    ];
                }
            }
            
            // Fallback parsing
            return $this->fallbackResponseParsing($response);
            
        } catch (Exception $e) {
            $this->log("⚠️ Failed to parse AI response: " . $e->getMessage());
            return $this->fallbackResponseParsing($response);
        }
    }
    
    /**
     * Validate category against allowed values
     */
    private function validateCategory($category) {
        $category = strtolower(trim($category));
        return array_key_exists($category, self::CATEGORIES) ? $category : 'neutral';
    }
    
    /**
     * Combine rule-based and AI classifications
     */
    private function combineClassifications($ruleResult, $aiResult) {
        // If rule-based has high confidence, trust it
        if ($ruleResult['confidence'] >= self::HIGH_CONFIDENCE) {
            return $ruleResult;
        }
        
        // If they agree on category, increase confidence
        if ($ruleResult['category'] === $aiResult['category']) {
            return [
                'category' => $aiResult['category'],
                'confidence' => min(1.0, ($ruleResult['confidence'] + $aiResult['confidence']) / 1.5),
                'sentiment_score' => ($ruleResult['sentiment_score'] + $aiResult['sentiment_score']) / 2,
                'key_phrases' => array_merge($ruleResult['key_phrases'], $aiResult['key_phrases']),
                'requires_followup' => $aiResult['requires_followup'],
                'reasoning' => $aiResult['reasoning'] ?? ''
            ];
        }
        
        // If they disagree, use AI result (assuming it has more context)
        return $aiResult;
    }
    
    /**
     * Fallback response parsing if JSON parsing fails
     */
    private function fallbackResponseParsing($response) {
        $response = strtolower($response);
        
        // Extract category
        $category = 'neutral';
        foreach (array_keys(self::CATEGORIES) as $cat) {
            if (strpos($response, $cat) !== false) {
                $category = $cat;
                break;
            }
        }
        
        return [
            'category' => $category,
            'confidence' => 0.5,
            'sentiment_score' => 0.5,
            'key_phrases' => [],
            'requires_followup' => true,
            'reasoning' => 'Fallback parsing used'
        ];
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>