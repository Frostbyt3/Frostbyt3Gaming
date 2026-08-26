<?php
declare(strict_types=1);

// mailer.php

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/error-handling.php';

require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function fbgSendVerificationEmail(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $verificationUrl = trim((string)($data['verification_url'] ?? ''));

    if ($toEmail === '' || $verificationUrl === '') {
        return false;
    }

    $greetingName = $firstName !== '' ? $firstName : 'there';

    $subject = 'Verify your Frostbyt3 Gaming account';

    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color: #f2f2f2; background-color: #1c1c1c; padding: 20px 0; border: 2px solid rgba(255, 255, 255, 0.08);">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border-radius:12px;padding:30px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:10px;">
                                Frostbyt3 Gaming
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:20px;">
                                Hey ' . htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8') . ',
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:20px;color:#cccccc;">
                                Thanks for signing up for Frostbyt3 Gaming. Click the button below to verify your email address.
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding:20px 0;">
                                <a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '" style="-webkit-appearance: none; -moz-appearance: none; appearanace: none; background-color: #0067a3; border: none; border-radius: 10px; color: #ffffff; cursor: pointer; display: inline-block; font-size: 14px; font-weight: 600; line-height: 17px; outline: 0; padding: 19px 31px; text-align: center; text-transform: uppercase; text-decoration: none; letter-spacing: .05em;">
                                    Verify Email
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If the button does not work, use this link:<br><br>
                                <a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#22aeff;">' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '</a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If you did not create this account, you can safely ignore this email.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainMessage = "Hey {$greetingName},\n\n"
        . "Thanks for signing up for Frostbyt3 Gaming.\n\n"
        . "Verify your email address here:\n\n"
        . $verificationUrl . "\n\n"
        . "If you did not create this account, you can ignore this email.\n";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        /* $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'cafile' => 'H:\\xampp\\php\\extras\\ssl\\cacert.pem',
            ],
        ]; */

        return $mail->send();
    } catch (Exception $e) {
        error_log('Verification email failed: ' . $e->getMessage());
        throw $e;
        return false;
    }
}

function fbgSendAccountEmailChangeVerification(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $verificationUrl = trim((string)($data['verification_url'] ?? ''));

    if ($toEmail === '' || $verificationUrl === '') {
        return false;
    }

    $greetingName = $firstName !== '' ? $firstName : 'there';
    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Verify your new Frostbyt3 Gaming email';
    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#f2f2f2;background-color:#1c1c1c;padding:20px 0;border:2px solid rgba(255,255,255,0.08);">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border-radius:12px;padding:30px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:10px;">Frostbyt3 Gaming</td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:20px;">Hey ' . $safeName . ',</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:20px;color:#cccccc;">
                                Click the button below to confirm this as your new account email address.
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding:20px 0;">
                                <a href="' . $safeUrl . '" style="background-color:#0067a3;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:600;line-height:17px;padding:19px 31px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.05em;">
                                    Verify Email
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If the button does not work, use this link:<br><br>
                                <a href="' . $safeUrl . '" style="color:#22aeff;">' . $safeUrl . '</a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If you did not request this change, you can safely ignore this email.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainMessage = "Hey {$greetingName},\n\n"
        . "Confirm this as your new Frostbyt3 Gaming account email:\n\n"
        . $verificationUrl . "\n\n"
        . "If you did not request this change, you can ignore this email.\n";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Account email change verification failed: ' . $e->getMessage());
        throw $e;
    }
}

function fbgSendRegistrationCompletionEmail(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $completionUrl = trim((string)($data['completion_url'] ?? ''));

    if ($toEmail === '' || $completionUrl === '') {
        return false;
    }

    $greetingName = $firstName !== '' ? $firstName : 'there';
    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($completionUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Complete your Frostbyt3 Gaming account';
    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#f2f2f2;background-color:#1c1c1c;padding:20px 0;border:2px solid rgba(255,255,255,0.08);">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border-radius:12px;padding:30px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:10px;">Frostbyt3 Gaming</td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:20px;">Hey ' . $safeName . ',</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:20px;color:#cccccc;">
                                Your registration has been approved. Click the button below to set your password and finish creating your account.
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding:20px 0;">
                                <a href="' . $safeUrl . '" style="background-color:#0067a3;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:600;line-height:17px;padding:19px 31px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.05em;">
                                    Set Password
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If the button does not work, use this link:<br><br>
                                <a href="' . $safeUrl . '" style="color:#22aeff;">' . $safeUrl . '</a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If you did not request this account, you can safely ignore this email.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainMessage = "Hey {$greetingName},\n\n"
        . "Your Frostbyt3 Gaming registration has been approved.\n\n"
        . "Set your password and complete your account here:\n\n"
        . $completionUrl . "\n\n"
        . "If you did not request this account, you can ignore this email.\n";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Registration completion email failed: ' . $e->getMessage());
        throw $e;
    }
}

