<?php
/**
 * contact_delete.php — delete a contact (issue-047).
 *
 * WHY THIS FILE EXISTS
 * The v1 contact edit page had a "Delete Contact" button whose JS navigated
 * to `contact_delete.php?id=<id>`. That file never existed and the route was
 * never registered, so the button 404'd on every contact. The v2 mockup
 * (docs/design/2.0/contact-form.html) instead requires a typed-name
 * confirmation inside a Danger-zone modal, so this handler implements that
 * contract:
 *
 *   POST /contacts/<id>/delete
 *       csrf_token            — required, validated
 *       contact_id            — required, integer
 *       confirmation_name     — required, must equal the contact's name
 *
 * The delete is deliberately conservative: it removes the contact and its
 * project_contacts assignments, and NULLs tasks.contact_person_id so the
 * tasks themselves survive (the mockup states "Tasks already assigned to
 * them are kept"). tasks.contact_person_id is a RESTRICT foreign key, so
 * without the UPDATE the DELETE would be rejected by InnoDB.
 */
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/contacts', 'Contact deletion must be submitted through the confirmation form.', 'danger');
}

$contact_id = $_POST['contact_id'] ?? null;
$user_id = getCurrentUserId();

if (!is_string($contact_id) || !ctype_digit($contact_id) || (int)$contact_id < 1) {
    redirect('/contacts', 'Invalid contact ID.', 'danger');
}

$db = Database::getInstance();

/*
 * Ownership check: the contact must be reachable through at least one
 * project the current user is responsible for. A contact with no project
 * assignment is unreachable by design (the contact form requires at least
 * one project), so it cannot be deleted here either.
 */
$contact = $db->fetchOne(
    "SELECT DISTINCT c.id, c.name
     FROM contacts c
     JOIN project_contacts pc ON c.id = pc.contact_id
     JOIN projects p ON pc.project_id = p.id
     WHERE c.id = ? AND p.responsible_person_id = ?",
    [$contact_id, $user_id]
);

if (!$contact) {
    redirect('/contacts', 'Contact not found or access denied.', 'danger');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    redirect(
        '/contacts/' . (int)$contact_id . '/edit',
        'Your session could not be verified. Please try deleting the contact again.',
        'danger'
    );
}

$confirmation = $_POST['confirmation_name'] ?? null;
if (!is_string($confirmation)
    || !hash_equals((string)$contact['name'], trim($confirmation))) {
    redirect(
        '/contacts/' . (int)$contact_id . '/edit',
        'The contact name did not match. The contact was not deleted.',
        'danger'
    );
}

try {
    /* Keep the tasks, drop the dangling reference. */
    $db->execute(
        "UPDATE tasks SET contact_person_id = NULL WHERE contact_person_id = ?",
        [$contact_id]
    );

    /* project_contacts has ON DELETE CASCADE, but clearing it explicitly
     * keeps the intent visible and avoids depending on the FK's action. */
    $db->execute(
        "DELETE FROM project_contacts WHERE contact_id = ?",
        [$contact_id]
    );

    $db->execute(
        "DELETE FROM contacts WHERE id = ?",
        [$contact_id]
    );

    redirect('/contacts', 'Contact deleted successfully.', 'success');
} catch (Exception $e) {
    error_log("Contact deletion error: " . $e->getMessage());
    redirect(
        '/contacts/' . (int)$contact_id . '/edit',
        'Failed to delete the contact. Please try again.',
        'danger'
    );
}
