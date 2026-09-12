<?php
/**
 * tScrub form endpoint.
 * Receives JSON POST from the website, validates it, and hands off to
 * sendmail.py (Python smtplib -> Apple iCloud SMTP).
 */
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

// Honeypot: bots that fill this are silently dropped.
if (!empty($data['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$type = $data['type'] ?? 'contact';
if (!in_array($type, ['contact', 'download', 'support'], true)) {
    $type = 'contact';
}

$email   = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$name    = trim((string)($data['name'] ?? ''));
$org     = trim((string)($data['org'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

if (!$email) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A valid email address is required.']);
    exit;
}
if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A message is required.']);
    exit;
}
if (mb_strlen($message) > 4000 || mb_strlen($name) > 200 || mb_strlen($org) > 200) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message too long.']);
    exit;
}

// Basic rate limit: one submission per IP per 30 seconds.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlDir = __DIR__ . '/rl';
$rlFile = $rlDir . '/' . md5($ip);
if (!is_dir($rlDir)) { @mkdir($rlDir, 0770, true); }
if (file_exists($rlFile) && (time() - (int)@file_get_contents($rlFile)) < 30) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Please wait a moment before sending again.']);
    exit;
}
@file_put_contents($rlFile, (string)time());

$subject = $type === 'download' ? 'tScrub download request' : ($type === 'support' ? 'tScrub support request' : 'tScrub enquiry');
$text = "Name: {$name}\nEmail: {$email}\nOrganisation: {$org}\n\n{$message}";

$payload = json_encode([
    'type'     => $type,
    'subject'  => $subject,
    'text'     => $text,
    'reply_to' => $email,
]);

$py     = '/usr/bin/python3';
$script = __DIR__ . '/sendmail.py';
$desc   = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc   = proc_open([$py, $script], $desc, $pipes);

if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mailer unavailable. Please email us directly.']);
    exit;
}

fwrite($pipes[0], $payload);
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    error_log('tscrub mailer failed: ' . trim($stderr));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not send your message. Please email us directly.']);
    exit;
}

echo json_encode(['ok' => true]);
