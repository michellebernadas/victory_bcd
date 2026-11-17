<?php
/**
 * Minimal SMTP sender, no dependencies.
 *
 * Why not mail()? On IIS it needs an SMTP host set in php.ini, which this site's
 * production cannot edit. This talks to any SMTP server directly, configured in
 * .env:
 *
 *   MAIL_HOST        smtp.example.com        (blank = do not send; log instead)
 *   MAIL_PORT        587                     (465 for ssl)
 *   MAIL_ENCRYPTION  tls | ssl | none        (default tls = STARTTLS)
 *   MAIL_USERNAME    / MAIL_PASSWORD         (blank = no login)
 *   MAIL_FROM        no-reply@yourchurch.org
 *   MAIL_FROM_NAME   Victory Bacolod
 *
 * With no MAIL_HOST nothing is sent: the message is appended to logs/mail.log
 * and the result status is "logged", so development works and nothing is lost.
 * A send never throws; it returns ['status' => sent|logged|failed, 'detail' => …].
 */
class Mailer {
    const TIMEOUT = 8;

    private static function env($k, $default = '') {
        $v = $_ENV[$k] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function configured() { return self::env('MAIL_HOST') !== ''; }

    /** Strip anything that could add headers (CR/LF) from a header value. */
    private static function clean($v) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$v)); }

    private static function encodeHeader($v) {
        $v = self::clean($v);
        return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    /**
     * @param string[] $to       addresses (already validated by the caller; re-checked here)
     * @param string   $replyTo  optional address replies should go to (e.g. the visitor)
     */
    public static function send(array $to, $subject, $text, $html = null, $replyTo = null) {
        $to = array_values(array_unique(array_filter(array_map('trim', $to), function ($a) { return filter_var($a, FILTER_VALIDATE_EMAIL); })));
        if (!$to) return ['status' => 'failed', 'detail' => 'No valid recipient address.'];

        // Not configured: nothing is sent, so do not fail on a placeholder From address; just keep a record.
        if (!self::configured()) return self::logOnly($to, $subject, $text);

        $from     = self::env('MAIL_FROM') ?: 'no-reply@localhost';
        $fromName = self::env('MAIL_FROM_NAME', 'Victory Bacolod');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['status' => 'failed', 'detail' => 'MAIL_FROM is not a valid address.'];
        $replyTo = ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) ? $replyTo : null;

        $boundary = 'vb_' . bin2hex(random_bytes(8));
        $h = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (preg_replace('/^.*@/', '', $from) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
        ];
        if ($replyTo) $h[] = 'Reply-To: ' . $replyTo;
        if ($html !== null) {
            $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($text)) . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($html)) . "--$boundary--\r\n";
        } else {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($text));
        }
        $message = implode("\r\n", $h) . "\r\n\r\n" . $body;

        try {
            self::smtp($from, $to, $message);
            return ['status' => 'sent', 'detail' => null];
        } catch (Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            return ['status' => 'failed', 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    private static function logOnly(array $to, $subject, $text) {
        $dir = __DIR__ . '/../logs';
        $line = '[' . date('Y-m-d H:i:s') . "] (MAIL NOT CONFIGURED — NOT SENT)\nTo: " . implode(', ', $to) . "\nSubject: " . self::clean($subject) . "\n\n" . $text . "\n" . str_repeat('-', 60) . "\n";
        @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
        return ['status' => 'logged', 'detail' => 'Email is not set up on this server (MAIL_HOST in .env); the message was saved to logs/mail.log.'];
    }

    // ── SMTP ─────────────────────────────────────────────────────────────────

    /** Read one (possibly multi-line) reply; returns [code, text]. */
    private static function read($fp) {
        $text = ''; $code = 0;
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            $code = (int)substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        if ($text === '') throw new RuntimeException('The mail server closed the connection.');
        return [$code, trim($text)];
    }

    private static function cmd($fp, $line, array $ok) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        list($code, $text) = self::read($fp);
        if (!in_array($code, $ok, true)) throw new RuntimeException('SMTP ' . $code . ': ' . mb_substr($text, 0, 200));
        return $text;
    }

    private static function smtp($from, array $to, $message) {
        $host = self::env('MAIL_HOST'); $port = (int)(self::env('MAIL_PORT') ?: 587);
        $enc  = strtolower(self::env('MAIL_ENCRYPTION', 'tls'));
        $user = self::env('MAIL_USERNAME'); $pass = $_ENV['MAIL_PASSWORD'] ?? '';
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("Could not connect to $host:$port ($es).");
        stream_set_timeout($fp, self::TIMEOUT);
        try {
            self::cmd($fp, null, [220]);
            $me = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost';
            self::cmd($fp, "EHLO $me", [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Could not start TLS with the mail server.');
                self::cmd($fp, "EHLO $me", [250]);
            }
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334]);
                self::cmd($fp, base64_encode($pass), [235]);
            }
            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($to as $r) self::cmd($fp, 'RCPT TO:<' . $r . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            // dot-stuff, normalise line endings, terminate
            $data = preg_replace('/^\./m', '..', preg_replace('/\r\n|\r|\n/', "\r\n", $message));
            fwrite($fp, $data . "\r\n.\r\n");
            list($code, $text) = self::read($fp);
            if ($code !== 250) throw new RuntimeException('SMTP ' . $code . ': ' . mb_substr($text, 0, 200));
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }
}
