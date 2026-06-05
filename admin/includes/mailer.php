<?php

class SmtpMailer
{
    private $socket;
    private $settings;
    private $lastError = null;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function connect(): bool
    {
        $host = trim($this->settings['smtp_host'] ?? '');
        $port = (int) ($this->settings['smtp_port'] ?? 587);
        $encryption = strtolower(trim($this->settings['smtp_encryption'] ?? 'tls'));

        if ($host === '') {
            $this->lastError = 'SMTP host is empty.';
            return false;
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host;
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $remote . ':' . $port,
            $errno,
            $errstr,
            25,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
        );

        if (!$this->socket) {
            $this->lastError = "Connection failed: {$errstr} ({$errno})";
            return false;
        }

        stream_set_timeout($this->socket, 30);

        if (!$this->expect($this->read(), [220])) {
            $this->lastError = 'Invalid SMTP greeting: ' . $this->lastResponse;
            return false;
        }

        if (!$this->cmd('EHLO ' . gethostname(), [250])) {
            if (!$this->cmd('HELO ' . gethostname(), [250])) {
                $this->lastError = 'EHLO failed: ' . $this->lastResponse;
                return false;
            }
        }

        if ($encryption === 'tls') {
            if (!$this->cmd('STARTTLS', [220])) {
                $this->lastError = 'STARTTLS failed: ' . $this->lastResponse;
                return false;
            }
            $crypto = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if (!$crypto) {
                $crypto = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$crypto) {
                $this->lastError = 'Could not enable TLS.';
                return false;
            }
            if (!$this->cmd('EHLO ' . gethostname(), [250])) {
                $this->lastError = 'EHLO after TLS failed: ' . $this->lastResponse;
                return false;
            }
        }

        $username = trim($this->settings['smtp_username'] ?? '');
        $password = $this->settings['smtp_password'] ?? '';

        if ($username !== '') {
            if (!$this->cmd('AUTH LOGIN', [334])) {
                $this->lastError = 'AUTH LOGIN failed: ' . $this->lastResponse;
                return false;
            }
            if (!$this->cmd(base64_encode($username), [334])) {
                $this->lastError = 'SMTP username rejected: ' . $this->lastResponse;
                return false;
            }
            if (!$this->cmd(base64_encode($password), [235])) {
                $this->lastError = 'SMTP password rejected. Use App Password for Gmail.';
                return false;
            }
        }

        return true;
    }

    public function send(string $to_email, string $to_name, string $subject, string $html_body, $pdf_binary = null, string $pdf_filename = 'salary_slip.pdf'): bool
    {
        $from_email = trim($this->settings['smtp_from_email'] ?? '');
        $from_name = trim($this->settings['smtp_from_name'] ?? 'Payroll');

        if ($from_email === '') {
            $this->lastError = 'From email is not set.';
            return false;
        }

        if (!$this->cmd('MAIL FROM:<' . $from_email . '>', [250])) {
            $this->lastError = 'MAIL FROM failed: ' . $this->lastResponse;
            return false;
        }

        if (!$this->cmd('RCPT TO:<' . $to_email . '>', [250, 251])) {
            $this->lastError = 'RCPT TO failed: ' . $this->lastResponse;
            return false;
        }

        if (!$this->cmd('DATA', [354])) {
            $this->lastError = 'DATA failed: ' . $this->lastResponse;
            return false;
        }

        $to_name = $to_name !== '' ? $to_name : $to_email;
        $message = $this->buildMimeMessage($from_email, $from_name, $to_email, $to_name, $subject, $html_body, $pdf_binary, $pdf_filename);
        $this->writeMimeData($message);

        if (!$this->expect($this->read(), [250])) {
            $this->lastError = 'Message not accepted: ' . $this->lastResponse;
            return false;
        }

        return true;
    }

    private function buildMimeMessage($from_email, $from_name, $to_email, $to_name, $subject, $html_body, $pdf_binary, $pdf_filename)
    {
        $boundary = '----Payroll_' . md5(uniqid((string) mt_rand(), true));
        $headers = [
            'From: ' . encode_mime_header($from_name) . " <{$from_email}>",
            'To: ' . encode_mime_header($to_name) . " <{$to_email}>",
            'Subject: ' . encode_mime_header($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
        ];

        if ($pdf_binary !== null && $pdf_binary !== '') {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $html_body . "\r\n\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Type: application/pdf; name="' . $pdf_filename . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . $pdf_filename . "\"\r\n\r\n";
            $body .= chunk_split(base64_encode($pdf_binary)) . "\r\n";
            $body .= "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $html_body;
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function writeMimeData($message)
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $message));
        foreach ($lines as $line) {
            $line = str_replace("\r", '', $line);
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
            fwrite($this->socket, $line . "\r\n");
        }
        fwrite($this->socket, ".\r\n");
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @$this->cmd('QUIT', [221, 250]);
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private string $lastResponse = '';

    private function read(): string
    {
        $data = '';
        if (!$this->socket) {
            return '';
        }
        while ($line = @fgets($this->socket, 515)) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $this->lastResponse = trim($data);
        return $data;
    }

    private function cmd(?string $command, array $codes): bool
    {
        if ($command !== null) {
            fwrite($this->socket, $command . "\r\n");
        }
        return $this->expect($this->read(), $codes);
    }

    private function expect(string $response, array $codes): bool
    {
        $code = (int) substr($response, 0, 3);
        return in_array($code, $codes, true);
    }
}

function send_email_smtp(array $settings, $to_email, $to_name, $subject, $html_body, $pdf_binary = null, $pdf_filename = 'salary_slip.pdf')
{
    $mailer = new SmtpMailer($settings);
    if (!$mailer->connect()) {
        return ['success' => false, 'message' => $mailer->getLastError() ?? 'SMTP connect failed'];
    }
    if (!$mailer->send($to_email, $to_name, $subject, $html_body, $pdf_binary, $pdf_filename)) {
        $err = $mailer->getLastError() ?? 'Send failed';
        $mailer->disconnect();
        return ['success' => false, 'message' => $err];
    }
    $mailer->disconnect();
    return ['success' => true, 'message' => 'Sent'];
}

function encode_mime_header($text)
{
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}
