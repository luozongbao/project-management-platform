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

/**
 * Resolve the effective estimated hours for a task, honouring the
 * inherit-from-subtasks flag (issue-011). Returns:
 *   - float|int  if a usable estimate exists
 *   - null       if there is no usable estimate
 *
 * The function reads a snapshot of the task's children and respects
 * the same "all siblings must have positive estimates" rule used by
 * the effort-weighted completion rollup (issue-007), so the parent's
 * *displayed* estimate agrees with the *computed* completion.
 *
 * Cross-reference: docs/issues/issue-011.md, docs/issues/issue-007.md.
 */
function resolveTaskEstimateHours(array $task, array $childTasks): ?float {
    if (!empty($task['inherit_subtask_hours'])) {
        $sum = 0.0;
        $hasPositive = false;
        foreach ($childTasks as $child) {
            $h = $child['estimated_hours'] ?? null;
            if ($h !== null && is_numeric($h) && (float)$h > 0) {
                $sum += (float)$h;
                $hasPositive = true;
            }
        }
        return $hasPositive ? round($sum, 4) : null;
    }
    $h = $task['estimated_hours'] ?? null;
    if ($h === null || !is_numeric($h) || (float)$h <= 0) {
        return null;
    }
    return round((float)$h, 4);
}

/**
 * Derive a parent task's status from its sub-task statuses (issue-012).
 * Pure, deterministic, unit-testable.
 *
 * Rule:
 *   - `on_hold` (parent) is sticky manual: it wins regardless of children.
 *   - Otherwise `in_progress` if any child is `in_progress`.
 *   - Otherwise `completed` if any child is `completed`.
 *   - Otherwise `not_started`.
 *
 * Cross-reference: docs/issues/issue-012.md.
 */
