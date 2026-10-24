<?php
/**
 * Email utility class using PHPMailer
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    /**
     * Issue-016: maximum length of a user-supplied name we will echo back
     * in a rendered email. Keeps the rendered body compact and bounds the
     * cost of sanitisation.
     */
    const NAME_MAX_LENGTH = 100;

    private $mailer;

    public function __construct() {
        $this->mailer = new PHPMailer(true);

        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USER;
            $this->mailer->Password = SMTP_PASS;
            $this->mailer->SMTPSecure = SMTP_PROTOCOL;
            $this->mailer->Port = SMTP_PORT;

            // Default from address
            $this->mailer->setFrom(SMTP_USER, APP_NAME);
        } catch (Exception $e) {
            error_log("Email configuration failed: " . $e->getMessage());
            throw new Exception("Email service unavailable");
        }
    }

    public function sendPasswordResetEmail($to_email, $to_name, $reset_token) {
        try {
            $this->mailer->clearAddresses();
            // PHPMailer escapes the recipient display name internally for
            // the envelope, but the visible body is rendered below.
            $this->mailer->addAddress($to_email, self::sanitizePlaintextName($to_name));

            $reset_url = APP_URL . "/reset-password?token=" . $reset_token;
            $rendered  = self::renderPasswordResetBody($to_name, $reset_url);

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Password Reset Request - ' . APP_NAME;
            $this->mailer->Body    = $rendered['html'];
            $this->mailer->AltBody = $rendered['plain'];

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Failed to send password reset email: " . $e->getMessage());
            return false;
        }
    }

    public function sendWelcomeEmail($to_email, $to_name, $reset_token) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to_email, self::sanitizePlaintextName($to_name));

            $reset_url = APP_URL . "/reset-password?token=" . $reset_token;
            $rendered  = self::renderWelcomeBody($to_name, $reset_url);

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Welcome to ' . APP_NAME . ' - Set Your Password';
            $this->mailer->Body    = $rendered['html'];
            $this->mailer->AltBody = $rendered['plain'];

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Failed to send welcome email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Issue-016 (Section C — Email HTML injection).
     *
     * Build the password-reset email body from a name and a reset URL.
     * Static + public so it can be unit-tested without instantiating
     * PHPMailer. The name is escaped for HTML and stripped of control
     * characters for the plaintext part to prevent header / body
     * injection when an attacker controls the user's display name.
     *
     * @param string $name      raw user display name
     * @param string $reset_url fully-qualified reset URL
     * @return array{html:string,plain:string}
     */
    public static function renderPasswordResetBody($name, $reset_url) {
        $safe_name_html  = self::sanitizeHtmlName($name);
        $safe_name_plain = self::sanitizePlaintextName($name);

        $html = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #007bff;'>Password Reset Request</h2>
                <p>Hello <strong>{$safe_name_html}</strong>,</p>
                <p>You requested a password reset for your " . APP_NAME . " account.</p>
                <p>Click the button below to reset your password:</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='" . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . "' style='background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a>
                </p>
                <p><strong>Important:</strong> This link will expire in 1 hour for security reasons.</p>
                <p>If you didn't request this password reset, please ignore this email. Your password will remain unchanged.</p>
                <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
                <p style='color: #666; font-size: 12px;'>This email was sent by " . APP_NAME . "</p>
            </div>
        </body>
        </html>";

        $plain = "Hello {$safe_name_plain},\n\n"
               . "You requested a password reset. Click the following link to reset your password:\n"
               . $reset_url . "\n\n"
               . "This link will expire in 1 hour.\n\n"
               . "If you didn't request this, please ignore this email.";

        return ['html' => $html, 'plain' => $plain];
    }

    /**
     * Issue-016 (Section C — Email HTML injection).
     *
     * Build the welcome email body. Same sanitisation rules as the
     * password reset body — see {@see renderPasswordResetBody()}.
     *
     * @param string $name      raw user display name
     * @param string $reset_url fully-qualified set-password URL
     * @return array{html:string,plain:string}
     */
    public static function renderWelcomeBody($name, $reset_url) {
        $safe_name_html  = self::sanitizeHtmlName($name);
        $safe_name_plain = self::sanitizePlaintextName($name);

        $html = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #28a745;'>Welcome to " . APP_NAME . "!</h2>
                <p>Hello <strong>{$safe_name_html}</strong>,</p>
                <p>Your account has been created successfully! Welcome to " . APP_NAME . ".</p>
                <p>To get started, please set your password by clicking the button below:</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='" . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . "' style='background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>Set Password</a>
                </p>
                <p><strong>Important:</strong> This link will expire in 1 hour for security reasons.</p>
                <p>Once you've set your password, you'll be able to:</p>
                <ul>
                    <li>Create and manage projects</li>
                    <li>Track tasks and subtasks</li>
                    <li>Manage project contacts</li>
                    <li>Monitor project progress</li>
                </ul>
                <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
                <p style='color: #666; font-size: 12px;'>This email was sent by " . APP_NAME . "</p>
            </div>
        </body>
        </html>";

        $plain = "Welcome to " . APP_NAME . ", {$safe_name_plain}!\n\n"
               . "Your account has been created successfully. Please set your password by clicking the following link:\n"
               . $reset_url . "\n\n"
               . "This link will expire in 1 hour.";

        return ['html' => $html, 'plain' => $plain];
    }

    /**
     * Escape a name for safe inclusion in an HTML body, then truncate.
     * Returns a non-empty safe string even if $name is null/empty.
     */
    private static function sanitizeHtmlName($name) {
        $str = is_string($name) ? $name : '';
        $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
        if (function_exists('mb_substr')) {
            $str = mb_substr($str, 0, self::NAME_MAX_LENGTH, 'UTF-8');
        } else {
            $str = substr($str, 0, self::NAME_MAX_LENGTH);
        }
        return $str === '' ? 'there' : $str;
    }

    /**
     * Strip ASCII control characters (incl. CR/LF used for header
     * injection) and stray Unicode line terminators, trim, truncate,
     * and fall back to "there" so the rendered greeting is never empty.
     */
    private static function sanitizePlaintextName($name) {
        $str = is_string($name) ? $name : '';
        // Drop ASCII control chars 0x00-0x1F and 0x7F. The /u modifier
        // lets the pattern also drop U+2028 / U+2029 line terminators.
        $str = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $str);
        $str = trim((string) $str);
        if (function_exists('mb_substr')) {
            $str = mb_substr($str, 0, self::NAME_MAX_LENGTH, 'UTF-8');
        } else {
            $str = substr($str, 0, self::NAME_MAX_LENGTH);
        }
        return $str === '' ? 'there' : $str;
    }
}
?>
