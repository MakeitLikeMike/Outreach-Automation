// Monitoring page JavaScript functionality

function updateDateRange(days) {
    const url = new URL(window.location);
    url.searchParams.set('date_range', days);
    window.location = url;
}

// Modal functionality
function showModal(title, content) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = content;
    document.getElementById('detailsModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('detailsModal');
    if (event.target == modal) {
        closeModal();
    }
}

// Monitoring page interactivity functions
function showDetailedCampaignAnalytics() {
    fetch('api/monitoring_data.php?type=campaign_analytics')
        .then(response => response.json())
        .then(data => {
            let content = '<div class="campaign-analytics-clean">';
            
            if (data.campaigns && data.campaigns.length > 0) {
                content += '<div class="campaigns-performance-list">';
                
                data.campaigns.forEach(campaign => {
                    const responseRate = campaign.emails_sent > 0 ? 
                        Math.round((campaign.replies_received / campaign.emails_sent) * 100) : 0;
                        
                    content += `
                        <div class="campaign-performance-row clickable-row" onclick="viewCampaignDetails(${campaign.id})">
                            <div class="campaign-info-section">
                                <h4>${campaign.name}</h4>
                                <span class="status status-${campaign.status}">${campaign.status}</span>
                            </div>
                            <div class="performance-metrics-grid">
                                <div class="metric-item">
                                    <span class="value">${campaign.total_domains}</span>
                                    <span class="label">Domains</span>
                                </div>
                                <div class="metric-item">
                                    <span class="value">${campaign.emails_sent}</span>
                                    <span class="label">Sent</span>
                                </div>
                                <div class="metric-item">
                                    <span class="value">${campaign.replies_received}</span>
                                    <span class="label">Replies</span>
                                </div>
                                <div class="metric-item">
                                    <span class="value">${responseRate}%</span>
                                    <span class="label">Response Rate</span>
                                </div>
                            </div>
                            <div class="response-indicator">
                                <div class="response-bar-clean">
                                    <div class="response-fill" style="width: ${responseRate}%"></div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                content += '</div>';
            } else {
                content += '<div class="empty-state"><i class="fas fa-bullhorn"></i><p>No campaign data available.</p></div>';
            }
            
            content += '</div>';
            showModal('Campaign Analytics', content);
        })
        .catch(error => {
            showModal('Campaign Analytics', '<div class="error">Error loading campaign analytics.</div>');
        });
}

function showDetailedEmailsSent(dateRange) {
    fetch(`api/monitoring_data.php?type=emails_sent&date_range=${dateRange}&page=1&limit=50`)
        .then(response => response.json())
        .then(data => {
            let content = '<div class="emails-sent-detail">';
            
            if (data.emails && data.emails.length > 0) {
                content += `
                    <div class="email-stats">
                        <div class="stat"><span class="value">${data.total}</span><span class="label">Total Sent</span></div>
                        <div class="stat"><span class="value">${data.delivered}</span><span class="label">Delivered</span></div>
                        <div class="stat"><span class="value">${data.bounced}</span><span class="label">Bounced</span></div>
                        <div class="stat"><span class="value">${data.pending}</span><span class="label">Pending</span></div>
                    </div>
                    <div class="emails-list">
                `;
                
                data.emails.forEach(email => {
                    content += `
                        <div class="email-sent-item">
                            <div class="email-header">
                                <h4>${email.subject || 'No Subject'}</h4>
                                <span class="email-date">${email.sent_at}</span>
                            </div>
                            <div class="email-details">
                                <div class="email-info">
                                    ${email.sender_email ? `<span><strong>From:</strong> ${email.sender_email}</span>` : ''}
                                    <span><strong>To:</strong> ${email.recipient_email}</span>
                                    <span><strong>Campaign:</strong> ${email.campaign_name}</span>
                                    <span><strong>Domain:</strong> ${email.domain}</span>
                                </div>
                                <div class="email-status">
                                    <span class="status status-${email.status}">${email.status}</span>
                                    <span class="delivery-status">${email.delivery_status || 'Unknown'}</span>
                                </div>
                            </div>
                            ${email.thread_id ? `<button class="btn btn-sm btn-outline" onclick="viewEmailThread('${email.thread_id}')">View Thread</button>` : ''}
                        </div>
                    `;
                });
                
                content += `
                    </div>
                    <div class="pagination">
                        <button class="btn btn-sm" onclick="loadEmailsSentPage(${dateRange}, 1)" ${data.page <= 1 ? 'disabled' : ''}>Previous</button>
                        <span>Page ${data.page} of ${data.total_pages}</span>
                        <button class="btn btn-sm" onclick="loadEmailsSentPage(${dateRange}, ${data.page + 1})" ${data.page >= data.total_pages ? 'disabled' : ''}>Next</button>
                    </div>
                `;
            } else {
                content += '<div class="empty-state"><i class="fas fa-paper-plane"></i><p>No emails sent in the selected period.</p></div>';
            }
            
            content += '</div>';
            showModal('Emails Sent - Detailed View', content);
        })
        .catch(error => {
            showModal('Emails Sent', '<div class="error">Error loading emails sent data.</div>');
        });
}

function showDetailedRepliesReceived(dateRange) {
    fetch(`api/monitoring_data.php?type=replies_received&date_range=${dateRange}&page=1&limit=50`)
        .then(response => response.json())
        .then(data => {
            let content = '<div class="replies-received-detail">';
            
            if (data.replies && data.replies.length > 0) {
                content += `
                    <div class="reply-stats">
                        <div class="stat"><span class="value">${data.total}</span><span class="label">Total Replies</span></div>
                        <div class="stat"><span class="value">${data.interested}</span><span class="label">Interested</span></div>
                        <div class="stat"><span class="value">${data.not_interested}</span><span class="label">Not Interested</span></div>
                        <div class="stat"><span class="value">${data.unclassified}</span><span class="label">Unclassified</span></div>
                    </div>
                    <div class="replies-list">
                `;
                
                data.replies.forEach(reply => {
                    const classificationColor = {
                        'interested': 'success',
                        'not_interested': 'danger',
                        'neutral': 'warning'
                    };
                    
                    content += `
                        <div class="reply-item">
                            <div class="reply-header">
                                <h4>Re: ${reply.original_subject || 'No Subject'}</h4>
                                <span class="reply-date">${reply.replied_at}</span>
                            </div>
                            <div class="reply-details">
                                <div class="reply-info">
                                    <span><strong>From:</strong> ${reply.from_email}</span>
                                    <span><strong>Campaign:</strong> ${reply.campaign_name}</span>
                                    <span><strong>Domain:</strong> ${reply.domain}</span>
                                </div>
                                <div class="reply-classification">
                                    <span class="classification classification-${classificationColor[reply.reply_classification] || 'secondary'}">
                                        ${reply.reply_classification || 'Unclassified'}
                                    </span>
                                    ${reply.confidence_score ? `<span class="confidence">${Math.round(reply.confidence_score * 100)}% confidence</span>` : ''}
                                </div>
                            </div>
                            <div class="reply-preview">
                                <p>${reply.reply_snippet || 'No preview available'}</p>
                            </div>
                            <div class="reply-actions">
                                <button class="btn btn-sm btn-outline" onclick="viewFullReply('${reply.id}')">View Full Reply</button>
                                ${reply.reply_classification === 'interested' ? `<button class="btn btn-sm btn-success" onclick="viewForwardStatus('${reply.id}')">Forward Status</button>` : ''}
                            </div>
                        </div>
                    `;
                });
                
                content += `
                    </div>
                    <div class="pagination">
                        <button class="btn btn-sm" onclick="loadRepliesPage(${dateRange}, 1)" ${data.page <= 1 ? 'disabled' : ''}>Previous</button>
                        <span>Page ${data.page} of ${data.total_pages}</span>
                        <button class="btn btn-sm" onclick="loadRepliesPage(${dateRange}, ${data.page + 1})" ${data.page >= data.total_pages ? 'disabled' : ''}>Next</button>
                    </div>
                `;
            } else {
                content += '<div class="empty-state"><i class="fas fa-reply"></i><p>No replies received in the selected period.</p></div>';
            }
            
            content += '</div>';
            showModal('Replies Received - Detailed View', content);
        })
        .catch(error => {
            showModal('Replies Received', '<div class="error">Error loading replies data.</div>');
        });
}

function showResponseRateAnalytics(dateRange) {
    fetch(`api/monitoring_data.php?type=response_rate&date_range=${dateRange}`)
        .then(response => response.json())
        .then(data => {
            let content = '<div class="response-rate-analytics">';
            
            content += `
                <div class="rate-filters-enhanced">
                    <div class="filter-section">
                        <label class="filter-label">
                            <i class="fas fa-clock"></i> Time Range
                        </label>
                        <div class="custom-select-wrapper">
                            <select class="custom-select" onchange="updateResponseRateFilter(this.value)">
                                <option value="7" ${dateRange == 7 ? 'selected' : ''}>Last 7 days</option>
                                <option value="30" ${dateRange == 30 ? 'selected' : ''}>Last 30 days</option>
                                <option value="90" ${dateRange == 90 ? 'selected' : ''}>Last 90 days</option>
                                <option value="180" ${dateRange == 180 ? 'selected' : ''}>Last 6 months</option>
                                <option value="365" ${dateRange == 365 ? 'selected' : ''}>Last year</option>
                            </select>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </div>
                    </div>
                </div>
                
                <div class="overall-stats">
                    <div class="stat"><span class="value">${data.overall.total_sent}</span><span class="label">Total Emails Sent</span></div>
                    <div class="stat"><span class="value">${data.overall.total_replies}</span><span class="label">Total Replies</span></div>
                    <div class="stat"><span class="value">${data.overall.response_rate}%</span><span class="label">Overall Response Rate</span></div>
                    <div class="stat"><span class="value">${data.overall.interest_rate}%</span><span class="label">Interest Rate</span></div>
                </div>
            `;
            
            if (data.by_campaign && data.by_campaign.length > 0) {
                content += '<h4>Response Rate by Campaign</h4><div class="campaign-response-rates">';
                
                data.by_campaign.forEach(campaign => {
                    content += `
                        <div class="campaign-rate-item">
                            <div class="campaign-info">
                                <h5>${campaign.campaign_name}</h5>
                                <span class="rate-value">${campaign.response_rate}%</span>
                            </div>
                            <div class="rate-details">
                                <span>${campaign.emails_sent} sent, ${campaign.replies} replies</span>
                                <div class="rate-bar">
                                    <div class="rate-fill" style="width: ${campaign.response_rate}%"></div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                content += '</div>';
            }
            
            if (data.daily_trends && data.daily_trends.length > 0) {
                content += '<h4>Daily Response Trends</h4><div class="daily-trends"><canvas id="trendsChart" width="400" height="200"></canvas></div>';
            }
            
            content += '</div>';
            showModal('Response Rate Analytics', content);
            
            // Create trends chart if data exists
            if (data.daily_trends && data.daily_trends.length > 0) {
                setTimeout(() => {
                    const ctx = document.getElementById('trendsChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.daily_trends.map(d => d.date),
                            datasets: [{
                                label: 'Response Rate %',
                                data: data.daily_trends.map(d => d.response_rate),
                                borderColor: '#4facfe',
                                backgroundColor: 'rgba(79, 172, 254, 0.1)',
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100
                                }
                            }
                        }
                    });
                }, 100);
            }
        })
        .catch(error => {
            showModal('Response Rate Analytics', '<div class="error">Error loading response rate analytics.</div>');
        });
}

