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

function fbgEmailEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fbgRenderSiteEmail(array $options): string
{
    $brandName = fbgEmailEscape(trim((string)($options['brand_name'] ?? 'Frostbyt3 Gaming')) ?: 'Frostbyt3 Gaming');
    $greetingName = fbgEmailEscape(trim((string)($options['greeting_name'] ?? 'there')) ?: 'there');
    $headline = fbgEmailEscape(trim((string)($options['headline'] ?? '')));
    $bodyHtml = (string)($options['body_html'] ?? '');
    $actionUrl = trim((string)($options['action_url'] ?? ''));
    $actionLabel = fbgEmailEscape(trim((string)($options['action_label'] ?? 'Open')));
    $panelHtml = (string)($options['panel_html'] ?? '');
    $afterActionHtml = (string)($options['after_action_html'] ?? '');
    $footerNoteHtml = (string)($options['footer_note_html'] ?? '');
    $safeActionUrl = fbgEmailEscape($actionUrl);

    $actionHtml = $actionUrl !== '' ? '
                        <tr>
                            <td align="center" style="padding:28px 32px 22px;">
                                <a href="' . $safeActionUrl . '" style="background-color:#0067a3;border-radius:10px;color:#ffffff;display:inline-block;font-size:14px;font-weight:700;line-height:17px;padding:14px 24px;text-align:center;text-transform:uppercase;text-decoration:none;letter-spacing:.04em;">
                                    ' . $actionLabel . '
                                </a>
                            </td>
                        </tr>' : '';

    $fallbackHtml = $actionUrl !== '' ? '
                                If you are having trouble clicking the "' . $actionLabel . '" button, copy and paste the URL below into your browser:<br><br>
                                <a href="' . $safeActionUrl . '" style="color:#22aeff;text-decoration:none;">' . $safeActionUrl . '</a><br><br>' : '';

    return '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background-color:#0d1117;font-family:Arial,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="color:#eeeeee;background-color:#0d1117;padding:24px 0;">
            <tr>
                <td align="center" style="padding:0 12px;">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#171c22;border:1px solid #2d333b;border-radius:14px;color:#eeeeee;padding:0;max-width:600px;width:100%;">
                        <tr>
                            <td align="center" style="padding:32px 32px 12px;">
                                <img src="https://frostbyt3gaming.com/backend/uplimg/29e3a1d2f6279265483296d8d3e829c5.png" height="96" width="96" alt="Frostbyt3 Gaming" style="display:block;border:0;outline:none;text-decoration:none;">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:0 32px 8px;font-size:22px;font-weight:bold;line-height:1.25;color:#ffffff;">' . $brandName . '</td>
                        </tr>
                        <tr>
                            <td style="padding:0 32px 18px;font-size:16px;line-height:1.5;color:#eeeeee;">Hello ' . $greetingName . ',</td>
                        </tr>
                        ' . ($headline !== '' ? '
                        <tr>
                            <td style="padding:0 32px 12px;font-size:26px;font-weight:bold;line-height:1.25;color:#ffffff;">' . $headline . '</td>
                        </tr>' : '') . '
                        <tr>
                            <td style="padding:0 32px 22px;font-size:15px;line-height:1.65;color:#cfcfcf;">' . $bodyHtml . '</td>
                        </tr>
                        ' . $panelHtml . '
                        ' . $actionHtml . '
                        ' . $afterActionHtml . '
                        <tr>
                            <td style="padding:0 32px 24px;font-size:14px;line-height:1.6;color:#cfcfcf;">
                                Regards,<br>
                                Frostbyt3 Gaming, LLC.
                            </td>
                        </tr>
                        <tr>
                            <td style="border-top:1px solid #2d333b;padding:20px 32px 30px;font-size:12px;line-height:1.55;color:#9a9a9a;">
                                ' . $fallbackHtml . '
                                ' . $footerNoteHtml . '
                                Copyright 2026 &copy; Frostbyt3 Gaming, LLC. All Rights Reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}

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

    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => 'Verify your email address',
        'body_html' => 'Thanks for signing up for Frostbyt3 Gaming. Click the button below to verify your email address.',
        'action_url' => $verificationUrl,
        'action_label' => 'Verify Email',
        'footer_note_html' => 'If you did not create this account, you can safely ignore this email.<br><br>',
    ]);

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
    $subject = 'Verify your new Frostbyt3 Gaming email';
    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => 'Verify your new email address',
        'body_html' => 'Click the button below to confirm this as your new account email address.',
        'action_url' => $verificationUrl,
        'action_label' => 'Verify Email',
        'footer_note_html' => 'If you did not request this change, you can safely ignore this email.<br><br>',
    ]);

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
    $subject = 'Complete your Frostbyt3 Gaming account';
    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => 'Complete your account',
        'body_html' => 'Your registration has been approved. Click the button below to set your password and finish creating your account.',
        'action_url' => $completionUrl,
        'action_label' => 'Set Password',
        'footer_note_html' => 'If you did not request this account, you can safely ignore this email.<br><br>',
    ]);

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
    $safeServerName = fbgEmailEscape($serverName);
    $safeExpiryDisplay = fbgEmailEscape($expiresAtDisplay);

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

    $panelHtml = '
                        <tr>
                            <td style="padding:0 32px 4px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #2d333b;border-left:4px solid #22aeff;border-radius:10px;color:#eeeeee;">
                                    <tr>
                                        <td style="padding:16px 18px;">
                                            <span style="display:block;color:#9a9a9a;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;padding-bottom:6px;">Server Name</span>
                                            <span style="display:block;color:#ffffff;font-size:16px;font-weight:bold;line-height:1.4;">' . $safeServerName . '</span>
                                            ' . ($expiryLine !== '' ? '<span style="display:block;color:#9fd39f;font-size:13px;line-height:1.5;padding-top:8px;">' . $expiryLine . '</span>' : '') . '
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>';

    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => $lead,
        'body_html' => fbgEmailEscape($body),
        'panel_html' => $panelHtml,
        'action_url' => $settingsUrl,
        'action_label' => 'Manage Server',
    ]);

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
    $safeServerName = fbgEmailEscape($serverName);
    $safeExpiryDisplay = fbgEmailEscape($expiresAtDisplay);

    $subject = $serverName . ' has expired';

    if ($deleteDays > 0) {
        $warningCopy = 'If you do not renew it within ' . $deleteDays . ' day' . ($deleteDays === 1 ? '' : 's') . ', it will be deleted automatically and its data will be lost.';
    } else {
        $warningCopy = 'It is now eligible for immediate deletion, and its data may be removed at any time.';
    }

    $panelHtml = '
                        <tr>
                            <td style="padding:0 32px 4px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #532427;border-left:4px solid #ff5252;border-radius:10px;color:#eeeeee;">
                                    <tr>
                                        <td style="padding:16px 18px;">
                                            <span style="display:block;color:#ffb0b0;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;padding-bottom:6px;">Expired Server</span>
                                            <span style="display:block;color:#ffffff;font-size:16px;font-weight:bold;line-height:1.4;">' . $safeServerName . '</span>
                                            ' . ($expiresAtDisplay !== '' ? '<span style="display:block;color:#cfcfcf;font-size:13px;line-height:1.5;padding-top:8px;">Expired on ' . $safeExpiryDisplay . '.</span>' : '') . '
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>';

    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => 'Your server has expired',
        'body_html' => fbgEmailEscape($warningCopy),
        'panel_html' => $panelHtml,
        'action_url' => $settingsUrl,
        'action_label' => 'Renew Server',
    ]);

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

