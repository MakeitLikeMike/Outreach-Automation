<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../classes/ChatGPTIntegration.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    $targetDomain = $input['target_domain'] ?? '';
    $userWebsite = $input['user_website'] ?? '';
    $emailType = $input['email_type'] ?? 'guest_post';
    $analysisData = $input['analysis_data'] ?? [];
    
    if (empty($targetDomain) || empty($userWebsite)) {
        throw new Exception('Target domain and user website are required');
    }
    
    $chatgpt = new ChatGPTIntegration();
    
    // Generate the email using ChatGPT
    $emailResult = $chatgpt->generateOutreachEmail($targetDomain, $userWebsite, $analysisData, $emailType);
    
    if (!$emailResult['success']) {
        throw new Exception($emailResult['error'] ?? 'Failed to generate email');
    }
    
    // Format the email content for better display
    $formattedEmail = formatEmailContent($emailResult['email'], $emailType, $targetDomain, $userWebsite);
    
    echo json_encode([
        'success' => true,
        'email_content' => $formattedEmail,
        'subject' => $emailResult['subject'] ?? 'Guest Post Collaboration Opportunity',
        'tokens_used' => $emailResult['tokens_used'] ?? 0,
        'target_domain' => $targetDomain,
        'user_website' => $userWebsite,
        'email_type' => $emailType
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function formatEmailContent($emailContent, $emailType, $targetDomain, $userWebsite) {
    // Extract components from the email
    $lines = explode("\n", $emailContent);
    $formattedLines = [];
    $inBody = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            if ($inBody) {
                $formattedLines[] = '<br>';
            }
            continue;
        }
        
        // Format different parts of the email
        if (preg_match('/^To:\s*(.+)$/i', $line, $matches)) {
            $formattedLines[] = '<div class="email-meta"><strong>To:</strong> ' . htmlspecialchars(trim($matches[1])) . '</div>';
        } elseif (preg_match('/^Subject:\s*(.+)$/i', $line, $matches)) {
            $formattedLines[] = '<div class="email-meta"><strong>Subject:</strong> ' . htmlspecialchars(trim($matches[1])) . '</div>';
            $formattedLines[] = '<hr style="margin: 1rem 0; border-color: #dee2e6;">';
            $inBody = true;
        } elseif (preg_match('/^(From|Date):/i', $line)) {
            $formattedLines[] = '<div class="email-meta"><strong>' . htmlspecialchars($line) . '</strong></div>';
        } elseif ($inBody && preg_match('/^(Hi there|Dear\s+|Hello)/i', $line)) {
            $formattedLines[] = '<div class="email-greeting">' . htmlspecialchars($line) . '</div>';
        } elseif ($inBody && preg_match('/^(Best regards|Sincerely|Thank you|Cheers|Looking forward)/i', $line)) {
            $formattedLines[] = '<div class="email-closing">' . htmlspecialchars($line) . '</div>';
        } elseif ($inBody && preg_match('/^-\s/', $line)) {
            // Format bullet points
            $formattedLines[] = '<div class="email-bullet">• ' . htmlspecialchars(ltrim($line, '- ')) . '</div>';
        } elseif ($inBody && !empty($line)) {
            // Regular paragraph
            $formattedLines[] = '<div class="email-paragraph">' . htmlspecialchars($line) . '</div>';
        } elseif (!$inBody) {
            // Before body starts (headers)
            $formattedLines[] = '<div class="email-line">' . htmlspecialchars($line) . '</div>';
        }
    }
    
    return implode('', $formattedLines);
}
?> 