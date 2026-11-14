<?php
// Throwaway: enrolled devices with their BIOS revision fields, one row per serial,
// so the patch-revision spread can be measured before any SPI work starts.
// Output (TSV): serial, model, biosversion, biosdate, bios_revision, bios_firmware_revision, lock
$base = '/home/oxwet/webs/tscrub-form';
$c = json_decode(file_get_contents($base . '/config.json'), true);
$db = $c['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = [];
$q = "SELECT payload FROM reports WHERE report_type='diagnostics' ORDER BY id DESC";
foreach ($pdo->query($q) as $r) {
    $p = json_decode($r['payload'], true);
    if (!is_array($p)) { continue; }
    $s = preg_replace('/\s+/', '', strtoupper(trim((string)($p['sysserial'] ?? ''))));
    if ($s === '' || isset($rows[$s])) { continue; }
    $rows[$s] = [
        (string)($p['system'] ?? ''),
        (string)($p['biosversion'] ?? ''),
        (string)($p['biosdate'] ?? ''),
        (string)($p['bios_revision'] ?? ''),
        (string)($p['bios_firmware_revision'] ?? ''),
        (string)($p['bioslock'] ?? ''),
    ];
}
ksort($rows);
foreach ($rows as $s => $v) {
    echo $s, "\t", implode("\t", $v), "\n";
}
fwrite(STDERR, 'devices: ' . count($rows) . "\n");
