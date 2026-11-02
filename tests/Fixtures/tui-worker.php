<?php

declare(strict_types=1);

// Fake DNS only. State must live in an explicitly supplied temporary directory.
$directory = realpath($argv[1] ?? '');
if ($directory === false || !str_starts_with($directory, realpath(sys_get_temp_dir()).'/')) exit(2);
$file = $directory.'/records.json';
$records = is_file($file) ? json_decode(file_get_contents($file), true) : [
    ['id' => 1, 'fieldType' => 'A', 'subDomain' => '', 'ttl' => 3600, 'target' => '203.0.113.10'],
    ['id' => 2, 'fieldType' => 'MX', 'subDomain' => '', 'ttl' => 3600, 'target' => '10 mail.example.test.'],
    ['id' => 3, 'fieldType' => 'TXT', 'subDomain' => '_dmarc', 'ttl' => 3600, 'target' => '"v=DMARC1; p=none;"'],
    ['id' => 4, 'fieldType' => 'TXT', 'subDomain' => 'google._domainkey', 'ttl' => 3600, 'target' => '"v=DKIM1; k=rsa; p='.str_repeat('DEMOkey', 80).'END_OF_DEMO_KEY"'],
];
$r = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
usleep(80000); // Exercise polling while requests are in flight.
$action = $r['action'] ?? '';
if ($action === 'zones') $result = ['status' => 'ok', 'zones' => ['demo.example.test', 'other.example.test']];
elseif (!in_array($r['zone'] ?? '', ['demo.example.test', 'other.example.test'], true)) exit(2);
elseif ($action === 'health') $result = ['status' => 'ok', 'report' => ['target' => $r['zone'], 'status' => 'WARN', 'observedAt' => '2026-01-01T12:00:00Z', 'checks' => [
    ['id' => 'dns.delegation', 'status' => 'PASS', 'summary' => 'Delegation matches authoritative DNS.', 'source' => 'SIMULATED public DNS', 'evidence' => ['nameservers' => ['ns1.example.test']], 'remediation' => ''],
    ['id' => 'mail.dmarc', 'status' => 'WARN', 'summary' => 'DMARC monitors without enforcement.', 'source' => 'SIMULATED public DNS', 'evidence' => ['policy' => 'none'], 'remediation' => 'Review aggregate reports before increasing enforcement.'],
]]];
elseif ($action === 'records') $result = ['status' => 'ok', 'records' => $records];
elseif ($action === 'preview') {
    $result = $r + ['status' => 'preview', 'refresh' => true];
    unset($result['action']);
    foreach ($records as $record) if ((string) $record['id'] === ($r['id'] ?? null)) $result['record'] = $record;
} elseif ($action === 'write') {
    $id = $r['id'] ?? null;
    if ($r['operation'] === 'record:add') {
        $id = (string) (max(array_column($records, 'id')) + 1);
        $records[] = ['id' => (int) $id] + $r['fields'];
    } elseif ($r['operation'] === 'record:delete') {
        $records = array_values(array_filter($records, fn ($record) => (string) $record['id'] !== $id));
    } elseif ($r['operation'] === 'record:update') {
        foreach ($records as &$record) if ((string) $record['id'] === $id) $record = array_replace($record, $r['fields']);
        unset($record);
    }
    file_put_contents($file, json_encode($records));
    $backup = 'SIMULATED: operations/demo-backup';
    $result = ['status' => 'publication_accepted', 'zone' => $r['zone'], 'id' => $id, 'backup' => $backup];
} else exit(2);
echo json_encode($result, JSON_THROW_ON_ERROR);
