<?php
require_once 'classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

$action = $_GET['action'] ?? 'list';
$templateId = $_GET['id'] ?? null;
$message = '';
$error = '';

if ($_POST) {
    try {
        if ($action === 'create') {
            $name = $_POST['name'];
            $subject = $_POST['subject'];
            $body = $_POST['body'];
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            $emailTemplate->create($name, $subject, $body, $is_default);
            $message = "Email template created successfully!";
            $action = 'list';
        } elseif ($action === 'update' && $templateId) {
            $name = $_POST['name'];
            $subject = $_POST['subject'];
            $body = $_POST['body'];
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            $emailTemplate->update($templateId, $name, $subject, $body, $is_default);
            $message = "Email template updated successfully!";
            $action = 'list';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'delete' && $templateId) {
    try {
        $emailTemplate->delete($templateId);
        $message = "Email template deleted successfully!";
        $action = 'list';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$templates = $emailTemplate->getAll();
$currentTemplate = null;
if ($templateId && in_array($action, ['edit', 'view', 'preview'])) {
    $currentTemplate = $emailTemplate->getById($templateId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates - Outreach Automation</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-envelope"></i> Outreach Automation</h2>
        </div>
        <ul class="nav-menu">
            <li><a href="index.php"><i class="fas fa-dashboard"></i> Dashboard</a></li>
            <li><a href="campaigns.php"><i class="fas fa-bullhorn"></i> Campaigns</a></li>
            <li><a href="domains.php"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="templates.php" class="active"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>
                <?php
                switch ($action) {
                    case 'new':
                    case 'create':
                        echo 'Create New Template';
                        break;
                    case 'edit':
                        echo 'Edit Template';
                        break;
                    case 'view':
                        echo 'Template Details';
                        break;
                    case 'preview':
                        echo 'Template Preview';
                        break;
                    default:
                        echo 'Email Templates';
                }
                ?>
            </h1>
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

            <?php if ($action === 'list'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-text"></i> All Email Templates</h3>
                        <a href="templates.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Template
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($templates)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Template Name</th>
                                        <th>Subject Line</th>
                                        <th>Default</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($templates as $template): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($template['name']); ?></strong>
                                                <?php if ($template['is_default']): ?>
                                                    <span class="badge badge-primary">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($template['subject'], 0, 50)) . (strlen($template['subject']) > 50 ? '...' : ''); ?></td>
                                            <td>
                                                <?php if ($template['is_default']): ?>
                                                    <i class="fas fa-check text-success"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times text-muted"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($template['created_at'])); ?></td>
                                            <td>
                                                <a href="templates.php?action=preview&id=<?php echo $template['id']; ?>" class="btn btn-sm btn-success">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="templates.php?action=edit&id=<?php echo $template['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if (!$template['is_default']): ?>
                                                    <a href="templates.php?action=delete&id=<?php echo $template['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="info-box mt-3">
                                <h4><i class="fas fa-info-circle"></i> Template Variables</h4>
                                <p>You can use the following variables in your templates:</p>
                                <div class="variable-grid">
                                    <div class="variable-item">
                                        <code>{DOMAIN}</code>
                                        <span>Target domain name</span>
                                    </div>
                                    <div class="variable-item">
                                        <code>{RECIPIENT_EMAIL}</code>
                                        <span>Recipient's email address</span>
                                    </div>
                                    <div class="variable-item">
                                        <code>{SENDER_NAME}</code>
                                        <span>Your name/company</span>
                                    </div>
                                    <div class="variable-item">
                                        <code>{TOPIC_AREA}</code>
                                        <span>Relevant topic/industry</span>
                                    </div>
                                    <div class="variable-item">
                                        <code>{INDUSTRY}</code>
                                        <span>Industry focus</span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-file-text"></i>
                                <p>No email templates created yet.</p>
                                <a href="templates.php?action=new" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Create Your First Template
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'new' || $action === 'create'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-plus"></i> Create New Email Template</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="templates.php?action=create">
                            <div class="form-group">
                                <label for="name">Template Name *</label>
                                <input type="text" id="name" name="name" class="form-control" required 
                                       placeholder="e.g., Technology Outreach Template">
                            </div>

                            <div class="form-group">
                                <label for="subject">Subject Line *</label>
                                <input type="text" id="subject" name="subject" class="form-control" required 
                                       placeholder="e.g., Guest Post Collaboration Opportunity - {DOMAIN}">
                                <small class="help-text">Use variables like {DOMAIN} for personalization</small>
                            </div>

                            <div class="form-group">
                                <label for="body">Email Body *</label>
                                <textarea id="body" name="body" class="form-control" style="min-height: 300px;" required 
                                          placeholder="Write your email template here...

Use variables for personalization:
{DOMAIN} - Target domain
{SENDER_NAME} - Your name
{TOPIC_AREA} - Relevant topic
{INDUSTRY} - Industry focus"></textarea>
                                <small class="help-text">Use variables like {DOMAIN}, {SENDER_NAME}, etc. for personalization</small>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" id="is_default" name="is_default" value="1">
                                    <label for="is_default">Set as default template</label>
                                </div>
                                <small class="help-text">The default template will be used automatically for new campaigns</small>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Create Template
                                </button>
                                <a href="templates.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'edit' && $currentTemplate): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-edit"></i> Edit Email Template</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="templates.php?action=update&id=<?php echo $currentTemplate['id']; ?>">
                            <div class="form-group">
                                <label for="name">Template Name *</label>
                                <input type="text" id="name" name="name" class="form-control" required 
                                       value="<?php echo htmlspecialchars($currentTemplate['name']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="subject">Subject Line *</label>
                                <input type="text" id="subject" name="subject" class="form-control" required 
                                       value="<?php echo htmlspecialchars($currentTemplate['subject']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="body">Email Body *</label>
                                <textarea id="body" name="body" class="form-control" style="min-height: 300px;" required><?php echo htmlspecialchars($currentTemplate['body']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" id="is_default" name="is_default" value="1" 
                                           <?php echo $currentTemplate['is_default'] ? 'checked' : ''; ?>>
                                    <label for="is_default">Set as default template</label>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Template
                                </button>
                                <a href="templates.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'preview' && $currentTemplate): ?>
                <?php
                $sampleDomain = 'example-blog.com';
                $sampleEmail = 'editor@example-blog.com';
                $personalizedSubject = $emailTemplate->personalizeTemplate($currentTemplate['subject'], $sampleDomain, $sampleEmail);
                $personalizedBody = $emailTemplate->personalizeTemplate($currentTemplate['body'], $sampleDomain, $sampleEmail);
                ?>
                <div class="content-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-file-text"></i> Template Source</h3>
                        </div>
                        <div class="card-body">
                            <div class="template-source">
                                <div class="field-group">
                                    <strong>Subject:</strong>
                                    <div class="code-block"><?php echo htmlspecialchars($currentTemplate['subject']); ?></div>
                                </div>
                                <div class="field-group">
                                    <strong>Body:</strong>
                                    <div class="code-block"><?php echo nl2br(htmlspecialchars($currentTemplate['body'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-eye"></i> Preview (Personalized)</h3>
                            <small>Sample domain: <?php echo $sampleDomain; ?></small>
                        </div>
                        <div class="card-body">
                            <div class="email-preview">
                                <div class="email-header">
                                    <div class="email-field">
                                        <strong>To:</strong> <?php echo $sampleEmail; ?>
                                    </div>
                                    <div class="email-field">
                                        <strong>Subject:</strong> <?php echo htmlspecialchars($personalizedSubject); ?>
                                    </div>
                                </div>
                                <div class="email-body">
                                    <?php echo nl2br(htmlspecialchars($personalizedBody)); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3><i class="fas fa-info"></i> Template Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="template-info-grid">
                            <div class="info-item">
                                <strong>Name:</strong> <?php echo htmlspecialchars($currentTemplate['name']); ?>
                            </div>
                            <div class="info-item">
                                <strong>Default Template:</strong> 
                                <?php if ($currentTemplate['is_default']): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">No</span>
                                <?php endif; ?>
                            </div>
                            <div class="info-item">
                                <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($currentTemplate['created_at'])); ?>
                            </div>
                            <div class="info-item">
                                <strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($currentTemplate['updated_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="preview-actions mt-3">
                            <a href="templates.php?action=edit&id=<?php echo $currentTemplate['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Edit Template
                            </a>
                            <a href="templates.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Templates
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
</body>
</html>