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
