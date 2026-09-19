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
