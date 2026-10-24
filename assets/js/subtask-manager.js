// Subtask Management Utilities
class SubtaskManager {
    constructor() {
        this.bindEvents();
    }
    
    bindEvents() {
        // Bind progress update events
        document.addEventListener('change', (e) => {
            if (e.target.matches('.subtask-progress-input')) {
                this.updateSubtaskProgress(e.target);
            }
        });

        // Bind status change events
        document.addEventListener('change', (e) => {
            if (e.target.matches('.subtask-status-select')) {
                this.updateSubtaskStatus(e.target);
            }
        });

        // Issue-034 v1.8.0 — inline reason editor on the
        // task_detail.php sidebar. Each `[data-save-reason]` button is
        // paired with a sibling `[data-update-reason]` textarea and a
        // `[data-save-status]` placeholder for the response message.
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-save-reason]');
            if (!btn) return;
            e.preventDefault();
            this.saveInlineReason(btn);
        });

        // Initialize progress circles
        this.initializeProgressCircles();
    }
    
    // Update subtask progress via AJAX
    async updateSubtaskProgress(input) {
        const taskId = input.dataset.taskId;
        const progress = parseFloat(input.value);
        
        try {
            const response = await fetch('/ajax/update_task_progress.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    task_id: taskId,
                    completion_percentage: progress
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Update progress bar
                this.updateProgressBar(taskId, progress);
                
                // Update parent task progress if needed
                if (result.parent_progress !== undefined) {
                    this.updateProgressBar(result.parent_task_id, result.parent_progress);
                }
                
                // Show success message
                this.showNotification('Progress updated successfully', 'success');
            } else {
                this.showNotification('Failed to update progress: ' + result.error, 'error');
                // Reset input to previous value
                input.value = input.dataset.previousValue || 0;
            }
        } catch (error) {
            console.error('Error updating progress:', error);
            this.showNotification('Network error occurred', 'error');
            // Reset input to previous value
            input.value = input.dataset.previousValue || 0;
        }
    }
    
    // Update subtask status
    async updateSubtaskStatus(select) {
        const taskId = select.dataset.taskId;
        const status = select.value;

        // Issue-034 v1.8.0: when moving a task into 'on_hold' the spec
        // requires a non-empty reason. The leaf-row dropdown has no
        // inline editor, so we prompt synchronously. Server-side also
        // rejects empty reasons for defense in depth.
        let onHoldReason = '';
        if (status === 'on_hold') {
            onHoldReason = (window.prompt('Why is this task going on hold? (required)', '') || '').trim();
            if (onHoldReason === '') {
                select.value = select.dataset.previousValue || 'not_started';
                this.showNotification('On-hold reason is required to change status to On Hold.', 'error');
                return;
            }
        }

        try {
            const response = await fetch('/ajax/update_task_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    task_id: taskId,
                    status: status,
                    on_hold_reason: onHoldReason
                })
            });

            const result = await response.json();

            if (result.success) {
                // Update status indicator
                this.updateStatusIndicator(taskId, status);

                // If completed, set progress to 100%
                if (status === 'completed') {
                    this.updateProgressBar(taskId, 100);
                    const progressInput = document.querySelector(`[data-task-id="${taskId}"].subtask-progress-input`);
                    if (progressInput) {
                        progressInput.value = 100;
                    }
                }

                // Update parent task progress if needed
                if (result.parent_progress !== undefined) {
                    this.updateProgressBar(result.parent_task_id, result.parent_progress);
                }

                // On task_detail.php reload so the new reason chip /
                // sidebar card appear. The dedicated inline editor
                // covers the no-reload path on that same page.
                if (window.location.pathname.includes('task_detail.php')) {
                    window.location.reload();
                    return;
                }

                this.showNotification('Status updated successfully', 'success');
            } else {
                if (result.field === 'on_hold_reason') {
                    this.showNotification(result.error || 'On-hold reason is required.', 'error');
                } else {
                    this.showNotification('Failed to update status: ' + result.error, 'error');
                }
                select.value = select.dataset.previousValue || 'not_started';
            }
        } catch (error) {
            console.error('Error updating status:', error);
            this.showNotification('Network error occurred', 'error');
            // Reset select to previous value
            select.value = select.dataset.previousValue || 'not_started';
        }
    }

    // Issue-034 v1.8.0 — save a status reason from the inline editor
    // on task_detail.php. Pairs with [data-save-reason] /
    // [data-update-reason] / [data-save-status] markup rendered in PHP.
    async saveInlineReason(btn) {
        const taskId = parseInt(btn.dataset.taskId, 10) || 0;
        const field = btn.dataset.field;
        const card = btn.closest('.task-reason-card') || document;
        const textarea = card.querySelector(`[data-update-reason][data-field="${field}"]`);
        const statusSpan = card.querySelector('[data-save-status]');

        if (!textarea) return;

        const value = textarea.value.trim();
        const csrfTokenEl = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfTokenEl ? csrfTokenEl.getAttribute('content')
            : (document.querySelector('input[name="csrf_token"]')?.value || '');

        if (statusSpan) {
            statusSpan.textContent = 'Saving…';
        }

        try {
            const response = await fetch('/ajax/update_status_reason.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    task_id: taskId,
                    field: field,
                    value: value
                })
            });
            const result = await response.json();

            if (!result.success) {
                if (statusSpan) {
                    statusSpan.textContent = result.error || 'Failed to save.';
                }
                this.showNotification(result.error || 'Failed to save reason.', 'error');
                return;
            }

            const detailItem = btn.closest('.detail-item');
            if (detailItem) {
                detailItem.innerHTML = this.renderReasonBlock(field, result.value);
            }
            this.showNotification('Reason saved.', 'success');
        } catch (error) {
            console.error('Error saving reason:', error);
            if (statusSpan) {
                statusSpan.textContent = 'Network error.';
            }
            this.showNotification('Network error occurred', 'error');
        }
    }

    // Issue-034 v1.8.0 — small renderer for the refreshed chip block
    // so the inline editor can swap populated ⇄ missing without a full
    // page reload. Returns raw HTML for `el.innerHTML = ...`.
    renderReasonBlock(field, value) {
        const safeValue = value == null ? '' : String(value);
        const isEmpty = safeValue === '';
        const kindClass = field === 'on_hold_reason' ? 'on-hold' : 'delay';
        const escape = (s) => s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        const taskId = this.getCurrentTaskId() || '';
        const escapedValue = escape(safeValue);
        if (isEmpty) {
            return `
                <span class="status-reason-required">
                    <i class="fas fa-exclamation-circle"></i>
                    Missing — fill in to explain this to the client.
                </span>
                <details class="reason-inline-edit" open>
                    <summary>Add reason</summary>
                    <textarea data-update-reason data-task-id="${taskId}" data-field="${field}" rows="2" maxlength="2000" placeholder="Add reason..."></textarea>
                    <button type="button" data-save-reason data-task-id="${taskId}" data-field="${field}">Save</button>
                    <span data-save-status aria-live="polite"></span>
                </details>
            `;
        }
        return `
            <span class="task-reason ${kindClass}" style="display:block;margin:0;">${escapedValue}</span>
            <details class="reason-inline-edit">
                <summary>Edit</summary>
                <textarea data-update-reason data-task-id="${taskId}" data-field="${field}" rows="2" maxlength="2000">${escapedValue}</textarea>
                <button type="button" data-save-reason data-task-id="${taskId}" data-field="${field}">Save</button>
                <span data-save-status aria-live="polite"></span>
            </details>
        `;
    }
    
    // Update progress bar visual
    updateProgressBar(taskId, progress) {
        const progressBar = document.querySelector(`[data-task-id="${taskId}"] .progress-fill`);
        const progressText = document.querySelector(`[data-task-id="${taskId}"] .progress-percentage`);
        
        if (progressBar) {
            progressBar.style.width = progress + '%';
        }
        
        if (progressText) {
            progressText.textContent = Math.round(progress) + '%';
        }
        
        // Update progress circle if exists
        this.updateProgressCircle(taskId, progress);
    }
    
    // Update status indicator
    updateStatusIndicator(taskId, status) {
        const statusElement = document.querySelector(`[data-task-id="${taskId}"] .status`);
        
        if (statusElement) {
            // Remove old status classes
            statusElement.classList.remove('status-not_started', 'status-in_progress', 'status-completed', 'status-on_hold');
            
            // Add new status class
            statusElement.classList.add('status-' + status);
            
            // Update text
            statusElement.textContent = status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
        }
    }
    
    // Initialize progress circles
    initializeProgressCircles() {
        const circles = document.querySelectorAll('.progress-circle');
        circles.forEach(circle => {
            const progress = parseFloat(circle.dataset.progress) || 0;
            this.drawProgressCircle(circle, progress);
        });
    }
    
    // Draw progress circle
    drawProgressCircle(circle, progress) {
        const svg = circle.querySelector('svg');
        const progressPath = circle.querySelector('.progress-path');
        
        if (!svg || !progressPath) return;
        
        const radius = 40;
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (progress / 100) * circumference;
        
        progressPath.style.strokeDasharray = circumference;
        progressPath.style.strokeDashoffset = offset;
        
        // Update percentage text
        const percentageText = circle.querySelector('.percentage-text');
        if (percentageText) {
            percentageText.textContent = Math.round(progress) + '%';
        }
    }
    
    // Update progress circle
    updateProgressCircle(taskId, progress) {
        const circle = document.querySelector(`[data-task-id="${taskId}"] .progress-circle`);
        if (circle) {
            circle.dataset.progress = progress;
            this.drawProgressCircle(circle, progress);
        }
    }
    
    // Show notification
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        // Add to notification container or body
        const container = document.getElementById('notifications') || document.body;
        container.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
        
        // Add slide-in animation
        requestAnimationFrame(() => {
            notification.classList.add('notification-show');
        });
    }
    
    // Calculate hierarchical progress
    calculateHierarchicalProgress(subtasks) {
        if (!subtasks || subtasks.length === 0) return 0;
        
        const totalProgress = subtasks.reduce((sum, subtask) => {
            return sum + parseFloat(subtask.completion_percentage || 0);
        }, 0);
        
        return totalProgress / subtasks.length;
    }
    
    // Add new subtask row dynamically
    addSubtaskRow(parentTaskId, subtaskData = null) {
        const subtasksList = document.querySelector('.subtasks-list');
        if (!subtasksList) return;
        
        const subtaskItem = document.createElement('div');
        subtaskItem.className = 'subtask-item';
        subtaskItem.dataset.taskId = subtaskData ? subtaskData.id : 'new';
        
        subtaskItem.innerHTML = `
            <div class="subtask-header">
                <div class="subtask-title">
                    <input type="text" class="subtask-name-input" placeholder="Enter subtask name..." 
                           value="${subtaskData ? subtaskData.name : ''}" required>
                    <div class="subtask-meta">
                        <select class="subtask-status-select" data-task-id="${subtaskData ? subtaskData.id : 'new'}">
                            <option value="not_started" ${!subtaskData || subtaskData.status === 'not_started' ? 'selected' : ''}>Not Started</option>
                            <option value="in_progress" ${subtaskData && subtaskData.status === 'in_progress' ? 'selected' : ''}>In Progress</option>
                            <option value="completed" ${subtaskData && subtaskData.status === 'completed' ? 'selected' : ''}>Completed</option>
                            <option value="on_hold" ${subtaskData && subtaskData.status === 'on_hold' ? 'selected' : ''}>On Hold</option>
                        </select>
                    </div>
                </div>
                <div class="subtask-actions">
                    <button class="btn btn--sm btn--success save-subtask" onclick="subtaskManager.saveSubtask(this)">
                        <i class="fas fa-save"></i>
                    </button>
                    <button class="btn btn--sm btn--danger delete-subtask" onclick="subtaskManager.deleteSubtask(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <div class="subtask-description">
                <textarea class="subtask-description-input" placeholder="Enter subtask description..." rows="2">${subtaskData ? subtaskData.description || '' : ''}</textarea>
            </div>
            <div class="subtask-progress">
                <div class="progress-info">
                    <span class="progress-label">Progress</span>
                    <span class="progress-percentage">${Math.round(subtaskData ? subtaskData.completion_percentage : 0)}%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${subtaskData ? subtaskData.completion_percentage : 0}%"></div>
                </div>
                <input type="range" class="subtask-progress-input" min="0" max="100" step="5" 
                       value="${subtaskData ? subtaskData.completion_percentage : 0}" 
                       data-task-id="${subtaskData ? subtaskData.id : 'new'}">
            </div>
        `;
        
        subtasksList.appendChild(subtaskItem);
        
        // Focus on name input for new subtasks
        if (!subtaskData) {
            const nameInput = subtaskItem.querySelector('.subtask-name-input');
            nameInput.focus();
        }
    }
    
    // Save subtask
    async saveSubtask(button) {
        const subtaskItem = button.closest('.subtask-item');
        const taskId = subtaskItem.dataset.taskId;
        const isNew = taskId === 'new';
        
        const formData = {
            name: subtaskItem.querySelector('.subtask-name-input').value.trim(),
            description: subtaskItem.querySelector('.subtask-description-input').value.trim(),
            status: subtaskItem.querySelector('.subtask-status-select').value,
            completion_percentage: subtaskItem.querySelector('.subtask-progress-input').value
        };
        
        if (!formData.name) {
            this.showNotification('Subtask name is required', 'error');
            return;
        }
        
        try {
            const url = isNew ? '/ajax/create_subtask.php' : '/ajax/update_subtask.php';
            const body = isNew ? 
                { ...formData, parent_task_id: this.getCurrentTaskId() } : 
                { ...formData, task_id: taskId };
            
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (isNew) {
                    subtaskItem.dataset.taskId = result.task_id;
                    subtaskItem.querySelector('.subtask-status-select').dataset.taskId = result.task_id;
                    subtaskItem.querySelector('.subtask-progress-input').dataset.taskId = result.task_id;
                }
                
                this.showNotification('Subtask saved successfully', 'success');
            } else {
                this.showNotification('Failed to save subtask: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Error saving subtask:', error);
            this.showNotification('Network error occurred', 'error');
        }
    }
    
    // Delete subtask
    async deleteSubtask(button) {
        const subtaskItem = button.closest('.subtask-item');
        const taskId = subtaskItem.dataset.taskId;
        
        if (taskId === 'new') {
            subtaskItem.remove();
            return;
        }
        
        if (!confirm('Are you sure you want to delete this subtask?')) {
            return;
        }
        
        try {
            const response = await fetch('/ajax/delete_subtask.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: taskId })
            });
            
            const result = await response.json();
            
            if (result.success) {
                subtaskItem.remove();
                this.showNotification('Subtask deleted successfully', 'success');
            } else {
                this.showNotification('Failed to delete subtask: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Error deleting subtask:', error);
            this.showNotification('Network error occurred', 'error');
        }
    }
    
    // Get current task ID from page
    getCurrentTaskId() {
        const match = window.location.pathname.match(/task_detail\.php\?id=(\d+)/);
        return match ? match[1] : new URLSearchParams(window.location.search).get('id');
    }
}

