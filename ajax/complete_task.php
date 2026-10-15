<?php
/**
 * AJAX endpoint for quick task completion
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$task_id = $_POST['task_id'] ?? null;

if (!$task_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Task ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $user_id = getCurrentUserId();
    
    // Verify user has permission to complete this task
    // RECOMMENDATION: For best performance, ensure indexes exist on (project_id, responsible_person_id) in 'projects' and responsible_person_id in 'tasks'.
    $task = $db->fetchOne(
        "SELECT t.*, p.responsible_person_id as project_owner
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.id = ? AND p.responsible_person_id = ?
         UNION
         SELECT t.*, p.responsible_person_id as project_owner
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.id = ? AND t.responsible_person_id = ?",
        [$task_id, $user_id, $task_id, $user_id]
    );
    
    if (!$task) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Task not found or access denied']);
        exit;
    }

    $child_count = $db->fetchOne(
        "SELECT COUNT(*) AS count FROM tasks WHERE parent_task_id = ?",
        [$task_id]
    )['count'] ?? 0;
    if ((int)$child_count > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'This task has subtasks. Complete its subtasks to update its progress.',
        ]);
        exit;
    }
    
    if ($task['status'] === 'completed') {
        echo json_encode(['success' => false, 'message' => 'Task is already completed']);
        exit;
    }
    
    // Update task to completed status
    $completion_date = date('Y-m-d H:i:s');
    $db->execute(
        "UPDATE tasks SET 
         status = 'completed',
         completion_percentage = 100,
         completion_date = ?,
         updated_at = NOW()
         WHERE id = ?",
        [$completion_date, $task_id]
    );
    
    // If this is a subtask, update parent task completion percentage
    // (issue-012: the extended syncProjectTaskCompletions() also derives
    // the parent's status from its sub-tasks and aligns completion_date.)
    if ($task['parent_task_id']) {
        syncProjectTaskCompletions($db, $task['project_id']);
    } else {
        syncProjectTaskCompletions($db, $task['project_id']);
    }
    
    // Get updated task data
    $updated_task = $db->fetchOne(
        "SELECT id, name, status, completion_percentage, completion_date
         FROM tasks WHERE id = ?",
        [$task_id]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Task marked as completed successfully',
        'task' => $updated_task
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    // Issue-016: log the underlying error server-side but do NOT
    // echo it to the client (info disclosure).
    error_log("Task completion failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to complete the task. Please try again.']);
}
?>