function fbgSendServerExpiryReminderEmail(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $serverName = trim((string)($data['server_name'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $settingsUrl = trim((string)($data['settings_url'] ?? ''));
    $daysRemaining = max(0, (int)($data['days_remaining'] ?? 0));
    $expiresAtDisplay = trim((string)($data['expires_at_display'] ?? ''));

    if ($toEmail === '' || $serverName === '' || $settingsUrl === '') {
        return false;
    }

    $greetingName = $firstName !== '' ? $firstName : 'there';
    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    $safeServerName = htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8');
    $safeSettingsUrl = htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8');
    $safeExpiryDisplay = htmlspecialchars($expiresAtDisplay, ENT_QUOTES, 'UTF-8');

    if ($daysRemaining <= 0) {
        $subject = $serverName . ' expires today';
        $lead = 'Your server expires today.';
        $body = 'Renew it today to keep it online and avoid service interruption.';
    } elseif ($daysRemaining === 1) {
        $subject = $serverName . ' expires in 1 day';
        $lead = 'Your server expires in 1 day.';
        $body = 'Renew it now to keep everything running smoothly.';
    } else {
        $subject = $serverName . ' expires in ' . $daysRemaining . ' days';
        $lead = 'Your server expires in ' . $daysRemaining . ' days.';
        $body = 'Renew it before the expiration date to keep your server and data active.';
    }

    $expiryLine = $expiresAtDisplay !== ''
        ? 'Current expiration date: ' . $safeExpiryDisplay . '.'
        : '';

    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#f2f2f2;background-color:#1c1c1c;padding:20px 0;border:2px solid rgba(255,255,255,0.08);">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border-radius:12px;padding:30px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:10px;">Frostbyt3 Gaming</td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:20px;">Hey ' . $safeName . ',</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:16px;color:#cccccc;">
                                <strong>' . $safeServerName . '</strong> is getting close to its expiration date.
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:12px;color:#cccccc;">
                                ' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '
                            </td>
                        </tr>
                        ' . ($expiryLine !== '' ? '
                        <tr>
                            <td style="font-size:13px;padding-bottom:12px;color:#9fd39f;">' . $expiryLine . '</td>
                        </tr>' : '') . '
                        <tr>
                            <td align="center" style="padding:20px 0;">
                                <a href="' . $safeSettingsUrl . '" style="background-color:#0067a3;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:600;line-height:17px;padding:19px 31px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.05em;">
                                    Manage Server
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If the button does not work, use this link:<br><br>
                                <a href="' . $safeSettingsUrl . '" style="color:#22aeff;">' . $safeSettingsUrl . '</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainMessage = "Hey {$greetingName},\n\n"
        . "{$serverName} is getting close to its expiration date.\n"
        . $lead . ' ' . $body . "\n\n"
        . ($expiresAtDisplay !== '' ? "Current expiration date: {$expiresAtDisplay}\n\n" : '')
        . "Manage your server here:\n{$settingsUrl}\n";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Server expiry reminder email failed: ' . $e->getMessage());
        throw $e;
    }
}

function fbgSendServerExpiredEmail(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $serverName = trim((string)($data['server_name'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $settingsUrl = trim((string)($data['settings_url'] ?? ''));
    $deleteDays = max(0, (int)($data['delete_days'] ?? 0));
    $expiresAtDisplay = trim((string)($data['expires_at_display'] ?? ''));

    if ($toEmail === '' || $serverName === '' || $settingsUrl === '') {
        return false;
    }

    $greetingName = $firstName !== '' ? $firstName : 'there';
    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    $safeServerName = htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8');
    $safeSettingsUrl = htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8');
    $safeExpiryDisplay = htmlspecialchars($expiresAtDisplay, ENT_QUOTES, 'UTF-8');

    $subject = $serverName . ' has expired';

    if ($deleteDays > 0) {
        $warningCopy = 'If you do not renew it within ' . $deleteDays . ' day' . ($deleteDays === 1 ? '' : 's') . ', it will be deleted automatically and its data will be lost.';
    } else {
        $warningCopy = 'It is now eligible for immediate deletion, and its data may be removed at any time.';
    }

    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#f2f2f2;background-color:#1c1c1c;padding:20px 0;border:2px solid rgba(255,255,255,0.08);">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border-radius:12px;padding:30px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:10px;">Frostbyt3 Gaming</td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:20px;">Hey ' . $safeName . ',</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:16px;color:#cccccc;">
                                <strong>' . $safeServerName . '</strong> has expired.
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:12px;color:#ffb0b0;">
                                ' . htmlspecialchars($warningCopy, ENT_QUOTES, 'UTF-8') . '
                            </td>
                        </tr>
                        ' . ($expiresAtDisplay !== '' ? '
                        <tr>
                            <td style="font-size:13px;padding-bottom:12px;color:#cccccc;">Expired on ' . $safeExpiryDisplay . '.</td>
                        </tr>' : '') . '
                        <tr>
                            <td align="center" style="padding:20px 0;">
                                <a href="' . $safeSettingsUrl . '" style="background-color:#0067a3;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:600;line-height:17px;padding:19px 31px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.05em;">
                                    Renew Server
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:20px;">
                                If the button does not work, use this link:<br><br>
                                <a href="' . $safeSettingsUrl . '" style="color:#22aeff;">' . $safeSettingsUrl . '</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainMessage = "Hey {$greetingName},\n\n"
        . "{$serverName} has expired.\n"
        . $warningCopy . "\n\n"
        . ($expiresAtDisplay !== '' ? "Expired on {$expiresAtDisplay}.\n\n" : '')
        . "Renew your server here:\n{$settingsUrl}\n";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Server expired email failed: ' . $e->getMessage());
        throw $e;
    }
}

function fbgSendInvoiceEmail(array $invoice, string $invoiceUrl): bool
{
    $toEmail = trim((string)($invoice['customer_email'] ?? ''));
    $customerName = trim((string)($invoice['customer_name'] ?? $invoice['customer_username'] ?? ''));
    $invoiceNumber = trim((string)($invoice['invoice_number'] ?? ''));
    $currency = trim((string)($invoice['currency'] ?? 'USD')) ?: 'USD';

    if ($toEmail === '' || $invoiceNumber === '' || $invoiceUrl === '') {
        return false;
    }

    $greetingName = $customerName !== '' ? $customerName : 'there';
    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    $safeInvoiceNumber = htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8');
    $safeInvoiceUrl = htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8');
    $companyName = trim((string)($invoice['company_name'] ?? 'Frostbyt3 Gaming')) ?: 'Frostbyt3 Gaming';
    $safeCompanyName = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $getInvoiceMailSetting = static function (string $key, string $default = ''): string {
        if (function_exists('fbgGetSetting')) {
            return trim((string)fbgGetSetting($key, $default));
        }

        return trim($default);
    };
    $fromEmail = $getInvoiceMailSetting('fbg_invoice_mail_from_email', defined('SMTP_FROM_EMAIL') ? (string)SMTP_FROM_EMAIL : '');
    $fromName = $getInvoiceMailSetting('fbg_invoice_mail_from_name', defined('SMTP_FROM_NAME') ? (string)SMTP_FROM_NAME : '');
    $replyToEmail = $getInvoiceMailSetting('fbg_invoice_mail_reply_to_email');
    $replyToName = $getInvoiceMailSetting('fbg_invoice_mail_reply_to_name');

    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = defined('SMTP_FROM_EMAIL') ? (string)SMTP_FROM_EMAIL : '';
    }

    if ($fromName === '') {
        $fromName = defined('SMTP_FROM_NAME') ? (string)SMTP_FROM_NAME : $companyName;
    }

    if ($replyToEmail !== '' && !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $replyToEmail = '';
        $replyToName = '';
    }
    $invoicePdfFilename = (preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoiceNumber) ?: 'invoice') . '.pdf';
    $formatMoney = static function ($amount) use ($currency): string {
        return function_exists('fbgFormatCredit')
            ? fbgFormatCredit((float)$amount, $currency)
            : number_format((float)$amount, 2) . ' ' . $currency;
    };

    $lineRows = '';
    foreach (($invoice['line_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $lineRows .= '
            <tr>
                <td style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#ffffff;">' . htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
                <td align="right" style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#cccccc;">' . htmlspecialchars($formatMoney($item['line_total'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
    }

    if ($lineRows === '') {
        $lineRows = '
            <tr>
                <td style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#ffffff;">Frostbyt3 Gaming invoice</td>
                <td align="right" style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#cccccc;">' . htmlspecialchars($formatMoney($invoice['total'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
    }

    $hasTax = round((float)($invoice['tax_rate'] ?? 0), 4) > 0 || round((float)($invoice['tax_amount'] ?? 0), 2) > 0;
    $taxLabel = trim((string)($invoice['tax_label'] ?? 'Tax')) ?: 'Tax';
    $taxRow = $hasTax ? '
        <tr>
            <td style="padding:6px 0;color:#cccccc;">' . htmlspecialchars($taxLabel, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars(number_format((float)($invoice['tax_rate'] ?? 0), 2), ENT_QUOTES, 'UTF-8') . '%</td>
            <td align="right" style="padding:6px 0;color:#ffffff;">' . htmlspecialchars($formatMoney($invoice['tax_amount'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
        </tr>' : '';

    $subject = 'Your Frostbyt3 Gaming invoice ' . $invoiceNumber;

    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#f2f2f2;background-color:#1c1c1c;padding:20px 0;border:2px solid rgba(255,255,255,0.08);">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border-radius:12px;padding:30px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:10px;">' . $safeCompanyName . '</td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:20px;">Hey ' . $safeName . ',</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;padding-bottom:18px;color:#cccccc;">
                                Your invoice <strong style="color:#ffffff;">' . $safeInvoiceNumber . '</strong> is ready.
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#141414;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px 18px;">
                                    ' . $lineRows . '
                                    <tr>
                                        <td style="padding:14px 0 6px;color:#cccccc;">Subtotal</td>
                                        <td align="right" style="padding:14px 0 6px;color:#ffffff;">' . htmlspecialchars($formatMoney($invoice['subtotal'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
                                    </tr>
                                    ' . $taxRow . '
                                    <tr>
                                        <td style="padding:10px 0 0;color:#ffffff;font-weight:bold;">Total</td>
                                        <td align="right" style="padding:10px 0 0;color:#ffffff;font-weight:bold;">' . htmlspecialchars($formatMoney($invoice['total'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding:24px 0 18px;">
                                <a href="' . $safeInvoiceUrl . '" style="background-color:#0067a3;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:600;line-height:17px;padding:16px 28px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.05em;">
                                    View Invoice
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:12px;color:#888888;padding-top:12px;">
                                If the button does not work, use this link:<br><br>
                                <a href="' . $safeInvoiceUrl . '" style="color:#22aeff;">' . $safeInvoiceUrl . '</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainLines = [];
    foreach (($invoice['line_items'] ?? []) as $item) {
        if (is_array($item)) {
            $plainLines[] = '- ' . (string)($item['description'] ?? 'Invoice item') . ': ' . $formatMoney($item['line_total'] ?? 0);
        }
    }

    $plainMessage = "Hey {$greetingName},\n\n"
        . "Your Frostbyt3 Gaming invoice {$invoiceNumber} is ready.\n\n"
        . (!empty($plainLines) ? implode("\n", $plainLines) . "\n\n" : '')
        . "Subtotal: " . $formatMoney($invoice['subtotal'] ?? 0) . "\n"
        . ($hasTax ? "{$taxLabel}: " . $formatMoney($invoice['tax_amount'] ?? 0) . "\n" : '')
        . "Total: " . $formatMoney($invoice['total'] ?? 0) . "\n\n"
        . "View your invoice here:\n{$invoiceUrl}\n";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName);
        if ($replyToEmail !== '') {
            $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $fromName);
        }
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        if (function_exists('fbgCreateFrontendInvoicePdf')) {
            $mail->addStringAttachment(
                fbgCreateFrontendInvoicePdf($invoice),
                $invoicePdfFilename,
                'base64',
                'application/pdf'
            );
        }

        return $mail->send();
    } catch (Exception $e) {
        error_log('Invoice email failed: ' . $e->getMessage());
        throw $e;
    }
}

function fbgSendServerInstallFinishedEmail(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $serverName = trim((string)($data['server_name'] ?? ''));
    $serverPanelUrl = trim((string)($data['server_panel_url'] ?? ''));
    $type = strtolower(trim((string)($data['type'] ?? 'initial')));

    if ($toEmail === '' || $serverName === '' || $serverPanelUrl === '') {
        return false;
    }

    $variants = [
        'initial' => [
            'subject' => 'Your Frostbyt3 Gaming server is ready',
            'headline' => 'Your server is ready!',
            'body' => 'Your server has finished installing and is ready to use. Click the button below to start your adventure.',
            'button' => 'Log In and Begin Using',
        ],
        'reinstall' => [
            'subject' => 'Your Frostbyt3 Gaming server reinstall is complete',
            'headline' => 'Your server reinstall is complete!',
            'body' => 'Your server has finished reinstalling and is ready to use again. Click the button below to jump back in.',
            'button' => 'Log In and Begin Using',
        ],
        'modpack' => [
            'subject' => 'Your Frostbyt3 Gaming modpack install is complete',
            'headline' => 'Your modpack is ready!',
            'body' => 'Your modpack has finished installing and your server is ready to use. Click the button below to start playing.',
            'button' => 'Log In and Begin Using',
        ],
    ];

    $variant = $variants[$type] ?? $variants['initial'];
    $greetingName = $firstName !== '' ? $firstName : 'there';
    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    $safeServerName = htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8');
    $safeServerPanelUrl = htmlspecialchars($serverPanelUrl, ENT_QUOTES, 'UTF-8');
    $safeHeadline = htmlspecialchars($variant['headline'], ENT_QUOTES, 'UTF-8');
    $safeBody = htmlspecialchars($variant['body'], ENT_QUOTES, 'UTF-8');
    $safeButton = htmlspecialchars($variant['button'], ENT_QUOTES, 'UTF-8');
    $subject = $variant['subject'];

    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#111;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#f2f2f2;background-color:#111;padding:24px 0;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#1e1e1e;border:1px solid #1c1c1c;border-radius:14px;padding:32px;color:#ffffff;">
                        <tr>
                            <td align="center" style="padding-bottom:10px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="128px" width="128px" alt="Frostbyt3 Gaming"></img>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:22px;font-weight:bold;padding-bottom:8px;color:#ffffff;">Frostbyt3 Gaming</td>
                        </tr>
                        <tr>
                            <td style="font-size:16px;padding-bottom:18px;color:#f2f2f2;">Hello ' . $safeName . ',</td>
                        </tr>
                        <tr>
                            <td style="font-size:24px;font-weight:bold;padding-bottom:12px;color:#ffffff;">' . $safeHeadline . '</td>
                        </tr>
                        <tr>
                            <td style="font-size:15px;line-height:1.6;padding-bottom:22px;color:#cccccc;">' . $safeBody . '</td>
                        </tr>
                        <tr>
                            <td style="background:#141414;border-left:4px solid #22aeff;border-radius:10px;padding:16px 18px;font-size:15px;color:#ffffff;">
                                <span style="display:block;color:#9a9a9a;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;padding-bottom:6px;">Server Name</span>
                                ' . $safeServerName . '
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding:28px 0 22px;">
                                <a href="' . $safeServerPanelUrl . '" style="background-color:#007fca;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:700;line-height:17px;padding:16px 28px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.05em;">
                                    ' . $safeButton . '
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;line-height:1.6;color:#cccccc;padding-bottom:22px;">
                                Regards,<br>
                                Frostbyt3 Gaming, LLC.
                            </td>
                        </tr>
                        <tr>
                            <td style="border-top:1px solid #383838;font-size:12px;line-height:1.5;color:#888888;padding-top:20px;">
                                If you are having trouble clicking the "' . $safeButton . '" button, copy and paste the URL below into your browser:<br><br>
                                <a href="' . $safeServerPanelUrl . '" style="color:#22aeff;">' . $safeServerPanelUrl . '</a><br><br>
                                Copyright 2026 &copy; Frostbyt3 Gaming, LLC. All Rights Reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $plainMessage = "Hello {$greetingName},\n\n"
        . $variant['body'] . "\n\n"
        . "Server Name: {$serverName}\n\n"
        . "{$variant['button']}:\n{$serverPanelUrl}\n\n"
        . "Regards,\nFrostbyt3 Gaming, LLC.\n\n"
        . "If you are having trouble opening the link, copy and paste the URL below into your browser:\n\n"
        . "{$serverPanelUrl}\n\n"
        . "Copyright 2026 (c) Frostbyt3 Gaming, LLC. All Rights Reserved.";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;

        $mail->SMTPDebug = fbgIsLocalRequest() ? 2 : 0;
        $mail->Debugoutput = 'error_log';

        if (defined('SMTP_USE_AUTH') && SMTP_USE_AUTH) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        } else {
            $mail->SMTPAuth = false;
        }

        if (defined('SMTP_USE_TLS') && SMTP_USE_TLS) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlMessage;
        $mail->AltBody = $plainMessage;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Server install finished email failed: ' . $e->getMessage());
        throw $e;
    }
}
