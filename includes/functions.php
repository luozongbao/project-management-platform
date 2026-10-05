<?php
/**
 * Common utility functions
 */

// Start session if not already started
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Check if user is logged in
function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Require login - redirect to login if not authenticated
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Get current user ID
function getCurrentUserId() {
    startSession();
    return $_SESSION['user_id'] ?? null;
}

// Get current user info
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $db = Database::getInstance();
    $user = $db->fetchOne(
        "SELECT id, name, username, email, created_at FROM users WHERE id = ?",
        [getCurrentUserId()]
    );
    return $user;
}

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Generate random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function getCsrfToken() {
    startSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    startSession();

    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function formatTaskEstimate($estimatedHours) {
    if ($estimatedHours === null || $estimatedHours === '') {
        return '';
    }

    $hours = (float)$estimatedHours;
    $formatted = rtrim(rtrim(number_format($hours, 4, '.', ','), '0'), '.');

    return $formatted . ' ' . (abs($hours - 1) < 0.00005 ? 'hour' : 'hours');
}

function calculateTaskGroupCompletion($tasks) {
    if (empty($tasks)) {
        return 0.0;
    }

    $allHavePositiveEstimates = true;
    $weightedCompletion = 0.0;
    $totalHours = 0.0;
    $totalCompletion = 0.0;

    foreach ($tasks as $task) {
        $completion = (float)($task['completion_percentage'] ?? 0);
        $estimate = $task['estimated_hours'] ?? null;
        $totalCompletion += $completion;

        if ($estimate === null || !is_numeric($estimate) || (float)$estimate <= 0) {
            $allHavePositiveEstimates = false;
            continue;
        }

        $hours = (float)$estimate;
        $weightedCompletion += $hours * $completion;
        $totalHours += $hours;
    }

    if ($allHavePositiveEstimates && $totalHours > 0) {
        return $weightedCompletion / $totalHours;
    }

    return $totalCompletion / count($tasks);
}

function calculateTaskCompletionMap($tasks) {
    $tasksById = [];
    $childrenByParent = [];

    foreach ($tasks as $task) {
        $id = (int)$task['id'];
        $tasksById[$id] = $task;
        $parentId = $task['parent_task_id'] === null ? 0 : (int)$task['parent_task_id'];
        $childrenByParent[$parentId][] = $id;
    }

    $completionById = [];
    $calculating = [];
    $getCompletion = function ($taskId) use (&$getCompletion, &$tasksById, &$childrenByParent, &$completionById, &$calculating) {
        if (isset($completionById[$taskId])) {
            return $completionById[$taskId];
        }

        $task = $tasksById[$taskId];
        if (isset($calculating[$taskId])) {
            return (float)($task['completion_percentage'] ?? 0);
        }
        $calculating[$taskId] = true;

        $children = [];
        foreach ($childrenByParent[$taskId] ?? [] as $childId) {
            $children[] = [
                'completion_percentage' => $getCompletion($childId),
                'estimated_hours' => $tasksById[$childId]['estimated_hours'] ?? null,
            ];
        }

        $completionById[$taskId] = $children
            ? calculateTaskGroupCompletion($children)
            : (float)($task['completion_percentage'] ?? 0);
        unset($calculating[$taskId]);

        return $completionById[$taskId];
    };

    foreach (array_keys($tasksById) as $taskId) {
        $getCompletion($taskId);
    }

    $topLevelTasks = [];
    foreach ($childrenByParent[0] ?? [] as $taskId) {
        $topLevelTasks[] = [
            'completion_percentage' => $completionById[$taskId],
            'estimated_hours' => $tasksById[$taskId]['estimated_hours'] ?? null,
        ];
    }

    return [
        'project_completion' => calculateTaskGroupCompletion($topLevelTasks),
        'task_completion' => $completionById,
    ];
}

function getProjectTaskCompletionMap($db, $projectId) {
    $tasks = $db->fetchAll(
        "SELECT id, parent_task_id, estimated_hours, completion_percentage
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );

    return calculateTaskCompletionMap($tasks);
}

function syncProjectTaskCompletions($db, $projectId) {
    $tasks = $db->fetchAll(
        "SELECT id, parent_task_id, estimated_hours, completion_percentage
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );
    $completionMap = calculateTaskCompletionMap($tasks);
    $parentIds = [];

    foreach ($tasks as $task) {
        if ($task['parent_task_id'] !== null) {
            $parentIds[(int)$task['parent_task_id']] = true;
        }
    }

    foreach (array_keys($parentIds) as $parentId) {
        $storedCompletion = round($completionMap['task_completion'][$parentId], 2);
        $db->execute(
            "UPDATE tasks SET completion_percentage = ?, updated_at = NOW()
             WHERE id = ? AND completion_percentage <> ?",
            [
                $storedCompletion,
                $parentId,
                $storedCompletion,
            ]
        );
    }

    return $completionMap;
}

// Hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_ARGON2ID);
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Convert UTC datetime to user timezone
function formatDateTime($utc_datetime, $format = 'Y-m-d H:i:s') {
    if (empty($utc_datetime)) return '';

    $value = (string)$utc_datetime;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        $date = parseCalendarDate($value);
        return $date ? $date->format($format) : '';
    }

    $utc = parseUtcDateTime($value);
    if (!$utc) return '';

    try {
        $userTimezone = new DateTimeZone($_SESSION['timezone'] ?? TIMEZONE);
        return $utc->setTimezone($userTimezone)->format($format);
    } catch (Exception $e) {
        error_log('Unable to format stored UTC datetime: ' . $e->getMessage());
        return '';
    }
}

