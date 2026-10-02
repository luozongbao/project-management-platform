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

if (!$task_id) {
    echo json_encode(['success' => false, 'error' => 'Task ID is required']);
    exit;
}

$valid_statuses = ['not_started', 'in_progress', 'completed', 'on_hold'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    $db = Database::getInstance();
    $user_id = getCurrentUserId();
    
    // Verify that the user has access to this task
    $task = $db->fetchOne(
        "SELECT t.id, t.parent_task_id, t.completion_percentage, p.responsible_person_id
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
        $completion_date = date('Y-m-d H:i:s');
    } elseif ($status === 'not_started') {
        $completion_percentage = 0;
    }
    
    // Update the task status and related fields
    $db->execute(
        "UPDATE tasks SET 
            status = ?, 
            completion_percentage = ?, 
            completion_date = ?,
            updated_at = NOW() 
         WHERE id = ?",
        [$status, $completion_percentage, $completion_date, $task_id]
    );
    
    $response = ['success' => true];
    
    // If this is a subtask, calculate and update parent task completion
    if ($task['parent_task_id']) {
        $parent_subtasks = $db->fetchAll(
            "SELECT completion_percentage FROM tasks WHERE parent_task_id = ?",
            [$task['parent_task_id']]
        );
        
        $total_completion = array_sum(array_column($parent_subtasks, 'completion_percentage'));
        $avg_completion = count($parent_subtasks) > 0 ? $total_completion / count($parent_subtasks) : 0;
        
        // Update parent task completion
        $db->execute(
            "UPDATE tasks SET completion_percentage = ?, updated_at = NOW() WHERE id = ?",
            [$avg_completion, $task['parent_task_id']]
        );
        
        $response['parent_task_id'] = $task['parent_task_id'];
        $response['parent_progress'] = $avg_completion;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>