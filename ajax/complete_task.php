<?php
/**
 * AJAX endpoint for quick task completion
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

/**
 * Update parent task completion percentage based on subtasks
 */
function updateParentTaskCompletion($db, $parent_task_id) {
    // Get all subtasks for this parent task
    $subtasks = $db->fetchAll(
        "SELECT id, completion_percentage, status FROM tasks WHERE parent_task_id = ?",
        [$parent_task_id]
    );
    
    if (empty($subtasks)) {
        return;
    }
    
    // Calculate average completion percentage
    $total_completion = 0;
    $completed_count = 0;
    
    foreach ($subtasks as $subtask) {
        $total_completion += $subtask['completion_percentage'];
        if ($subtask['status'] === 'completed') {
            $completed_count++;
        }
    }
    
    $average_completion = round($total_completion / count($subtasks), 1);
    
    // Determine parent task status
    $parent_status = 'not_started';
    if ($average_completion > 0 && $average_completion < 100) {
        $parent_status = 'in_progress';
    } elseif ($average_completion === 100.0) {
        $parent_status = 'completed';
    }
    
    // Update parent task
    $update_params = [$parent_status, $average_completion, $parent_task_id];
    $completion_date_sql = '';
    
    if ($parent_status === 'completed') {
        $completion_date_sql = ', completion_date = NOW()';
    }
    
    $db->execute(
        "UPDATE tasks SET 
         status = ?,
         completion_percentage = ?,
         updated_at = NOW()
         {$completion_date_sql}
         WHERE id = ?",
        $update_params
    );
}

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
    if ($task['parent_task_id']) {
        updateParentTaskCompletion($db, $task['parent_task_id']);
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
    echo json_encode(['success' => false, 'message' => 'Error completing task: ' . $e->getMessage()]);
}
?>