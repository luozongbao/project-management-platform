<?php
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$task_id = $input['task_id'] ?? null;
$status = $input['status'] ?? null;

// Issue-034 v1.8.0: optional on_hold_reason. Required (non-empty
// trimmed) when the new status is 'on_hold'. Persisted verbatim on
// success; cleared (set NULL) on any other status. The field is
// surfaced back to the caller in the response so the JS controller can
// decide whether to render the chip or the "missing" mark.
$posted_on_hold_reason = isset($input['on_hold_reason']) && is_string($input['on_hold_reason'])
    ? trim($input['on_hold_reason'])
    : '';
if ($posted_on_hold_reason !== '' && strlen($posted_on_hold_reason) > 2000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'On-hold reason must be 2000 characters or less.',
        'field'   => 'on_hold_reason',
    ]);
    exit;
}

if (!$task_id) {
    echo json_encode(['success' => false, 'error' => 'Task ID is required']);
    exit;
}

$valid_statuses = ['not_started', 'in_progress', 'completed', 'on_hold'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

// Issue-034 v1.8.0: enforce non-empty trimmed reason when going TO
// on_hold. Returning HTTP 400 with a `field` key so the front-end
// controller can focus the offending input.
if ($status === 'on_hold' && $posted_on_hold_reason === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'An on-hold reason is required when status is On Hold.',
        'field'   => 'on_hold_reason',
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $user_id = getCurrentUserId();
    
    // Verify that the user has access to this task
    $task = $db->fetchOne(
        "SELECT t.id, t.parent_task_id, t.project_id, t.completion_percentage, p.responsible_person_id
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.id = ?",
        [$task_id]
    );
    
    if (!$task || $task['responsible_person_id'] != $user_id) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Determine completion percentage and completion date based on status
    $completion_percentage = $task['completion_percentage'];
    $completion_date = null;
    
    if ($status === 'completed') {
        $completion_percentage = 100;
        // Issue-055: completion_date is a SQL DATE column — a timestamp would
        // be silently truncated by MariaDB, so store day precision to match
        // task_edit.php.
        $completion_date = date('Y-m-d');
    } elseif ($status === 'not_started') {
        $completion_percentage = 0;
    }
    
    // Update the task status and related fields. Issue-034 v1.8.0:
    //   * status === 'on_hold'  → store the trimmed reason.
    //   * any other status      → clear on_hold_reason (NULL).
    //   * status !== completed AND row is delayed now → keep delay_reason
    //     (we don't touch it on a status flip; the inline reason editor
    //     owns the delay_reason column to keep this endpoint narrow).
    $stored_on_hold_reason = $status === 'on_hold' ? $posted_on_hold_reason : null;
    $db->execute(
        "UPDATE tasks SET 
            status = ?, 
            completion_percentage = ?, 
            completion_date = ?,
            on_hold_reason = ?,
            updated_at = NOW() 
         WHERE id = ?",
        [$status, $completion_percentage, $completion_date, $stored_on_hold_reason, $task_id]
    );

    $completionMap = syncProjectTaskCompletions($db, $task['project_id']);
    $response = [
        'success' => true,
        'task_progress' => $completionMap['task_completion'][(int)$task_id] ?? 0,
        'status'        => $status,
        'on_hold_reason' => $status === 'on_hold' ? $stored_on_hold_reason : null,
    ];
    if ($task['parent_task_id']) {
        $response['parent_task_id'] = $task['parent_task_id'];
        $response['parent_progress'] = $completionMap['task_completion'][(int)$task['parent_task_id']] ?? 0;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    // Issue-016: log the underlying error server-side but do NOT
    // echo it to the client (info disclosure).
    error_log("Task status update failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to update the task status. Please try again.']);
}
?>