function deriveParentStatus(?string $currentStatus, array $childStatuses): string {
    if ($currentStatus === 'on_hold') {
        return 'on_hold';
    }
    foreach ($childStatuses as $s) {
        if ($s === 'in_progress') return 'in_progress';
    }
    foreach ($childStatuses as $s) {
        if ($s === 'completed') return 'completed';
    }
    return 'not_started';
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
        "SELECT id, parent_task_id, status, estimated_hours, completion_percentage
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );
    $completionMap = calculateTaskCompletionMap($tasks);
    $parentIds = [];
    $subtaskStatuses = [];

    foreach ($tasks as $task) {
        if ($task['parent_task_id'] !== null) {
            $parentIds[(int)$task['parent_task_id']] = true;
            $subtaskStatuses[(int)$task['parent_task_id']][] = $task['status'] ?? 'not_started';
        }
    }

    foreach (array_keys($parentIds) as $parentId) {
        $storedCompletion = round($completionMap['task_completion'][$parentId], 2);

        // Issue-012: derive the parent's status from its sub-task statuses.
        // Leaves the row untouched if no children (defensive, but we already
        // filtered by $parentIds so this only fires for parents with sub-tasks).
        $currentStatus = $db->fetchOne(
            "SELECT status FROM tasks WHERE id = ?",
            [$parentId]
        )['status'] ?? null;
        $newStatus = deriveParentStatus($currentStatus, $subtaskStatuses[$parentId] ?? []);

        // Issue-012: align completion_date with the derived status.
        // - new status 'completed' and no prior completion_date: set to NOW.
        // - new status != 'completed' and a prior completion_date exists: clear it.
        $setCompletionDate = '';
        $params = [$storedCompletion, $newStatus, $parentId, $storedCompletion, $newStatus];
        if ($newStatus === 'completed') {
            $setCompletionDate = ', completion_date = COALESCE(completion_date, NOW())';
        } elseif ($currentStatus === 'completed' && $newStatus !== 'completed') {
            $setCompletionDate = ', completion_date = NULL';
        }

        $db->execute(
            "UPDATE tasks SET completion_percentage = ?, status = ?, updated_at = NOW()
                 $setCompletionDate
             WHERE id = ?
               AND (completion_percentage <> ? OR status <> ?)",
            $params
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
// The entry point is the domain root — see /index.php (public landing).
// Configurable via APP_URL so it works behind a reverse proxy / different host.
function buildClientShareUrl($share_code) {
    return rtrim(APP_URL, '/') . '/?code=' . rawurlencode($share_code);
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

// ============================================================================
// Password-reset rate-limit / brute-force protection (issue-018)
//
// Three small helpers used by forgot_password.php and reset_password.php.
// The policy is per-IP and recorded in the password_reset_attempts table
// (see database/migrations/20261015_add_password_reset_attempts.sql).
//
// Constants (overridable in config.php; defaults are 5 request attempts per
// 15 minutes and 10 consume attempts per 15 minutes):
//   PASSWORD_RESET_REQUEST_LIMIT  (default 5)
//   PASSWORD_RESET_REQUEST_WINDOW (default 900 seconds = 15 min)
//   PASSWORD_RESET_CONSUME_LIMIT  (default 10)
//   PASSWORD_RESET_CONSUME_WINDOW (default 900 seconds = 15 min)
// ============================================================================

if (!function_exists('recordPasswordResetAttempt')) {
    /**
     * Record a single password-reset attempt.
     *
     * @param string $ip      The client IP (use $_SERVER['REMOTE_ADDR']; not
     *                        X-Forwarded-For, since this app is not behind
     *                        a load balancer that sets the header).
     * @param string $action  'request' (forgot_password.php POST) or
     *                        'consume' (reset_password.php POST).
     * @param int|null $userId  The user id if known, or null for unknown
     *                        emails (forgot_password.php) and rate-limited
     *                        dropped requests.
     * @param bool $success   True if the attempt succeeded (the request was
     *                        processed normally), false if it failed (bad
     *                        credentials, rate-limited drop, etc.).
     */
    function recordPasswordResetAttempt($ip, $action, $userId, $success) {
        $ip = (string)$ip;
        $action = ($action === 'consume') ? 'consume' : 'request';
        $successFlag = $success ? 1 : 0;
        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO password_reset_attempts (ip_address, action, user_id, success)
             VALUES (?, ?, ?, ?)",
            [$ip, $action, $userId, $successFlag]
        );
    }
}

if (!function_exists('isPasswordResetRateLimited')) {
    /**
     * Check whether a given IP is currently rate-limited for a given action.
     *
     * The policy:
     *   - 'request' (forgot_password.php): at most
     *     PASSWORD_RESET_REQUEST_LIMIT attempts (default 5) within
     *     PASSWORD_RESET_REQUEST_WINDOW seconds (default 15 min). A
     *     *successful* request counts as a request too (it sent an
     *     email and consumed SMTP quota), so a real user who hammers
     *     the button can also trip the limit. The 15-min cooldown
     *     is short enough that this is rarely a UX problem.
     *   - 'consume' (reset_password.php): at most
     *     PASSWORD_RESET_CONSUME_LIMIT attempts (default 10) within
     *     PASSWORD_RESET_CONSUME_WINDOW seconds (default 15 min).
     *     Successful consumes DO count — but a successful consume
     *     also clears the consume history for that IP via
     *     clearPasswordResetAttempts() so the user is not locked
     *     out by their own success.
     *
     * The function does NOT mutate the database; the caller is
     * responsible for calling recordPasswordResetAttempt() to
     * record the current attempt.
     *
     * @return bool True if the IP is over the threshold.
     */
    function isPasswordResetRateLimited($ip, $action) {
        $ip = (string)$ip;
        $action = ($action === 'consume') ? 'consume' : 'request';

        if ($action === 'consume') {
            $limit  = defined('PASSWORD_RESET_CONSUME_LIMIT')  ? PASSWORD_RESET_CONSUME_LIMIT  : 10;
            $window = defined('PASSWORD_RESET_CONSUME_WINDOW') ? PASSWORD_RESET_CONSUME_WINDOW : 900;
        } else {
            $limit  = defined('PASSWORD_RESET_REQUEST_LIMIT')  ? PASSWORD_RESET_REQUEST_LIMIT  : 5;
            $window = defined('PASSWORD_RESET_REQUEST_WINDOW') ? PASSWORD_RESET_REQUEST_WINDOW : 900;
        }

        $db = Database::getInstance();
        $count = (int)$db->fetchOne(
            "SELECT COUNT(*) AS c FROM password_reset_attempts
             WHERE ip_address = ? AND action = ?
               AND attempted_at >= NOW() - INTERVAL ? SECOND",
            [$ip, $action, $window]
        )['c'];

        // The limit is the MAXIMUM allowed. After `$limit` records
        // the IP is still allowed (the `$limit`-th request was the
        // last one that fit). The `$limit + 1`-th request is the
        // first to be denied. So use `>` not `>=`.
        return $count > $limit;
    }
}

if (!function_exists('clearPasswordResetAttempts')) {
    /**
     * Clear the rate-limit history for a given IP + action.
     *
     * Called after a successful 'consume' so the legitimate user
     * is not locked out by their own success. Not called after
     * a successful 'request' — a user who successfully requested
     * a reset should not immediately be able to request another
     * one without the cooldown.
     */
    function clearPasswordResetAttempts($ip, $action) {
        $ip = (string)$ip;
        $action = ($action === 'consume') ? 'consume' : 'request';
        $db = Database::getInstance();
        $db->execute(
            "DELETE FROM password_reset_attempts WHERE ip_address = ? AND action = ?",
            [$ip, $action]
        );
    }
}

if (!function_exists('consumePasswordResetToken')) {
    /**
     * Look up a valid (non-expired) password-reset token and return the
     * associated user info. Does NOT consume (delete) the token — the
     * caller is responsible for marking it consumed after a successful
     * password update.
     *
     * The reason this is factored out of reset_password.php is twofold:
     *   1. The test suite needs to call it from a CLI proxy without
     *      booting the whole page (the page has session / isLoggedIn()
     *      / header() calls that make a CLI render brittle).
     *   2. It is a clean separation: the page is responsible for the
     *      user-facing flow; this helper is responsible for the DB
     *      lookup.
     *
     * @return array|null The token row + joined user name/email, or null
     *                    if the token is missing, unknown, or expired.
     */
    function consumePasswordResetToken($token) {
        $token = (string)$token;
        if ($token === '') {
            return null;
        }
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT rt.id, rt.user_id, rt.token, rt.expires_at, u.name, u.email
             FROM password_reset_tokens rt
             JOIN users u ON rt.user_id = u.id
             WHERE rt.token = ? AND rt.expires_at > NOW()",
            [$token]
        );
        return $row ?: null;
    }
}

if (!function_exists('rotatePasswordResetToken')) {
    /**
     * Replace a password-reset token with a fresh one. Returns the new
     * token string, or null if the original token was not found.
     *
     * The expiry is bumped to NOW() + PASSWORD_RESET_EXPIRY (1 hour by
     * default) so the rotation extends the validity window for the
     * currently-valid user. A future hardening could leave the expiry
     * alone, but extending is the user-friendly default and matches
     * the spec's "rotates on every form render" intent.
     */
    function rotatePasswordResetToken($oldToken) {
        $oldToken = (string)$oldToken;
        if ($oldToken === '') {
            return null;
        }
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT id FROM password_reset_tokens WHERE token = ?",
            [$oldToken]
        );
        if (!$row) {
            return null;
        }
        $newToken = generateToken();
        $newExpires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY);
        $db->execute(
            "UPDATE password_reset_tokens SET token = ?, expires_at = ? WHERE id = ?",
            [$newToken, $newExpires, $row['id']]
        );
        return $newToken;
    }
}

if (!function_exists('invalidateAllPasswordResetTokensForUser')) {
    /**
     * Delete ALL password-reset tokens for a given user. Called on
     * successful password change so a previously-issued link cannot
     * be reused after the user has successfully reset.
     */
    function invalidateAllPasswordResetTokensForUser($userId) {
        $uid = (int)$userId;
        if ($uid <= 0) {
            return;
        }
        $db = Database::getInstance();
        $db->execute(
            "DELETE FROM password_reset_tokens WHERE user_id = ?",
            [$uid]
        );
    }
}
?>