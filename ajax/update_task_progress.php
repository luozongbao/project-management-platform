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
$completion_percentage = floatval($input['completion_percentage'] ?? 0);

if (!$task_id) {
    echo json_encode(['success' => false, 'error' => 'Task ID is required']);
    exit;
}

if ($completion_percentage < 0 || $completion_percentage > 100) {
    echo json_encode(['success' => false, 'error' => 'Completion percentage must be between 0 and 100']);
    exit;
}

try {
    $db = Database::getInstance();
    $user_id = getCurrentUserId();
    
    // Verify that the user has access to this task
    $task = $db->fetchOne(
        "SELECT t.id, t.parent_task_id, t.project_id, p.responsible_person_id
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.id = ?",
        [$task_id]
    );
    
    if (!$task || $task['responsible_person_id'] != $user_id) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Update the task completion percentage
    $db->execute(
        "UPDATE tasks SET completion_percentage = ?, updated_at = NOW() WHERE id = ?",
        [$completion_percentage, $task_id]
    );
    
    $completionMap = syncProjectTaskCompletions($db, $task['project_id']);
    $response = [
        'success' => true,
        'task_progress' => $completionMap['task_completion'][(int)$task_id] ?? 0,
    ];
    if ($task['parent_task_id']) {
        $response['parent_task_id'] = $task['parent_task_id'];
        $response['parent_progress'] = $completionMap['task_completion'][(int)$task['parent_task_id']] ?? 0;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>