function fbgSendReceiptEmail(array $receipt, string $receiptUrl): bool
{
    $toEmail = trim((string)($receipt['customer_email'] ?? ''));
    $customerName = trim((string)($receipt['customer_name'] ?? $receipt['customer_username'] ?? ''));
    $receiptNumber = trim((string)($receipt['receipt_number'] ?? ''));
    $currency = trim((string)($receipt['currency'] ?? 'USD')) ?: 'USD';

    if ($toEmail === '' || $receiptNumber === '' || $receiptUrl === '') {
        return false;
    }

    $greetingName = $customerName !== '' ? $customerName : 'there';
    $companyName = trim((string)($receipt['company_name'] ?? 'Frostbyt3 Gaming')) ?: 'Frostbyt3 Gaming';
    $safeReceiptNumber = fbgEmailEscape($receiptNumber);
    $getReceiptMailSetting = static function (string $key, string $default = ''): string {
        if (function_exists('fbgGetSetting')) {
            return trim((string)fbgGetSetting($key, $default));
        }

        return trim($default);
    };
    $fromEmail = $getReceiptMailSetting('fbg_receipt_mail_from_email', defined('SMTP_FROM_EMAIL') ? (string)SMTP_FROM_EMAIL : '');
    $fromName = $getReceiptMailSetting('fbg_receipt_mail_from_name', defined('SMTP_FROM_NAME') ? (string)SMTP_FROM_NAME : '');
    $replyToEmail = $getReceiptMailSetting('fbg_receipt_mail_reply_to_email');
    $replyToName = $getReceiptMailSetting('fbg_receipt_mail_reply_to_name');

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
    $receiptPdfFilename = (preg_replace('/[^A-Za-z0-9._-]+/', '-', $receiptNumber) ?: 'receipt') . '.pdf';
    $formatMoney = static function ($amount) use ($currency): string {
        return function_exists('fbgFormatCredit')
            ? fbgFormatCredit((float)$amount, $currency)
            : number_format((float)$amount, 2) . ' ' . $currency;
    };

    $lineRows = '';
    foreach (($receipt['line_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $lineRows .= '
            <tr>
                <td style="padding:10px 0;border-bottom:1px solid #2d333b;color:#ffffff;">' . fbgEmailEscape((string)($item['description'] ?? '')) . '</td>
                <td align="right" style="padding:10px 0;border-bottom:1px solid #2d333b;color:#cfcfcf;">' . fbgEmailEscape($formatMoney($item['line_total'] ?? 0)) . '</td>
            </tr>';
    }

    if ($lineRows === '') {
        $lineRows = '
            <tr>
                <td style="padding:10px 0;border-bottom:1px solid #2d333b;color:#ffffff;">Frostbyt3 Gaming receipt</td>
                <td align="right" style="padding:10px 0;border-bottom:1px solid #2d333b;color:#cfcfcf;">' . fbgEmailEscape($formatMoney($receipt['total'] ?? 0)) . '</td>
            </tr>';
    }

    $hasTax = round((float)($receipt['tax_rate'] ?? 0), 4) > 0 || round((float)($receipt['tax_amount'] ?? 0), 2) > 0;
    $taxLabel = trim((string)($receipt['tax_label'] ?? 'Tax')) ?: 'Tax';
    $taxRow = $hasTax ? '
        <tr>
            <td style="padding:6px 0;color:#cfcfcf;">' . fbgEmailEscape($taxLabel) . ' ' . fbgEmailEscape(number_format((float)($receipt['tax_rate'] ?? 0), 2)) . '%</td>
            <td align="right" style="padding:6px 0;color:#ffffff;">' . fbgEmailEscape($formatMoney($receipt['tax_amount'] ?? 0)) . '</td>
        </tr>' : '';

    $subject = 'Your Frostbyt3 Gaming receipt ' . $receiptNumber;

    $panelHtml = '
                        <tr>
                            <td style="padding:0 32px 4px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #2d333b;border-left:4px solid #22aeff;border-radius:10px;padding:14px 18px;">
                                    ' . $lineRows . '
                                    <tr>
                                        <td style="padding:14px 0 6px;color:#cfcfcf;">Subtotal</td>
                                        <td align="right" style="padding:14px 0 6px;color:#ffffff;">' . fbgEmailEscape($formatMoney($receipt['subtotal'] ?? 0)) . '</td>
                                    </tr>
                                    ' . $taxRow . '
                                    <tr>
                                        <td style="padding:10px 0 0;color:#ffffff;font-weight:bold;">Total</td>
                                        <td align="right" style="padding:10px 0 0;color:#ffffff;font-weight:bold;">' . fbgEmailEscape($formatMoney($receipt['total'] ?? 0)) . '</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>';

    $htmlMessage = fbgRenderSiteEmail([
        'brand_name' => $companyName,
        'greeting_name' => $greetingName,
        'headline' => 'Your receipt is ready',
        'body_html' => 'Your receipt <strong style="color:#ffffff;">' . $safeReceiptNumber . '</strong> is ready. A PDF copy is attached for your records.',
        'panel_html' => $panelHtml,
        'action_url' => $receiptUrl,
        'action_label' => 'View Receipt',
    ]);

    $plainLines = [];
    foreach (($receipt['line_items'] ?? []) as $item) {
        if (is_array($item)) {
            $plainLines[] = '- ' . (string)($item['description'] ?? 'Receipt item') . ': ' . $formatMoney($item['line_total'] ?? 0);
        }
    }

    $plainMessage = "Hey {$greetingName},\n\n"
        . "Your Frostbyt3 Gaming receipt {$receiptNumber} is ready.\n\n"
        . (!empty($plainLines) ? implode("\n", $plainLines) . "\n\n" : '')
        . "Subtotal: " . $formatMoney($receipt['subtotal'] ?? 0) . "\n"
        . ($hasTax ? "{$taxLabel}: " . $formatMoney($receipt['tax_amount'] ?? 0) . "\n" : '')
        . "Total: " . $formatMoney($receipt['total'] ?? 0) . "\n\n"
        . "View your receipt here:\n{$receiptUrl}\n";

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

        if (function_exists('fbgCreateFrontendReceiptPdf')) {
            $mail->addStringAttachment(
                fbgCreateFrontendReceiptPdf($receipt),
                $receiptPdfFilename,
                'base64',
                'application/pdf'
            );
        }

        return $mail->send();
    } catch (Exception $e) {
        error_log('Receipt email failed: ' . $e->getMessage());
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
    $safeServerName = fbgEmailEscape($serverName);
    $subject = $variant['subject'];

    $panelHtml = '
                        <tr>
                            <td style="padding:0 32px 4px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #2d333b;border-left:4px solid #22aeff;border-radius:10px;color:#eeeeee;">
                                    <tr>
                                        <td style="padding:16px 18px;">
                                            <span style="display:block;color:#9a9a9a;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;padding-bottom:6px;">Server Name</span>
                                            <span style="display:block;color:#ffffff;font-size:16px;font-weight:bold;line-height:1.4;">' . $safeServerName . '</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>';

    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => $variant['headline'],
        'body_html' => fbgEmailEscape($variant['body']),
        'panel_html' => $panelHtml,
        'action_url' => $serverPanelUrl,
        'action_label' => $variant['button'],
    ]);

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

function fbgSendServerSubuserAccessEmail(array $data): bool
{
    $toEmail = trim((string)($data['to_email'] ?? ''));
    $serverName = trim((string)($data['server_name'] ?? ''));
    $serverPanelUrl = trim((string)($data['server_panel_url'] ?? ''));
    $type = strtolower(trim((string)($data['type'] ?? 'added')));

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL) || $serverName === '' || $serverPanelUrl === '') {
        return false;
    }

    $displayName = trim((string)($data['display_name'] ?? ''));
    $greetingName = $displayName !== '' ? $displayName : 'there';
    $isUpdate = $type === 'updated';

    $subject = $isUpdate
        ? 'Your Frostbyt3 Gaming server access was updated'
        : 'You have been added to a Frostbyt3 Gaming server';

    $headline = $isUpdate
        ? 'Your server access was updated'
        : 'You have been added to a server';

    $body = $isUpdate
        ? 'Your permissions for this server have been updated. You can log in to Frostbyt3 Gaming to view your current access.'
        : 'You have been added as a user on this server. You can now log in to Frostbyt3 Gaming and access it with the permissions assigned to you.';

    $buttonText = 'Open Server Panel';

    $safeServerName = fbgEmailEscape($serverName);
    $panelHtml = '
                        <tr>
                            <td style="padding:0 32px 4px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#111;border:1px solid #2d333b;border-left:4px solid #22aeff;border-radius:10px;color:#eeeeee;">
                                    <tr>
                                        <td style="padding:16px 18px;">
                                            <span style="display:block;color:#9a9a9a;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;padding-bottom:6px;">Server Name</span>
                                            <span style="display:block;color:#ffffff;font-size:16px;font-weight:bold;line-height:1.4;">' . $safeServerName . '</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>';

    $htmlMessage = fbgRenderSiteEmail([
        'greeting_name' => $greetingName,
        'headline' => $headline,
        'body_html' => fbgEmailEscape($body),
        'panel_html' => $panelHtml,
        'action_url' => $serverPanelUrl,
        'action_label' => $buttonText,
    ]);

    $plainMessage = "Hello {$greetingName},\n\n"
        . "{$body}\n\n"
        . "Server Name: {$serverName}\n\n"
        . "{$buttonText}:\n{$serverPanelUrl}\n\n"
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
        error_log('Server subuser access email failed: ' . $e->getMessage());
        return false;
    }
}
