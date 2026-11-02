<?php

declare(strict_types=1);

namespace OvhDns\Health;

use Closure;
use OvhDns\Dns\DnsInput;
use OvhDns\Provider\DnsProvider;
use Psr\Clock\ClockInterface;

final class DomainChecker
{
    /** @param (Closure(Budget): DnsProvider)|null $providerFactory */
    public function __construct(private readonly DnsResolver $resolver, private readonly CertificateChecker $certificates,
        private readonly ClockInterface $clock, private readonly HealthConfig $config, private readonly ?Closure $providerFactory = null) {}

    public function certificate(string $host, int $port = 443): Report
    {
        $endpoint = new Endpoint($host, $port, $port === 587);
        $report = new Report($endpoint->key(), $this->clock->now());
        $this->certificates->check($endpoint, new DnsSession($this->resolver, new Budget()), $report);
        return $report;
    }

    public function domain(string $domain): Report
    {
        $domain = DnsInput::zone($domain); $settings = $this->config->settings($domain);
        $report = new Report($domain, $this->clock->now()); $dns = new DnsSession($this->resolver, new Budget());
        $recursive = $dns->query($domain, 'NS'); $recursiveNs = $recursive->values($domain, 'NS');
        $report->add(new Finding('dns.existence', !$recursive->known() ? Status::UNKNOWN : ($recursive->status === 'NXDOMAIN' ? Status::FAIL : Status::PASS),
            $recursive->status === 'NXDOMAIN' ? 'Domain does not exist according to the public resolver.' : 'Domain existence lookup completed.', ['response' => $recursive], 'Google Public DNS'));
        $parentNs = $this->delegation($domain, $dns);
        $report->add(new Finding('dns.delegation', $parentNs === [] ? Status::UNKNOWN : ($parentNs === $recursiveNs ? Status::PASS : Status::WARN),
            'Parent delegation compared with recursive NS answers.', ['parent' => $parentNs, 'recursive' => $recursiveNs], 'parent authority / Google Public DNS', $parentNs === $recursiveNs ? '' : 'Check registrar delegation and allow for cached recursive answers.'));
        $servers = $parentNs ?: $recursiveNs;
        if (count($servers) > 6) $report->add(new Finding('dns.authorities.limit', Status::UNKNOWN, 'Only six authoritative servers can be inspected per report.'));
        $questions = [[$domain, 'A'], [$domain, 'AAAA'], [$domain, 'CNAME'], [$domain, 'MX'], [$domain, 'TXT'], ['_dmarc.'.$domain, 'TXT'], ['www.'.$domain, 'A'], ['www.'.$domain, 'AAAA'], ['www.'.$domain, 'CNAME']];
        foreach (array_slice($settings['dkim_selectors'] ?? [], 0, 8) as $selector) $questions[] = [$selector.'._domainkey.'.$domain, 'TXT'];
        $authorities = [];
        foreach (array_slice($servers, 0, 6) as $host) {
            $ips = $dns->addresses($host); $ip = null;
            foreach ($ips as $candidate) if (PublicAddress::allowed($candidate)) { $ip = $candidate; break; }
            if ($ip === null) { $report->add(new Finding('dns.authority.'.$host, Status::UNKNOWN, 'Authoritative server address unavailable or blocked.', source: $host)); continue; }
            $soa = $dns->query($domain, 'SOA', $ip, false);
            $good = $soa->known() && $soa->authoritative && $soa->values($domain, 'SOA') !== [];
            $report->add(new Finding('dns.authority.'.$host, !$soa->known() ? Status::UNKNOWN : ($good ? Status::PASS : Status::FAIL),
                $good ? 'Server answers authoritatively for the zone.' : 'Server did not provide an authoritative SOA.', ['response' => $soa], $host.' via '.$ip, $good ? '' : 'Check zone provisioning and delegated nameservers.'));
            if (!$good) continue;
            $ns = $dns->query($domain, 'NS', $ip, false);
            $report->add(new Finding('dns.authority_ns.'.$host, !$ns->known() || !$ns->authoritative ? Status::UNKNOWN : ($ns->values($domain, 'NS') === $servers ? Status::PASS : Status::WARN), 'Zone NS records compared with delegation.', ['delegated' => $servers, 'zone' => $ns->values($domain, 'NS')], $host));
            $answers = [];
            foreach ($questions as [$name, $type]) $answers[$name.'|'.$type] = $dns->query($name, $type, $ip, false);
            $authorities[$host] = $answers;
        }
        $baseline = $authorities === [] ? [] : reset($authorities);
        if ($authorities === []) $report->add(new Finding('dns.authorities', Status::UNKNOWN, 'No authoritative baseline could be established.'));
        foreach ($questions as [$name, $type]) {
            $key = $name.'|'.$type; $evidence = []; $values = []; $incomplete = false;
            foreach ($authorities as $host => $answers) {
                $answer = $answers[$key]; $evidence[$host] = $answer;
                if (!$answer->known() || !$answer->authoritative) $incomplete = true;
                else $values[] = serialize([$answer->status, $answer->values($name, $type)]);
            }
            $status = count(array_unique($values)) > 1 ? Status::FAIL : ($incomplete || $values === [] ? Status::UNKNOWN : Status::PASS);
            $report->add(new Finding('dns.consistency.'.$key, $status, 'Authoritative RRset consistency (TTL countdown and order ignored).', $evidence, 'authoritative DNS', $status === Status::FAIL ? 'Check synchronization between authoritative servers.' : ''));
            foreach (['Google' => '8.8.8.8', 'Cloudflare' => '1.1.1.1'] as $label => $ip) {
                $answer = $dns->query($name, $type, $ip);
                $reference = $baseline[$key] ?? null;
                $same = $reference !== null && $answer->status === $reference->status && $answer->values($name, $type) === $reference->values($name, $type);
                $known = $answer->known() && $reference !== null && $reference->known() && $reference->authoritative;
                $report->add(new Finding('dns.recursive.'.$label.'.'.$key, !$known ? Status::UNKNOWN : ($same ? Status::PASS : Status::WARN),
                    'Recursive answers compared with the authoritative baseline.', ['recursive' => $answer, 'authoritative' => $reference], $label.' Public DNS', $known && !$same ? 'Check pending publication and cached TTLs; rerun after propagation.' : ''));
            }
        }
        foreach ([$domain, 'www.'.$domain] as $owner) {
            $cname = $dns->query($owner, 'CNAME'); $aliases = $cname->values($owner, 'CNAME');
            if ($aliases === []) continue;
            $addresses = $dns->addresses($owner);
            $conflict = false;
            foreach (['A', 'AAAA', 'MX', 'TXT'] as $type) if ($dns->query($owner, $type)->values($owner, $type) !== []) $conflict = true;
            $report->add(new Finding('dns.cname.'.$owner, !$cname->known() ? Status::UNKNOWN : ($conflict ? Status::FAIL : ($addresses === [] ? Status::WARN : Status::PASS)),
                'CNAME exclusivity and target address resolution inspected.', ['targets' => $aliases, 'addresses' => $addresses, 'conflict' => $conflict], 'public DNS', $conflict || $addresses === [] ? 'Review the alias and its service ownership. Do not delete it solely from this finding.' : ''));
        }
        (new MailChecker())->check($domain, $settings, $dns, $report);
        foreach (['_jabber._tcp', '_xmpp-client._tcp', '_xmpp-server._tcp'] as $label) {
            $answer = $dns->query($label.'.'.$domain, 'SRV'); $legacy = array_filter($answer->values($label.'.'.$domain, 'SRV'), static fn (string $v): bool => preg_match('/(?:talk|xmpp-server).*\.google\.com\.?$/i', $v) === 1);
            if ($legacy !== []) $report->add(new Finding('dns.review.'.$label, Status::WARN, 'Legacy Google Talk records found; review whether the service is still used.', ['records' => array_values($legacy)], 'public DNS', 'Confirm service ownership before removing any records.'));
        }
        $this->compareProvider($domain, $questions, $baseline, $dns, $report);
        $endpoints = [];
        foreach ([$domain, 'www.'.$domain] as $host) if ($dns->addresses($host) !== []) $endpoints[] = new Endpoint($host);
        foreach ($settings['endpoints'] ?? [] as $value) $endpoints[] = HealthConfig::endpoint($value);
        $unique = []; foreach ($endpoints as $endpoint) $unique[$endpoint->key()] = $endpoint;
        if ($unique === []) $report->add(new Finding('tls.endpoints', Status::SKIP, 'No resolving web endpoints or configured mail endpoints to probe.'));
        if (count($unique) > 8) $report->add(new Finding('tls.limit', Status::UNKNOWN, 'Only eight TLS endpoints can be inspected per report.'));
        foreach (array_slice($unique, 0, 8) as $endpoint) $this->certificates->check($endpoint, $dns, $report);
        return $report;
    }

