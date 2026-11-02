<?php

declare(strict_types=1);

namespace OvhDns\Health;

/** Per-report cache and limits; never caches across audits or changes a resolver's global configuration. */
final class DnsSession
{
    private array $cache = [];
    public function __construct(private readonly DnsResolver $resolver, public readonly Budget $budget) {}
    public function query(string $name, string $type, string $server = '8.8.8.8', bool $recursive = true): DnsAnswer
    {
        $key = implode('|', [$name, $type, $server, (int) $recursive]);
        if (isset($this->cache[$key])) return $this->cache[$key];
        try {
            if (count($this->cache) >= 160) return new DnsAnswer('UNKNOWN', source: $server);
            return $this->cache[$key] = $this->resolver->query($name, $type, $server, $recursive, $this->budget->timeout(2));
        } catch (\Throwable) { return $this->cache[$key] = new DnsAnswer('UNKNOWN', source: $server); }
    }
    public function addresses(string $name): array
    {
        $seen = [];
        for ($i = 0; $i < 8; ++$i) {
            if (isset($seen[$name])) return [];
            $seen[$name] = true;
            $a = $this->query($name, 'A'); $aaaa = $this->query($name, 'AAAA');
            $ips = array_merge($a->values($name, 'A'), $aaaa->values($name, 'AAAA'));
            if ($ips !== []) return array_values(array_unique($ips));
            $aliases = array_merge($a->values($name, 'CNAME'), $aaaa->values($name, 'CNAME'));
            if ($aliases === []) return [];
            $name = $aliases[0];
        }
        return [];
    }
}
