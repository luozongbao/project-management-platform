<?php
require_once '../config.php';
require_once '../includes/Database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Project ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $user_id = getCurrentUserId();
    
    // Verify that the user has access to this project
    $project = $db->fetchOne(
        "SELECT id FROM projects WHERE id = ? AND responsible_person_id = ?",
        [$project_id, $user_id]
    );
    
    if (!$project) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Get contacts for this project
    $contacts = $db->fetchAll(
        "SELECT DISTINCT c.id, c.name, c.email, c.phone 
         FROM contacts c
         JOIN project_contacts pc ON c.id = pc.contact_id
         WHERE pc.project_id = ?
         ORDER BY c.name",
        [$project_id]
    );
    
    echo json_encode(['contacts' => $contacts]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>