// Utility functions
function viewCampaignDetails(campaignId) {
    closeModal();
    window.location.href = `campaigns.php?action=view&id=${campaignId}`;
}

function viewEmailThread(threadId) {
    window.open(`email_thread.php?thread=${threadId}`, '_blank', 'width=800,height=600');
}

function viewFullReply(replyId) {
    window.open(`reply_detail.php?id=${replyId}`, '_blank', 'width=800,height=600');
}

function viewForwardStatus(replyId) {
    window.open(`forward_status.php?reply=${replyId}`, '_blank', 'width=600,height=400');
}

function loadEmailsSentPage(dateRange, page) {
    showDetailedEmailsSent(dateRange);
}

function loadRepliesPage(dateRange, page) {
    showDetailedRepliesReceived(dateRange);
}

function updateResponseRateFilter(newRange) {
    showResponseRateAnalytics(newRange);
}

// Initialize email chart on page load
document.addEventListener('DOMContentLoaded', function() {
    const emailChart = document.getElementById('emailChart');
    if (emailChart) {
        // Chart data will be passed from PHP
        window.initEmailChart = function(data) {
            const ctx = emailChart.getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Sent', 'Replied', 'Bounced', 'Pending'],
                    datasets: [{
                        data: data,
                        backgroundColor: ['#4facfe', '#43e97b', '#f093fb', '#e2e8f0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });
        };
    }
});