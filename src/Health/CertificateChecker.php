<?php

declare(strict_types=1);

namespace OvhDns\Health;

use Psr\Clock\ClockInterface;

final class CertificateChecker
{
    public function __construct(private readonly TlsProbe $probe, private readonly ClockInterface $clock, private readonly HealthConfig $config) {}
    public function check(Endpoint $endpoint, DnsSession $dns, Report $report): void
    {
        $id = 'tls.'.$endpoint->key();
        $ips = $dns->addresses($endpoint->host);
        if ($ips === []) { $report->add(new Finding($id, Status::UNKNOWN, 'No address could be resolved for the TLS endpoint.', source: 'public DNS', remediation: 'Check A/AAAA and CNAME resolution, then rerun.')); return; }
        foreach ($ips as $ip) if (!PublicAddress::allowed($ip)) {
            $report->add(new Finding($id, Status::UNKNOWN, 'Probe blocked: target resolves to a private or special-use address.', ['addresses' => $ips], remediation: 'Only public Internet endpoints can be inspected.')); return;
        }
        try {
            $observation = $this->probe->inspect($endpoint, $ips[0], $dns->budget);
            $cert = openssl_x509_parse($observation->certificate, false);
            if ($cert === false) throw new \RuntimeException();
        } catch (\Throwable) { $report->add(new Finding($id, Status::UNKNOWN, 'TLS inspection did not complete.', ['address' => $ips[0]], source: $endpoint->key(), remediation: 'Check network access and TLS service availability, then rerun.')); return; }
        $source = $endpoint->key().' via '.$observation->address;
        $report->add(new Finding($id.'.verification', $observation->verified ? Status::PASS : Status::FAIL,
            $observation->verified ? 'Certificate chain and hostname verified by the platform trust store.' : 'Platform certificate verification failed.',
            source: $source, remediation: $observation->verified ? '' : 'Inspect the certificate dates, hostname and full trust chain.'));
        $matches = self::matches($cert, $endpoint->host);
        $report->add(new Finding($id.'.hostname', $matches ? Status::PASS : Status::FAIL, $matches ? 'Certificate covers the requested hostname.' : 'Certificate does not cover the requested hostname.',
            ['subjectAltName' => $cert['extensions']['subjectAltName'] ?? ''], $source, $matches ? '' : 'Issue and serve a certificate covering this hostname.'));
        $from = $cert['validFrom_time_t'] ?? null; $to = $cert['validTo_time_t'] ?? null;
        if (!is_int($from) || !is_int($to) || $to <= $from) { $report->add(new Finding($id.'.expiry', Status::UNKNOWN, 'Certificate lifetime could not be parsed.', source: $source)); return; }
        $now = $this->clock->now()->getTimestamp();
        $remaining = ($to - $now) / ($to - $from);
        $status = $now < $from || $now >= $to || $remaining < $this->config->critical ? Status::FAIL : ($remaining < $this->config->warning ? Status::WARN : Status::PASS);
        $summary = $now < $from ? 'Certificate is not yet valid.' : ($now >= $to ? 'Certificate has expired.' : sprintf('Certificate has %.1f%% of its lifetime remaining.', $remaining * 100));
        $report->add(new Finding($id.'.expiry', $status, $summary,
            ['notBefore' => gmdate('Y-m-d\TH:i:s\Z', $from), 'notAfter' => gmdate('Y-m-d\TH:i:s\Z', $to),
                'remainingSeconds' => $to - $now, 'remainingRatio' => $remaining, 'warningRatio' => $this->config->warning, 'criticalRatio' => $this->config->critical],
            $source, $status === Status::PASS ? '' : 'Check automated renewal and the certificate served by this endpoint.'));
        if (count($ips) > 1) $report->add(new Finding($id.'.coverage', Status::WARN, 'One resolved address was probed; other addresses may serve different certificates.', ['probed' => $ips[0], 'addresses' => $ips], $source, 'Inspect the remaining addresses if service behavior differs.'));
    }

    public static function matches(array $certificate, string $hostname): bool
    {
        $san = $certificate['extensions']['subjectAltName'] ?? null;
        $names = [];
        if (is_string($san)) foreach (explode(',', $san) as $entry) { $entry = trim($entry); if (str_starts_with($entry, 'DNS:')) $names[] = substr($entry, 4); }
        else $names = (array) ($certificate['subject']['commonName'] ?? []);
        foreach ($names as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === $hostname) return true;
            if (str_starts_with($name, '*.') && substr_count($hostname, '.') === substr_count($name, '.') && str_ends_with($hostname, substr($name, 1))) return true;
        }
        return false;
    }
}
