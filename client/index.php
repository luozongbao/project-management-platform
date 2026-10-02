<?php
/**
 * Client landing page — public.
 *
 * Two flows:
 *  1. GET ?code=XXXXX-XXXXX-XXXXX → redirect to dashboard.php?code=…
 *  2. POST with code → verify code exists, redirect to dashboard.php?code=…
 *  3. GET (no code) → show the code-entry form.
 *
 * The code itself is the credential — no login. Anyone with the code can
 * view a read-only progress dashboard.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();

// Helper: validate format only — don't reveal whether a code exists until
// we actually look it up. Format = 5-5-5 of A-Z0-9 minus confusing chars.
function isValidShareCodeFormat($code) {
    return (bool)preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', strtoupper((string)$code));
}

// --- 1. Direct link: ?code=… → dashboard -----------------------------------
$code = $_GET['code'] ?? '';
if ($code !== '') {
    $code = strtoupper(trim($code));
    if (isValidShareCodeFormat($code)) {
        $exists = $db->fetchOne(
            "SELECT 1 AS x FROM projects WHERE share_code = ?",
            [$code]
        );
        if ($exists) {
            header('Location: dashboard.php?code=' . rawurlencode($code));
            exit();
        }
    }
    // Bad/missing code falls through and shows the form again with an error.
}

// --- 2. Form submission -----------------------------------------------------
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim($_POST['code'] ?? ''));

    if ($code === '') {
        $error = 'Please enter your project code.';
    } elseif (!isValidShareCodeFormat($code)) {
        $error = 'That doesn\'t look like a valid project code. Codes look like AX498-99ZB2-92C3J.';
    } else {
        $exists = $db->fetchOne(
            "SELECT 1 AS x FROM projects WHERE share_code = ?",
            [$code]
        );
        if (!$exists) {
            $error = 'No project found for that code. Please double-check it.';
        } else {
            header('Location: dashboard.php?code=' . rawurlencode($code));
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Portal — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="assets/client.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="client-body">

<main class="client-shell">
    <div class="client-card">
        <div class="client-brand">
            <i class="fas fa-project-diagram"></i>
            <h1><?= e(APP_NAME) ?></h1>
            <p class="client-tagline">Client Project Portal</p>
        </div>

        <h2><i class="fas fa-key"></i> Enter Your Project Code</h2>

        <p class="client-intro">
            Paste the project code from your invitation email, or open the direct link
            your project manager sent you.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="client-form" autocomplete="off">
            <div class="form-group">
                <label for="code">Project Code</label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    placeholder="AAAAA-AAAAA-AAAAA"
                    value="<?= e($code) ?>"
                    required
                    autofocus
                    pattern="[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}"
                    title="Format: 5-5-5 alphanumeric, e.g. AX498-99ZB2-92C3J"
                    style="text-transform: uppercase; letter-spacing: 0.1em; font-family: monospace; font-size: 1.15rem;">
                <small>Format: 5 characters, dash, 5, dash, 5.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-arrow-right"></i> View My Project
            </button>
        </form>

        <p class="client-foot">
            Lost your code? Please contact your project manager.
        </p>
    </div>
</main>

<script>
// Auto-uppercase + auto-insert dashes while typing for a smoother UX.
(function() {
    var input = document.getElementById('code');
    if (!input) return;
    input.addEventListener('input', function(e) {
        var v = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (v.length > 15) v = v.substring(0, 15);
        var formatted = '';
        for (var i = 0; i < v.length; i++) {
            if (i === 5 || i === 10) formatted += '-';
            formatted += v[i];
        }
        if (input.value !== formatted) {
            input.value = formatted;
        }
    });
})();
</script>

</body>
</html>