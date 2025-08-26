// Main JavaScript for Outreach Automation System

document.addEventListener('DOMContentLoaded', function() {
    // Add entrance animations to cards
    const cards = document.querySelectorAll('.stat-card, .card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Add smooth hover effects to navigation
    const navLinks = document.querySelectorAll('.nav-menu a');
    navLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(8px)';
        });
        
        link.addEventListener('mouseleave', function() {
            if (!this.classList.contains('active')) {
                this.style.transform = 'translateX(0)';
            }
        });
    });

    // Enhanced stat card animations
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
    // Mobile menu toggle
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.querySelector('.menu-toggle');
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
});

// Universal back function (removed duplicate)

// Enhanced go back with specific fallback
function goBackTo(fallbackUrl) {
    if (window.history.length > 1 && document.referrer) {
        window.history.back();
    } else {
        window.location.href = fallbackUrl;
    }
}

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Table row hover effects
    const tableRows = document.querySelectorAll('.table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8fafc';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    // Smooth scrolling for anchor links
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    anchorLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Form validation with duplicate submission prevention
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        let isSubmitting = false;
        
        form.addEventListener('submit', function(e) {
            // Prevent double submissions
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }

            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = '#ef4444';
                    isValid = false;
                } else {
                    field.style.borderColor = '#d1d5db';
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }

            // Mark as submitting and disable submit buttons
            isSubmitting = true;
            const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitButtons.forEach(btn => {
                btn.disabled = true;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                
                // Re-enable if form submission fails
                setTimeout(() => {
                    if (isSubmitting) {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        isSubmitting = false;
                    }
                }, 30000); // Reset after 30 seconds
            });
        });
    });

    // Auto-resize textareas
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });

    // Confirmation dialogs for delete actions
    const deleteLinks = document.querySelectorAll('a[href*="delete"]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // Search functionality for tables
    const searchInputs = document.querySelectorAll('.table-search');
    searchInputs.forEach(input => {
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = this.closest('.card').querySelector('table');
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // Copy to clipboard functionality
    const copyButtons = document.querySelectorAll('.copy-btn');
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const target = document.querySelector(this.getAttribute('data-target'));
            if (target) {
                navigator.clipboard.writeText(target.textContent).then(() => {
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = originalText;
                    }, 2000);
                });
            }
        });
    });

    // Status update functionality
    const statusSelects = document.querySelectorAll('select[name="status"]');
    statusSelects.forEach(select => {
        select.addEventListener('change', function() {
            const form = this.closest('form');
            if (form && confirm('Update status immediately?')) {
                form.submit();
            }
        });
    });

    // Load more functionality for paginated content
    const loadMoreBtns = document.querySelectorAll('.load-more');
    loadMoreBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            const container = this.getAttribute('data-container');
            
            fetch(url)
                .then(response => response.text())
                .then(data => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(data, 'text/html');
                    const newContent = doc.querySelector(container);
                    
                    if (newContent) {
                        document.querySelector(container).appendChild(newContent);
                    }
                })
                .catch(error => {
                    console.error('Error loading more content:', error);
                });
        });
    });

    // Real-time form validation
    const emailInputs = document.querySelectorAll('input[type="email"]');
    emailInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (this.value && !emailRegex.test(this.value)) {
                this.style.borderColor = '#ef4444';
                showFieldError(this, 'Please enter a valid email address');
            } else {
                this.style.borderColor = '#d1d5db';
                hideFieldError(this);
            }
        });
    });

    // URL validation
    const urlInputs = document.querySelectorAll('input[type="url"], input[data-type="url"]');
    urlInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value && !isValidUrl(this.value)) {
                this.style.borderColor = '#ef4444';
                showFieldError(this, 'Please enter a valid URL');
            } else {
                this.style.borderColor = '#d1d5db';
                hideFieldError(this);
            }
        });
    });
});

