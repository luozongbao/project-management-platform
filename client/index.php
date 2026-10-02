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
 *
 * Localization: ENG (default) / 中文. Choose via ?lang=, browser header,
 * or the toggle on this form. Choice persists in a 1-year cookie.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lang.php';

// Persist ?lang= into the cookie so subsequent navigations remember it.
if (isset($_GET['lang']) && in_array($_GET['lang'], client_supported_langs(), true)) {
    client_set_lang_cookie($_GET['lang']);
}
$lang = client_current_lang();

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
            header('Location: dashboard.php?code=' . rawurlencode($code) . '&lang=' . urlencode($lang));
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
        $error = t('err.empty_code');
    } elseif (!isValidShareCodeFormat($code)) {
        $error = t('err.bad_format');
    } else {
        $exists = $db->fetchOne(
            "SELECT 1 AS x FROM projects WHERE share_code = ?",
            [$code]
        );
        if (!$exists) {
            $error = t('err.no_project');
        } else {
            header('Location: dashboard.php?code=' . rawurlencode($code) . '&lang=' . urlencode($lang));
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('landing.title')) ?> — <?= e(APP_NAME) ?></title>
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
            <p class="client-tagline"><?= e(t('landing.tagline')) ?></p>
        </div>

        <h2><i class="fas fa-key"></i> <?= e(t('landing.heading')) ?></h2>

        <p class="client-intro"><?= e(t('landing.intro')) ?></p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="client-form" autocomplete="off">
            <div class="form-group">
                <label for="code"><?= e(t('landing.code_label')) ?></label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    placeholder="<?= e(t('landing.code_placeholder')) ?>"
                    value="<?= e($code) ?>"
                    required
                    autofocus
                    pattern="[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}"
                    title="Format: 5-5-5 alphanumeric, e.g. AX498-99ZB2-92C3J"
                    style="text-transform: uppercase; letter-spacing: 0.1em; font-family: monospace; font-size: 1.15rem;">
                <small><?= e(t('landing.code_help')) ?></small>
            </div>
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-arrow-right"></i> <?= e(t('landing.submit')) ?>
            </button>
        </form>

        <p class="client-foot"><?= e(t('landing.foot')) ?></p>

        <div class="lang-switch" aria-label="<?= e(t('landing.lang_label')) ?>">
            <span class="lang-switch-label"><i class="fas fa-globe"></i> <?= e(t('landing.lang_label')) ?>:</span>
            <?php foreach (client_other_langs() as $code2 => $label): ?>
                <a href="<?= e(client_lang_url($code2)) ?>" class="lang-switch-link"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
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