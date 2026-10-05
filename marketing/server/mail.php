<?php
declare(strict_types=1);

/**
 * Send a plain-text email via sendmail.py (Apple iCloud SMTP).
 * If $to is provided the mail is sent to that address (transactional emails);
 * otherwise it is routed by $type to the configured inbox.
 */

function mail_send(string $subject, string $text, string $type = 'contact', ?string $to = null): bool {
    $py = '/usr/bin/python3';
    $script = __DIR__ . '/sendmail.py';
    $payload = [
        'type'     => $type,
        'subject'  => $subject,
        'text'     => $text,
        'reply_to' => null,
    ];
    if ($to !== null) {
        $payload['to'] = $to;
    }

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$py, $script], $desc, $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        error_log('tscrub mailer failed: ' . trim($stderr));
        return false;
    }
    return true;
}

/**
 * Fire-and-forget email: returns immediately and sends in a background process,
 * so a dashboard action (e.g. an org invite) is never blocked on SMTP latency.
 * Falls back to the synchronous mail_send() when process spawning is unavailable.
 */
function mail_send_async(string $subject, string $text, string $type = 'contact', ?string $to = null): void {
    if (!function_exists('exec')) {
        mail_send($subject, $text, $type, $to);
        return;
    }
    $payload = ['type' => $type, 'subject' => $subject, 'text' => $text, 'reply_to' => null];
    if ($to !== null) {
        $payload['to'] = $to;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'tscrub-mail-');
    if ($tmp === false || file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES)) === false) {
        mail_send($subject, $text, $type, $to);
        return;
    }
    $py = '/usr/bin/python3';
    $script = __DIR__ . '/sendmail.py';
    $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script) . ' < ' . escapeshellarg($tmp) . ' > /dev/null 2>&1; rm -f ' . escapeshellarg($tmp);
    @exec('(' . $cmd . ') > /dev/null 2>&1 &');
}
