<?php

declare(strict_types=1);

namespace src\Business;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private PHPMailer $mailer;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->configureMailer();
    }

    private function configureMailer(): void
    {
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host = MAIL_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = MAIL_ADDR;
            $this->mailer->Password = MAIL_PASS;
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mailPort = 587;
            if ((bool) is_numeric(MAIL_PORT) === true) {
                $mailPort = (int) MAIL_PORT;
                if ($mailPort === 465) {
                    $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                }
            }

            $this->mailer->Port = $mailPort;
            $this->mailer->setFrom(MAIL_ADDR, APP_NAME);
        } catch (Exception $e) {
            error_log("Mailer Error: {$this->mailer->ErrorInfo}");
        }
    }

    public function sendEmailTOTP(string $toEmail, string $totp): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail);
            $this->mailer->Subject = APP_NAME . ' - Your OTP code has arrived';
            $this->mailer->Body = sprintf(
                "To continue verifying your account ownership for %1s, copy or enter this " .
                "single time use OTP code: <strong>%2\$d</strong>.<br /><br />" .

                "Please do not share this code and your email address with anyone.<br /><br />" .

                "If you did not request this code you can safely ignore this email.<br /><br />" .

                "If you experience spam we recommend you to notify us and have us " .
                "(temporarily) blacklist your email address by writing us at: " .
                "<a href='mailto:%3\$s?subject=Blacklist my email'>%3\$s</a> .",
                "GangsterClub.com",
                (int) $totp,
                "info@gangsterclub.com"
            );

            $this->mailer->isHTML(true);

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Mailer Error: {$this->mailer->ErrorInfo}");
            return false;
        }
    }

    public function sendEmailChangeVerification(string $toEmail, string $newEmail, string $verificationUrl): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail);
            $this->mailer->Subject = APP_NAME . ' - Confirm your email change';
            $this->mailer->Body = sprintf(
                "We received a request to update the email on your %s account to %s.\n\n" .
                "If you initiated this change, confirm it by visiting: %s\n\n" .
                "If you did not request this change you can safely ignore this email.",
                APP_NAME,
                htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8'),
                $verificationUrl
            );
            $this->mailer->isHTML(false);

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Mailer Error: {$this->mailer->ErrorInfo}");
            return false;
        }
    }

    public function sendSecurityNotification(string $toEmail, string $subject, string $message): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail);
            $this->mailer->Subject = APP_NAME . ' - ' . $subject;
            $this->mailer->Body = $message;
            $this->mailer->isHTML(false);

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Mailer Error: {$this->mailer->ErrorInfo}");
            return false;
        }
    }

    public function sendRecoveryCompletionNotification(
        string $toEmail,
        string $purpose,
        bool $lostFlow
    ): void {
        if (in_array($purpose, [
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES,
            AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY,
        ], true) === false) {
            return;
        }

        $this->sendSecurityNotification(
            $toEmail,
            $lostFlow === true ? 'Authenticator replaced' : 'Recovery codes replaced',
            $lostFlow === true
                ? 'Your authenticator and recovery-code set were replaced. Other browser sessions were revoked.'
                : 'A new recovery-code set is active. All previous recovery codes are now invalid.'
        );
    }
}
