<?php
/**
 * _smtp_test.php — diagnostic helper for Hostinger-style shared hosting.
 *
 * DELETE THIS FILE after debugging. It:
 *   1. Prints the active SMTP config from config.php (so you can spot
 *      placeholders / wrong port).
 *   2. Attempts a real send() and the SMTP transcript.
 *   3. Forces PHPMailer into verbose debug mode (SMTP::DEBUG_SERVER).
 *
 * Run from CLI:
 *     php _smtp_test.php you@yourdomain.com
 *
 * Or visit in browser (delete the file when done!):
 *     https://yourdomain.com/_smtp_test.php?to=you@yourdomain.com
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// --- 1. Show the active config ----------------------------------------------
echo "=== Active SMTP config ===\n";
echo "Host      : " . SMTP_HOST . "\n";
echo "Port      : " . SMTP_PORT . " (type: " . gettype(SMTP_PORT) . ")\n";
echo "Protocol  : " . SMTP_PROTOCOL . "\n";
echo "User      : " . SMTP_USER . "\n";
echo "Pass len  : " . strlen(SMTP_PASS) . " chars\n";
echo "APP_NAME  : " . APP_NAME . "\n";
echo "APP_URL   : " . APP_URL . "\n\n";

if (SMTP_USER === 'your_email@gmail.com' || SMTP_USER === 'your_email@yourdomain.com' || SMTP_PASS === 'your_email_password') {
    echo "!! Looks like the template defaults are still in config.php — re-run install.php.\n\n";
}

if ((int)SMTP_PORT !== 465 && (int)SMTP_PORT !== 587) {
    echo "!! Unusual SMTP_PORT (" . SMTP_PORT . "). On Hostinger, use 465 + 'ssl'.\n\n";
}

$to = $argv[1] ?? ($_GET['to'] ?? SMTP_USER);
echo "Recipient : $to\n\n";

// --- 2. Real send attempt with full debug ----------------------------------
$mail = new PHPMailer(true);
$mail->Debugoutput = function ($str, $level) {
    // 0 = off, 1 = client, 2 = client+server, 3 = connection + 2, 4 = low-level
    echo "[SMTP $level] $str";
};
$mail->SMTPDebug = SMTP::DEBUG_CONNECTION; // verbose: shows transcript

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_PROTOCOL;
    $mail->Port       = SMTP_PORT;
    $mail->setFrom(SMTP_USER, APP_NAME);
    $mail->addAddress($to);
    $mail->Subject = 'SMTP test from ' . APP_NAME;
    $mail->Body    = 'If you see this in your inbox, SMTP works.';
    $mail->AltBody = 'Plain-text body.';
    $mail->send();
    echo "Sent OK\n";
} catch (Exception $e) {
    echo "!! SEND FAILED: " . $e->getMessage() . "\n";
    echo "!! Also check: \$mail->ErrorInfo = " . $mail->ErrorInfo . "\n";
}