// Initialize subtask manager when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.subtaskManager = new SubtaskManager();
});

// CSS for notifications (inject into head)
const notificationStyles = `
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        padding: 15px 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        z-index: 1000;
        transform: translateX(400px);
        transition: transform 0.3s ease;
        min-width: 300px;
        max-width: 500px;
    }
    
    .notification-show {
        transform: translateX(0);
    }
    
    .notification-success {
        border-left: 4px solid #28a745;
    }
    
    .notification-error {
        border-left: 4px solid #dc3545;
    }
    
    .notification-info {
        border-left: 4px solid #17a2b8;
    }
    
    .notification-content {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
    }
    
    .notification-success .notification-content i {
        color: #28a745;
    }
    
    .notification-error .notification-content i {
        color: #dc3545;
    }
    
    .notification-info .notification-content i {
        color: #17a2b8;
    }
    
    .notification-close {
        background: none;
        border: none;
        color: #666;
        cursor: pointer;
        padding: 5px;
        border-radius: 4px;
    }
    
    .notification-close:hover {
        background: #f8f9fa;
    }
    
    .progress-circle {
        position: relative;
        width: 90px;
        height: 90px;
    }
    
    .progress-circle svg {
        width: 100%;
        height: 100%;
        transform: rotate(-90deg);
    }
    
    .progress-circle .background-path {
        fill: none;
        stroke: #e9ecef;
        stroke-width: 6;
    }
    
    .progress-circle .progress-path {
        fill: none;
        stroke: #007bff;
        stroke-width: 6;
        stroke-linecap: round;
        transition: stroke-dashoffset 0.5s ease;
    }
    
    .progress-circle .percentage-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-weight: 600;
        font-size: 14px;
        color: #333;
    }
`;

// Inject styles
const styleElement = document.createElement('style');
styleElement.textContent = notificationStyles;
document.head.appendChild(styleElement);