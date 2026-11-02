<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use OvhDns\Tests\Support\TuiSnapshotSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TuiSnapshotTest extends TestCase
{
    /** @return iterable<string, array{string, int, int}> */
    public static function screens(): iterable
    {
        foreach ([
            ['browse-wide', 120, 34], ['browse-compact', 80, 24], ['zones-compact', 80, 24],
            ['type-filter', 120, 34], ['live-search', 120, 34], ['empty-search', 80, 24],
            ['palette', 120, 34], ['palette-filtered', 80, 24], ['help', 120, 34],
            ['record-details', 120, 34], ['form-add', 120, 34], ['form-errors', 80, 24],
            ['form-long-txt', 120, 34], ['preview-edit', 120, 34], ['preview-delete', 80, 24],
            ['preview-publish', 80, 24], ['publication-unconfirmed', 80, 24],
            ['health', 120, 34], ['health-compact', 80, 24], ['health-evidence', 80, 24], ['health-target', 80, 24],
            ['resize-form', 120, 34], ['terminal-too-small', 70, 20],
        ] as $case) yield $case[0] => $case;
    }

    #[DataProvider('screens')]
    public function testScreenMatchesReviewedSnapshot(string $name, int $columns, int $rows): void
    {
        $configure = $name === 'publication-unconfirmed' ? static function (\OvhDns\Tui\State $state): void {
            $state->result = ['status' => 'saved_publication_unconfirmed', 'zone' => 'example.test', 'id' => '104', 'backup' => 'operations/mcp/example-operation'];
            $state->message = 'Operation finished. Review the result before another change.';
            $state->open('result');
        } : null;
        if (str_starts_with($name, 'health')) $configure = static function (\OvhDns\Tui\State $state) use ($name): void {
            $state->healthOnly = true;
            $state->healthReport = ['target' => 'example.test', 'status' => 'WARN', 'observedAt' => '2026-01-01T12:00:00Z', 'checks' => [
                ['id' => 'dns.delegation', 'status' => 'PASS', 'summary' => 'Parent delegation matches authoritative DNS.', 'source' => 'authoritative DNS', 'evidence' => ['nameservers' => ['ns1.example.test', 'ns2.example.test']], 'remediation' => ''],
                ['id' => 'mail.dmarc', 'status' => 'WARN', 'summary' => 'DMARC monitors without enforcement.', 'source' => 'public DNS', 'evidence' => ['policy' => 'none'], 'remediation' => 'Review aggregate reports before increasing enforcement.'],
                ['id' => 'mail.dkim', 'status' => 'SKIP', 'summary' => 'No selector configured.', 'source' => 'local configuration', 'evidence' => [], 'remediation' => 'Configure a selector supplied by the sending service.'],
            ]];
            $state->message = 'Health audit completed. No DNS writes.';
            $state->open(match ($name) { 'health-evidence' => 'health_details', 'health-target' => 'health_target', default => 'health' });
        };
        $session = new TuiSnapshotSession($columns, $rows, $configure);
        try {
            switch ($name) {
                case 'zones-compact': $session->keys('1'); break;
                case 'type-filter': $session->keys("\e[C", "\e[C"); break;
                case 'live-search': $session->keys('/', 'google'); break;
                case 'empty-search': $session->keys('/', 'not-found'); break;
                case 'palette': $session->keys("\x10"); break;
                case 'palette-filtered': $session->keys("\x10", 'publish'); break;
                case 'help': $session->keys('?'); break;
                case 'record-details':
                    $session->keys('/', 'google', "\r", "\r");
                    break;
                case 'form-add': $session->keys('a'); break;
                case 'form-errors':
                    $session->keys('a', "\t", "\x15", 'bad..name', "\t", "\x15", '-1', "\r");
                    break;
                case 'form-long-txt':
                case 'resize-form':
                    $session->keys('/', 'google', "\r", 'e', "\t", "\t");
                    if ($name === 'resize-form') $session->resize(80, 24);
                    break;
                case 'preview-edit':
                    $session->keys('e', "\t", "\t", "\x05", "\x15", '203.0.113.20', "\r");
                    break;
                case 'preview-delete': $session->keys('d'); break;
                case 'preview-publish': $session->keys('p'); break;
            }
            // Size belongs to the golden name, including a resize during editing.
            $size = $session->terminal->getColumns().'x'.$session->terminal->getRows();
            $capture = $session->capture();
            foreach (['screen' => 'txt', 'styles' => 'json'] as $kind => $extension) {
                $file = __DIR__.'/Snapshots/Tui/'.$name.'-'.$size.'.'.$extension;
                $actual = $capture[$kind];
                if (getenv('UPDATE_TUI_SNAPSHOTS') === '1') {
                    self::assertFalse((bool) getenv('CI'), 'Snapshot updates are disabled in CI. Review and update locally.');
                    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
                    self::assertSame(strlen($actual), file_put_contents($file, $actual));
                }
                self::assertFileExists($file, 'Missing screen baseline. Run composer snapshots:update and review the diff.');
                self::assertSame(file_get_contents($file), $actual, 'TUI snapshot changed: '.basename($file).'. Review the screen before running composer snapshots:update.');
            }
            self::assertSame(0, $session->gateway->writeAttempts, 'Snapshot scenarios must never attempt a write.');
        } finally { $session->close(); }
    }
}