// Convert user timezone datetime to UTC for storage
function toUTC($datetime, $user_timezone = null) {
    if (empty($datetime)) return null;
    
    $timezone = $user_timezone ?? $_SESSION['timezone'] ?? TIMEZONE;
    $dt = new DateTime($datetime, new DateTimeZone($timezone));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

// Redirect with message
function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        startSession();
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $type;
    }
    header("Location: $url");
    exit();
}

// Display flash message
function displayMessage() {
    startSession();
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        
        return "<div class='alert alert-{$type}'>{$message}</div>";
    }
    return '';
}

// Calculate completion percentage
function calculateCompletionPercentage($completed_items, $total_items) {
    if ($total_items == 0) return 0;
    return round(($completed_items / $total_items) * 100, 1);
}

// Validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Format date for HTML date input
function formatDateForInput($utc_datetime) {
    if (empty($utc_datetime)) return '';

    $value = (string)$utc_datetime;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        $date = parseCalendarDate($value);
        return $date ? $date->format('Y-m-d') : '';
    }

    $utc = parseUtcDateTime($value);
    if (!$utc) return '';

    try {
        $userTimezone = new DateTimeZone($_SESSION['timezone'] ?? TIMEZONE);
        return $utc->setTimezone($userTimezone)->format('Y-m-d');
    } catch (Exception $e) {
        error_log('Unable to format stored UTC date for input: ' . $e->getMessage());
        return '';
    }
}

function parseCalendarDate($value) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();

    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        error_log('Unable to format invalid stored calendar date: ' . $value);
        return null;
    }

    return $date;
}

function parseUtcDateTime($value) {
    $formats = [
        ['!Y-m-d H:i:s', 'Y-m-d H:i:s'],
        ['!Y-m-d\TH:i', 'Y-m-d\TH:i'],
    ];

    foreach ($formats as [$parseFormat, $outputFormat]) {
        $datetime = DateTimeImmutable::createFromFormat($parseFormat, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($datetime && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $datetime->format($outputFormat) === $value) {
            return $datetime;
        }
    }

    error_log('Unable to format invalid stored UTC datetime: ' . $value);
    return null;
}

// Escape output for HTML
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Client share / project code helpers (added 2026-10-03)
// ---------------------------------------------------------------------------

// Allowed currencies for project budgets.
function allowedCurrencies() {
    return ['THB', 'USD', 'CNY', 'JPY', 'SGD', 'EUR'];
}

// Validate a currency code against the whitelist.
function isAllowedCurrency($code) {
    return in_array(strtoupper((string)$code), allowedCurrencies(), true);
}

// Generate a random 5-5-5 alpha-numeric share code like "AX498-99ZB2-92C3J".
// Uses only uppercase letters + digits, excluding easily-confused chars (0/O, 1/I/L).
function generateShareCode() {
    // Avoid 0/O, 1/I/L for readability.
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $maxIdx = strlen($alphabet) - 1;
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $buf = '';
        for ($i = 0; $i < 5; $i++) {
            $buf .= $alphabet[random_int(0, $maxIdx)];
        }
        $groups[] = $buf;
    }
    return implode('-', $groups);
}

// Build the absolute URL the client will use to view their project dashboard.
// Configurable via APP_URL so it works behind a reverse proxy / different host.
function buildClientShareUrl($share_code) {
    return rtrim(APP_URL, '/') . '/client/?code=' . rawurlencode($share_code);
}

// Format a money amount with the project currency. No FX conversion — the
// stored amount is taken at face value in the project's chosen currency.
function formatMoney($amount, $currency) {
    if ($amount === null || $amount === '' || $currency === null || $currency === '') {
        return '';
    }
    $symbol = [
        'THB' => '฿',
        'USD' => '$',
        'CNY' => '¥',
        'JPY' => '¥',
        'SGD' => 'S$',
        'EUR' => '€',
    ][strtoupper($currency)] ?? '';
    $decimals = (strtoupper($currency) === 'JPY') ? 0 : 2;
    return $symbol . number_format((float)$amount, $decimals) . ' ' . strtoupper($currency);
}
?>