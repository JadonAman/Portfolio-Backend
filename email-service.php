<?php

require_once 'config.php';
require_once 'database.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $config;
    private $mailer;
    private $db;

    public function __construct() {
        $this->config = Config::getInstance();
        $this->db = Database::getInstance();
        $this->initializeMailer();
    }

    private function initializeMailer(): void {
        $this->mailer = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config->get('smtp.host');
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->config->get('smtp.username');
            $this->mailer->Password = $this->config->get('smtp.password');
            $this->mailer->SMTPSecure = $this->config->get('smtp.secure');
            $this->mailer->Port = $this->config->get('smtp.port');
            
            // Default sender
            $this->mailer->setFrom(
                $this->config->get('smtp.from_email'),
                $this->config->get('smtp.from_name')
            );
            
            // Enable HTML
            $this->mailer->isHTML(true);
            
        } catch (Exception $e) {
            error_log("Mailer initialization failed: " . $e->getMessage());
            throw new Exception("Email service initialization failed");
        }
    }

    public function sendOTP(string $email, string $otp): bool {
        return $this->sendEmail(
            $email,
            'Admin Login OTP - Portfolio Dashboard',
            $this->getOTPEmailTemplate($otp),
            "Your OTP for admin login is: {$otp}. This OTP will expire in {$this->config->get('security.otp_expiry')} minutes.",
            'otp'
        );
    }

    public function sendContactNotification(array $contactData): bool {
        return $this->sendEmail(
            $this->config->get('admin.email'),
            'New Contact Form Submission - ' . $contactData['subject'],
            $this->getContactNotificationTemplate($contactData),
            $this->getContactNotificationText($contactData),
            'contact_notification',
            $contactData['id'] ?? null
        );
    }

    public function sendContactConfirmation(array $contactData): bool {
        return $this->sendEmail(
            $contactData['email'],
            'Thank you for contacting us!',
            $this->getContactConfirmationTemplate($contactData),
            $this->getContactConfirmationText($contactData),
            'contact_confirmation',
            $contactData['id'] ?? null
        );
    }

    private function sendEmail(string $to, string $subject, string $htmlBody, string $textBody, string $emailType, int $contactId = null): bool {
        $logId = $this->logEmailAttempt($emailType, $to, $subject, $contactId);
        
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);
            
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = $textBody;
            
            $sent = $this->mailer->send();
            
            if ($sent) {
                $this->updateEmailLog($logId, 'sent', null);
                return true;
            } else {
                $this->updateEmailLog($logId, 'failed', 'PHPMailer send failed');
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Failed to send {$emailType} email to {$to}: " . $e->getMessage());
            $this->updateEmailLog($logId, 'failed', $e->getMessage());
            return false;
        }
    }

    private function logEmailAttempt(string $emailType, string $recipient, string $subject, int $contactId = null): int {
        try {
            $security = new SecurityManager();
            return $this->db->insert('email_logs', [
                'email_type' => $emailType,
                'recipient_email' => $recipient,
                'sender_email' => $this->config->get('smtp.from_email'),
                'subject' => $subject,
                'status' => 'pending',
                'contact_id' => $contactId,
                'ip_address' => $security->getClientIP(),
                'user_agent' => $security->getUserAgent()
            ]);
        } catch (Exception $e) {
            error_log("Failed to log email attempt: " . $e->getMessage());
            return 0;
        }
    }

    private function updateEmailLog(int $logId, string $status, string $errorMessage = null): void {
        if ($logId === 0) return;
        
        try {
            $updateData = [
                'status' => $status,
                'sent_at' => ($status === 'sent') ? date('Y-m-d H:i:s') : null
            ];
            
            if ($errorMessage) {
                $updateData['error_message'] = $errorMessage;
            }
            
            $this->db->update('email_logs', $updateData, ['id' => $logId]);
        } catch (Exception $e) {
            error_log("Failed to update email log: " . $e->getMessage());
        }
    }

    private function getOTPEmailTemplate(string $otp): string {
        $expiryMinutes = $this->config->get('security.otp_expiry');
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Admin Login OTP</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .otp-code { background: #fff; border: 2px dashed #667eea; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
                .otp-number { font-size: 32px; font-weight: bold; color: #667eea; letter-spacing: 8px; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Admin Dashboard Access</h1>
                <p>Portfolio Management System</p>
            </div>
            <div class='content'>
                <h2>Login Verification Required</h2>
                <p>Hello Admin,</p>
                <p>You are attempting to access the admin dashboard. Please use the following One-Time Password (OTP) to complete your login:</p>
                
                <div class='otp-code'>
                    <div class='otp-number'>{$otp}</div>
                    <p>Enter this code in the admin login form</p>
                </div>
                
                <div class='warning'>
                    <strong>Security Notice:</strong>
                    <ul>
                        <li>This OTP will expire in <strong>{$expiryMinutes} minutes</strong></li>
                        <li>Do not share this code with anyone</li>
                        <li>If you didn't request this login, please ignore this email</li>
                    </ul>
                </div>
                
                <p>If you have any concerns about this login attempt, please check your recent activity or contact the system administrator.</p>
                
                <p>Best regards,<br>Portfolio Security System</p>
            </div>
            <div class='footer'>
                <p>This is an automated security email. Please do not reply to this message.</p>
                <p>&copy; " . date('Y') . " Portfolio Management System. All rights reserved.</p>
            </div>
        </body>
        </html>";
    }

    private function getContactNotificationTemplate(array $data): string {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>New Contact Form Submission</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .contact-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .info-row { display: flex; margin-bottom: 15px; }
                .info-label { font-weight: bold; width: 100px; color: #555; }
                .info-value { flex: 1; }
                .message-box { background: #e8f5e8; border-left: 4px solid #4CAF50; padding: 20px; margin: 20px 0; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>📧 New Contact Form Submission</h1>
                <p>Portfolio Website</p>
            </div>
            <div class='content'>
                <h2>Contact Details</h2>
                <div class='contact-info'>
                    <div class='info-row'>
                        <span class='info-label'>Name:</span>
                        <span class='info-value'>{$data['name']}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Email:</span>
                        <span class='info-value'>{$data['email']}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Subject:</span>
                        <span class='info-value'>{$data['subject']}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Source:</span>
                        <span class='info-value'>{$data['source']}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Submitted:</span>
                        <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
                    </div>
                </div>
                
                <h3>Message</h3>
                <div class='message-box'>
                    " . nl2br(htmlspecialchars($data['message'])) . "
                </div>
                
                <p><strong>Action Required:</strong> Please log in to your admin dashboard to view and respond to this message.</p>
            </div>
            <div class='footer'>
                <p>This notification was sent automatically from your portfolio contact form.</p>
            </div>
        </body>
        </html>";
    }

    private function getContactConfirmationTemplate(array $data): string {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Thank you for contacting us!</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .highlight { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Thank You!</h1>
                <p>Your message has been received</p>
            </div>
            <div class='content'>
                <h2>Hello {$data['name']},</h2>
                <p>Thank you for reaching out through my portfolio website. I have received your message and truly appreciate you taking the time to contact me.</p>
                
                <div class='highlight'>
                    <strong>Your Message Summary:</strong><br>
                    <strong>Subject:</strong> {$data['subject']}<br>
                    <strong>Submitted:</strong> " . date('F j, Y \a\t g:i A') . "
                </div>
                
                <p>I review all messages personally and will get back to you as soon as possible, typically within 24-48 hours. If your inquiry is urgent, please feel free to reach out to me directly at iasamanjadon@gmail.com.</p>
                
                <p>In the meantime, feel free to:</p>
                <ul>
                    <li>Check out my other projects on <a href='https://github.com/jadonaman'>GitHub</a></li>
                    <li>Connect with me on <a href='https://www.linkedin.com/in/jadonaman/'>LinkedIn</a></li>
                    <li>View my coding solutions on <a href='https://leetcode.com/u/amanjadon01/'>LeetCode</a></li>
                </ul>
                
                <p>Thank you again for your interest, and I look forward to connecting with you soon!</p>
                
                <p>Best regards,<br>
                <strong>Aman Jadon</strong><br>
                Full Stack Developer</p>
            </div>
            <div class='footer'>
                <p>This is an automated confirmation email from the portfolio contact form.</p>
            </div>
        </body>
        </html>";
    }

    private function getContactNotificationText(array $data): string {
        return "NEW CONTACT FORM SUBMISSION\n\n" .
               "Name: {$data['name']}\n" .
               "Email: {$data['email']}\n" .
               "Subject: {$data['subject']}\n" .
               "Source: {$data['source']}\n" .
               "Submitted: " . date('F j, Y \a\t g:i A') . "\n\n" .
               "Message:\n{$data['message']}\n\n" .
               "Please log in to your admin dashboard to respond.";
    }

    private function getContactConfirmationText(array $data): string {
        return "Hello {$data['name']},\n\n" .
               "Thank you for contacting me through my portfolio website. I have received your message about \"{$data['subject']}\" and will get back to you within 24-48 hours.\n\n" .
               "Best regards,\nAman Jadon\nFull Stack Developer";
    }
}