    private function delegation(string $domain, DnsSession $dns): array
    {
        $labels = explode('.', $domain); array_shift($labels);
        while ($labels !== []) {
            $parent = implode('.', $labels); $parentAnswer = $dns->query($parent, 'NS'); $parents = $parentAnswer->values($parent, 'NS');
            if ($parents === []) { array_shift($labels); continue; }
            foreach (array_slice($parents, 0, 2) as $host) foreach ($dns->addresses($host) as $ip) {
                if (!PublicAddress::allowed($ip)) continue;
                $answer = $dns->query($domain, 'NS', $ip, false);
                if ($answer->known() && ($values = $answer->values($domain, 'NS')) !== []) return $values;
                break;
            }
            return [];
        }
        return [];
    }

    private function compareProvider(string $domain, array $questions, array $baseline, DnsSession $dns, Report $report): void
    {
        if ($this->providerFactory === null) { $report->add(new Finding('provider.comparison', Status::SKIP, 'Provider comparison not configured; public checks need no OVH credentials.', source: 'provider')); return; }
        try {
            $provider = ($this->providerFactory)($dns->budget); $records = $provider->records($domain);
            $groups = [];
            foreach ($records as $record) {
                $owner = $record->owner === '' ? $domain : $record->owner.'.'.$domain;
                $groups[$owner.'|'.$record->type][] = self::canonical($record->type, $record->rdata, true);
            }
            foreach ($questions as [$name, $type]) {
                $key = $name.'|'.$type; $answer = $baseline[$key] ?? null;
                $configured = array_values(array_unique($groups[$key] ?? [])); sort($configured, SORT_STRING);
                $values = $answer?->values($name, $type) ?? [];
                $public = array_map(static fn (string $v): string => self::canonical($type, $v), $values); sort($public, SORT_STRING);
                $known = $answer !== null && $answer->known() && $answer->authoritative;
                $report->add(new Finding('provider.comparison.'.$key, !$known ? Status::UNKNOWN : ($configured === $public ? Status::PASS : Status::WARN),
                    'Provider configuration compared with published authoritative DNS.', ['configured' => $configured, 'authoritative' => $public], $provider->key(),
                    $known && $configured !== $public ? 'Inspect pending changes and delegation. Configuration does not prove publication; publishing may include other pending changes.' : ''));
            }
        } catch (\Throwable) { $report->add(new Finding('provider.comparison', Status::UNKNOWN, 'Provider comparison unavailable; public findings remain valid.', source: 'provider', remediation: 'Check credentials, zone access and time budget.')); }
    }

    private static function canonical(string $type, string $value, bool $zoneText = false): string
    {
        if ($type === 'TXT' && $zoneText) return TxtValue::fromZoneText($value);
        if (in_array($type, ['MX', 'CNAME', 'NS'], true)) return strtolower(rtrim($value, '.'));
        if ($type === 'AAAA') { $binary = @inet_pton($value); if ($binary !== false) return bin2hex($binary); }
        return $value;
    }
}