// Utility functions
function showFieldError(field, message) {
    hideFieldError(field);
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.textContent = message;
    errorDiv.style.color = '#ef4444';
    errorDiv.style.fontSize = '0.875rem';
    errorDiv.style.marginTop = '0.25rem';
    field.parentNode.appendChild(errorDiv);
}

function hideFieldError(field) {
    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
}

function isValidUrl(string) {
    try {
        new URL(string);
        return true;
    } catch (_) {
        return false;
    }
}

// API call helper with debug support
async function apiCall(endpoint, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        }
    };

    if (data) {
        options.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(endpoint, options);
        
        // First get the response as text to debug JSON parsing issues
        const responseText = await response.text();
        
        // Try to parse as JSON
        try {
            return JSON.parse(responseText);
        } catch (jsonError) {
            console.error('JSON parsing failed:', jsonError);
            console.error('Response text:', responseText);
            console.error('Response headers:', [...response.headers.entries()]);
            
            // Return a structured error response
            return {
                success: false,
                error: 'Invalid JSON response from server',
                debug: {
                    responseText: responseText,
                    parseError: jsonError.message
                }
            };
        }
    } catch (error) {
        console.error('API call failed:', error);
        throw error;
    }
}

// Show loading state
function showLoading(element) {
    element.disabled = true;
    element.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
}

function hideLoading(element, originalHTML) {
    element.disabled = false;
    element.innerHTML = originalHTML;
}

// Toast notification system
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem;
        border-radius: 8px;
        color: white;
        z-index: 1000;
        opacity: 0;
        transition: opacity 0.3s ease;
    `;

    switch (type) {
        case 'success':
            toast.style.backgroundColor = '#10b981';
            break;
        case 'error':
            toast.style.backgroundColor = '#ef4444';
            break;
        case 'warning':
            toast.style.backgroundColor = '#f59e0b';
            break;
        default:
            toast.style.backgroundColor = '#3b82f6';
    }

    document.body.appendChild(toast);
    
    setTimeout(() => toast.style.opacity = '1', 100);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// Back Button functionality with smart navigation
function goBack() {
    try {
        // Simple approach: try browser back first, then fallback
        if (window.history.length > 1) {
            window.history.back();
            
            // Set a timeout to check if back navigation worked
            // If page doesn't change in 100ms, use fallback
            setTimeout(() => {
                // This will only execute if history.back() failed
                const currentPath = window.location.pathname.toLowerCase();
                
                if (currentPath.includes('campaign')) {
                    window.location.href = 'campaigns.php';
                } else if (currentPath.includes('domain')) {
                    window.location.href = 'domains.php';  
                } else if (currentPath.includes('template')) {
                    window.location.href = 'templates.php';
                } else if (currentPath.includes('monitoring')) {
                    window.location.href = 'monitoring.php';
                } else if (currentPath.includes('setting')) {
                    window.location.href = 'settings.php';
                } else {
                    // Default fallback to dashboard
                    window.location.href = 'index.php';
                }
            }, 100);
        } else {
            // No history available, use immediate fallback
            const currentPath = window.location.pathname.toLowerCase();
            
            if (currentPath.includes('campaign')) {
                window.location.href = 'campaigns.php';
            } else if (currentPath.includes('domain')) {
                window.location.href = 'domains.php';  
            } else if (currentPath.includes('template')) {
                window.location.href = 'templates.php';
            } else if (currentPath.includes('monitoring')) {
                window.location.href = 'monitoring.php';
            } else if (currentPath.includes('setting')) {
                window.location.href = 'settings.php';
            } else {
                // Default fallback to dashboard
                window.location.href = 'index.php';
            }
        }
    } catch (error) {
        console.warn('Back navigation error:', error);
        // Final fallback to dashboard
        window.location.href = 'index.php';
    }
}

// Add keyboard shortcut for back button (Alt + Left Arrow)
document.addEventListener('keydown', function(e) {
    if (e.altKey && e.key === 'ArrowLeft') {
        e.preventDefault();
        goBack();
    }
});