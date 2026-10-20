<?php
/**
 * AJAX endpoint for putting a parent task on hold or resuming it (issue-012).
 *
 * This is the only legitimate path for a parent with sub-tasks to change its
 * status. The dropdown on task_edit.php is read-only for parents; managers
 * use the buttons on task_detail.php which POST here.
 *
 *   action=hold    — sets tasks.status = 'on_hold' for the parent. Sub-tasks
 *                    are untouched. Sticky: subsequent sub-task changes do
 *                    NOT auto-resume the parent.
 *   action=resume  — sets tasks.status to the value derived from the
 *                    current sub-task statuses (per deriveParentStatus()).
 *
 * Request: application/x-www-form-urlencoded
 *   csrf_token     (required)
 *   task_id        (required, int)
 *   action         (required, 'hold' | 'resume')
 *   on_hold_reason (required, non-empty trimmed, only when action='hold')
 *
 * Response: text/html (200/302) for browser-driven flow; JSON for AJAX.
 *
 * Errors:
 *   400 invalid CSRF, invalid action, non-parent target, missing on-hold reason
 *   403 task not found or not owned by the current user
 *   404 task has no sub-tasks (only parents with children are valid targets)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$is_ajax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

function send_error($is_ajax, $code, $message, $redirect_to = null) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    http_response_code($code);
    if ($redirect_to) {
        header('Location: ' . $redirect_to);
    } else {
        echo $message;
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error($is_ajax, 405, 'Method not allowed');
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrf_token)) {
    send_error($is_ajax, 400, 'Invalid form token. Please reload the page and try again.',
        'tasks.php');
}

$task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$action = $_POST['action'] ?? '';

if ($task_id <= 0) {
    send_error($is_ajax, 400, 'Task ID is required.', 'tasks.php');
}
if (!in_array($action, ['hold', 'resume'], true)) {
    send_error($is_ajax, 400, 'Invalid action.', 'tasks.php');
}

$db = Database::getInstance();
$user_id = getCurrentUserId();

$task = $db->fetchOne(
    "SELECT t.id, t.parent_task_id, t.status, t.project_id, p.responsible_person_id
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     WHERE t.id = ?",
    [$task_id]
);

if (!$task || (int)$task['responsible_person_id'] !== (int)$user_id) {
    send_error($is_ajax, 403, 'You are not allowed to change the hold state of this task.');
}

if ($task['parent_task_id'] !== null) {
    send_error($is_ajax, 400, 'Only top-level parent tasks can be put on hold.',
        'task_detail.php?id=' . $task_id);
}

$child_count = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM tasks WHERE parent_task_id = ?",
    [$task_id]
)['c'] ?? 0);

if ($child_count === 0) {
    send_error($is_ajax, 404, 'This task has no sub-tasks, so the hold action does not apply.',
        'task_detail.php?id=' . $task_id);
}

// Issue-034 v1.8.0: the on-hold path requires a non-empty trimmed
// reason. The page-form on task_detail.php uses `required` on the
// `<input>` but we re-validate server-side so a forged POST cannot
// bypass it. The reason is stored verbatim (already trimmed).
$on_hold_reason = '';
if ($action === 'hold') {
    $on_hold_reason = isset($_POST['on_hold_reason']) && is_string($_POST['on_hold_reason'])
        ? trim($_POST['on_hold_reason'])
        : '';
    if ($on_hold_reason === '') {
        send_error($is_ajax, 400, 'On-hold reason is required when putting a task on hold.',
            'task_detail.php?id=' . $task_id);
    }
    if (strlen($on_hold_reason) > 2000) {
        send_error($is_ajax, 400, 'On-hold reason must be 2000 characters or less.',
            'task_detail.php?id=' . $task_id);
    }
}

if ($action === 'hold') {
    $db->execute(
        "UPDATE tasks SET status = 'on_hold', on_hold_reason = ?, updated_at = NOW() WHERE id = ?",
        [$on_hold_reason, $task_id]
    );
} else {
    // resume: derive the new status from current sub-task statuses and
    // clear the on-hold reason. Issue-034 v1.8.0: reason must NOT survive
    // a resume — the row was un-held, so the previous reason is no
    // longer relevant.
    $child_rows = $db->fetchAll(
        "SELECT status FROM tasks WHERE parent_task_id = ?",
        [$task_id]
    );
    $child_statuses = array_map(
        fn($r) => $r['status'] ?? 'not_started',
        $child_rows
    );
    $new_status = deriveParentStatus(null, $child_statuses);
    $set_completion_date = '';
    $params = [$new_status, $task_id];
    if ($new_status === 'completed') {
        $set_completion_date = ', completion_date = COALESCE(completion_date, NOW())';
    } elseif ($task['status'] === 'completed') {
        $set_completion_date = ', completion_date = NULL';
    }
    $db->execute(
        "UPDATE tasks SET status = ?, on_hold_reason = NULL, updated_at = NOW() $set_completion_date WHERE id = ?",
        $params
    );
}

syncProjectTaskCompletions($db, $task['project_id']);

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'status' => $action === 'hold' ? 'on_hold' : 'derived']);
    exit;
}

header('Location: task_detail.php?id=' . $task_id);
exit;
