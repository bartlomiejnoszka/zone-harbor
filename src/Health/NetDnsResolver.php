<?php

declare(strict_types=1);

namespace OvhDns\Health;

/** Queries only pinned public DNS server IPs; names in untrusted DNS replies are never socket destinations. */
final class NetDnsResolver implements DnsResolver
{
    public function query(string $name, string $type, string $server, bool $recursive, float $timeout): DnsAnswer
    {
        if (!PublicAddress::allowed($server)) return new DnsAnswer('UNKNOWN', source: $server);
        try {
            $resolver = new \NetDNS2\Resolver(['nameservers' => [$server], 'timeout' => $timeout / 2, 'recurse' => $recursive]);
            $response = $resolver->query($name, $type);
        } catch (\NetDNS2\Exception $error) {
            $response = $error->getResponse();
            if ($response === null) return new DnsAnswer('UNKNOWN', source: $server);
        } catch (\Throwable) { return new DnsAnswer('UNKNOWN', source: $server); }
        $records = [];
        foreach (array_merge($response->answer, $response->authority, $response->additional) as $rr) {
            $kind = $rr->type->label();
            if ($rr instanceof \NetDNS2\RR\TXT) $value = implode('', array_map(static fn ($text): string => (string) $text, $rr->text));
            else { $parts = preg_split('/\s+/', (string) $rr, 5); $value = $parts[4] ?? ''; }
            $records[] = ['name' => strtolower(rtrim((string) $rr->name, '.')), 'type' => $kind, 'value' => $value, 'ttl' => $rr->ttl];
        }
        return new DnsAnswer($response->header->rcode->name, $records, $response->header->aa === 1, $server);
    }
}
