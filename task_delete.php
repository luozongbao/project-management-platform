<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/tasks', 'Task deletion must be submitted through the confirmation form.', 'danger');
}

$task_id = $_POST['task_id'] ?? null;
$user_id = getCurrentUserId();

if (!is_string($task_id) || !ctype_digit($task_id) || (int)$task_id < 1) {
    redirect('/tasks', 'Invalid task ID.', 'danger');
}

$task_id = (int)$task_id;
$db = Database::getInstance();

/*
 * Ownership check.
 *
 * A task belongs to the manager when the user is the responsible person of the
 * task itself, or the responsible person of the project the task lives in.
 * The task detail page uses the same rule, so the edit page cannot offer a
 * delete for a task the detail page would refuse to show.
 */
$task = $db->fetchOne(
    "SELECT t.id, t.name, t.parent_task_id, t.project_id
       FROM tasks t
       JOIN projects p ON p.id = t.project_id
      WHERE t.id = ?
        AND (t.responsible_person_id = ? OR p.responsible_person_id = ?)",
    [$task_id, $user_id, $user_id]
);

if (!$task) {
    redirect('/tasks', 'Task not found or access denied.', 'danger');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    redirect(
        '/tasks/' . $task_id . '/edit',
        'Your session could not be verified. Please try deleting the task again.',
        'danger'
    );
}

/*
 * Confirmation name.
 *
 * The delete dialog asks the user to type the task NAME, the same way the
 * contact-delete flow asks for the contact name. The task's code
 * (TSK-0007, derived from the row id) is only an internal identifier and is
 * not something the user can be expected to reproduce from memory, so it is
 * not used as the confirmation secret.
 *
 * The comparison uses hash_equals() so the check is not timing-leaky.
 */
$task_name = (string)$task['name'];

$confirmation = $_POST['confirmation_name'] ?? null;
if (!is_string($confirmation) || !hash_equals($task_name, trim($confirmation))) {
    redirect(
        '/tasks/' . $task_id . '/edit',
        'The Task Name did not match. The task was not deleted.',
        'danger'
    );
}

try {
    /*
     * Sub-tasks are removed by the tasks.parent_task_id foreign key, which is
     * declared ON DELETE CASCADE, so deleting the parent removes the whole
     * subtree in one statement. Sub-tasks of a task that is itself a child
     * are removed the same way (cascade is applied recursively by InnoDB).
     */
    $db->query("DELETE FROM tasks WHERE id = ?", [$task_id]);

    // The parent task may have been one of the deleted rows' parents; the
    // parent's own completion is recomputed on the next read, so nothing else
    // is needed here.

    redirect('/tasks', 'Task deleted successfully.', 'success');
} catch (Exception $e) {
    error_log("Task deletion error: " . $e->getMessage());
    redirect('/tasks/' . $task_id . '/edit', 'Failed to delete task.', 'danger');
}
