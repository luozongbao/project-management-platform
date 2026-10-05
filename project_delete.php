<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('projects.php', 'Project deletion must be submitted through the confirmation form.', 'danger');
}

$project_id = $_POST['project_id'] ?? null;
$user_id = getCurrentUserId();

if (!is_string($project_id) || !ctype_digit($project_id) || (int)$project_id < 1) {
    redirect('projects.php', 'Invalid project ID.', 'danger');
}

$db = Database::getInstance();

// Verify project ownership
$project = $db->fetchOne(
    "SELECT id, share_code FROM projects WHERE id = ? AND responsible_person_id = ?",
    [$project_id, $user_id]
);

if (!$project) {
    redirect('projects.php', 'Project not found or access denied.', 'danger');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    redirect(
        'project_edit.php?id=' . rawurlencode((string)$project_id),
        'Your session could not be verified. Please try deleting the project again.',
        'danger'
    );
}

if (empty($project['share_code'])) {
    redirect(
        'project_edit.php?id=' . rawurlencode((string)$project_id),
        'This project has no Project Code, so it cannot be deleted using this confirmation.',
        'danger'
    );
}

if (!is_string($_POST['confirmation_code'] ?? null)
    || !hash_equals((string)$project['share_code'], trim($_POST['confirmation_code']))) {
    redirect(
        'project_edit.php?id=' . rawurlencode((string)$project_id),
        'The Project Code did not match. The project was not deleted.',
        'danger'
    );
}

try {
    // Delete project (cascade will handle tasks and project_contacts)
    $db->query("DELETE FROM projects WHERE id = ?", [$project_id]);
    
    redirect('projects.php', 'Project deleted successfully.', 'success');
} catch (Exception $e) {
    error_log("Project deletion error: " . $e->getMessage());
    redirect('projects.php', 'Failed to delete project.', 'danger');
}
?>