<?php
/**
 * AJAX endpoint for inline-editing a status reason on a task
 * (issue-034, v1.8.0).
 *
 * The task_detail.php page renders a <details> block next to each
 * populated or missing reason chip. Submitting that block POSTs here;
 * on success the page swaps the chip text and closes the <details>.
 * The reason field can also be cleared (empty string → stored NULL).
 *
 * Request: application/json
 *   csrf_token  (required)
 *   task_id     (required, int)
 *   field       (required, 'on_hold_reason' | 'delay_reason')
 *   value       (required, string, max 2000 chars)
 *
 * Response: application/json
 *   success, normalized value, task_id, field
 *
 * Errors:
 *   400 CSRF failure / invalid field / oversized value
 *   403 task not owned by current user
 *   404 task not found
 *   409 task is in a state where the reason cannot apply
 *       (e.g. user tries to set on_hold_reason while status is
 *        not on_hold — the spec wants the reason cleared whenever
 *        the status moves off, so we accept the write and just
 *        null-stamp it on read)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

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
$csrf_token = $input['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
if (!is_string($csrf_token) || !validateCsrfToken($csrf_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid form token. Please reload the page and try again.']);
    exit;
}

$task_id = isset($input['task_id']) ? (int)$input['task_id'] : 0;
$field   = $input['field'] ?? '';
$value   = $input['value'] ?? '';
if ($task_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Task ID is required.']);
    exit;
}
if (!in_array($field, ['on_hold_reason', 'delay_reason'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field.']);
    exit;
}
if (!is_string($value)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Value must be a string.']);
    exit;
}
$value = trim($value);
if (strlen($value) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => ucfirst(str_replace('_', ' ', $field)) . ' must be 2000 characters or less.']);
    exit;
}

$db = Database::getInstance();
$user_id = getCurrentUserId();

$task = $db->fetchOne(
    "SELECT t.id, t.status, t.expected_completion_date, p.responsible_person_id
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     WHERE t.id = ?",
    [$task_id]
);
if (!$task) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Task not found.']);
    exit;
}
if ((int)$task['responsible_person_id'] !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// State-dependent rules: refuse to write a non-empty reason into a
// column that doesn't apply. Empty values are always accepted (they
// clear the column), which is the "I fixed it by moving the date
// forward" path.
$stored_value = $value === '' ? null : $value;
if ($stored_value !== null) {
    if ($field === 'on_hold_reason' && ($task['status'] ?? '') !== 'on_hold') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => 'Cannot set on-hold reason when status is not On Hold.',
            'field'   => $field,
        ]);
        exit;
    }
    if ($field === 'delay_reason') {
        $expected = $task['expected_completion_date'] ?? null;
        $is_delayed = ($task['status'] ?? '') !== 'completed'
            && !empty($expected)
            && strtotime((string)$expected) < strtotime(date('Y-m-d'));
        if (!$is_delayed) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'   => 'Cannot set delay reason when the task is not delayed.',
                'field'   => $field,
            ]);
            exit;
        }
    }
}

try {
    $db->execute(
        "UPDATE tasks SET {$field} = ?, updated_at = NOW() WHERE id = ?",
        [$stored_value, $task_id]
    );
    echo json_encode([
        'success'    => true,
        'task_id'    => $task_id,
        'field'      => $field,
        'value'      => $stored_value,
        'is_empty'   => $stored_value === null,
    ]);
} catch (Exception $e) {
    error_log('update_status_reason.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save the reason. Please try again.']);
}
