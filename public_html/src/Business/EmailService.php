<?php

declare(strict_types=1);

namespace src\Business;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    /**
     * Summary of mailer
     * @var PHPMailer
     */
    private PHPMailer $mailer;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->configureMailer();
    }

    /**
     * Summary of configureMailer
     * @return void
     */
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

    /**
     * Summary of sendTOTPEmail
     * @param string $toEmail
     * @param string $totp
     * @return bool
     */
    public function sendTOTPEmail(string $toEmail, string $totp): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail);
            $this->mailer->Subject = "Your OTP Code is: $totp";
            $this->mailer->Body = "Your GangsterClub.com OTP code is: $totp. Please do not share this code nor your email address
                with anyone. If you did not request a login code you can ignore this email. Or if you experience spam you can have us
                blacklist your email address by writing us at
                <a href='mailto:info@gangsterclub.com?subject=Blacklist my email'>info@gangsterclub.com</a>.";

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
}
