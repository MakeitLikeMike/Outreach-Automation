// Campaigns page JavaScript functionality

// Show/hide template selection based on automation mode
document.addEventListener('DOMContentLoaded', function() {
    const automationMode = document.getElementById('automation_mode');
    if (automationMode) {
        automationMode.addEventListener('change', function() {
            const templateSelection = document.getElementById('template_selection');
            const emailTemplateId = document.getElementById('email_template_id');
            
            if (this.value === 'template') {
                templateSelection.style.display = 'block';
                emailTemplateId.required = true;
            } else {
                templateSelection.style.display = 'none';
                emailTemplateId.required = false;
            }
        });
    }

    // Form validation for campaign creation
    const createForm = document.querySelector('form[action*="action=create"]');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            const campaignName = document.getElementById('name').value.trim();
            const ownerEmail = document.getElementById('owner_email').value.trim();
            const automationMode = document.getElementById('automation_mode').value;
            
            if (!campaignName) {
                alert('Please enter a campaign name.');
                e.preventDefault();
                return false;
            }
            
            if (!ownerEmail) {
                alert('Please enter an owner email.');
                e.preventDefault();
                return false;
            }
            
            if (!automationMode) {
                alert('Please select an email generation mode.');
                e.preventDefault();
                return false;
            }
        });
    }
});

// Bulk selection functionality
function toggleSelectAll() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.campaign-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateBulkDeleteButton();
}

function updateBulkDeleteButton() {
    const checkboxes = document.querySelectorAll('.campaign-checkbox:checked');
    const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
    
    if (checkboxes.length > 0) {
        bulkDeleteBtn.style.display = 'inline-block';
        bulkDeleteBtn.innerHTML = `<i class="fas fa-trash"></i> Delete Selected (${checkboxes.length})`;
    } else {
        bulkDeleteBtn.style.display = 'none';
    }
    
    // Update select-all checkbox state
    const allCheckboxes = document.querySelectorAll('.campaign-checkbox');
    const selectAll = document.getElementById('select-all');
    
    if (checkboxes.length === allCheckboxes.length) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
    } else if (checkboxes.length > 0) {
        selectAll.checked = false;
        selectAll.indeterminate = true;
    } else {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
}

function handleRowClick(event, campaignId) {
    if (event.target.type === 'checkbox' || event.target.closest('button')) {
        return;
    }
    window.location.href = `campaigns.php?action=view&id=${campaignId}`;
}

function confirmBulkDelete() {
    const checkboxes = document.querySelectorAll('.campaign-checkbox:checked');
    const count = checkboxes.length;
    
    if (count === 0) {
        alert('Please select campaigns to delete.');
        return;
    }
    
    const message = count === 1 
        ? 'Are you sure you want to delete this campaign? This action cannot be undone.'
        : `Are you sure you want to delete these ${count} campaigns? This action cannot be undone.`;
    
    if (confirm(message)) {
        document.getElementById('bulk-delete-form').submit();
    }
}