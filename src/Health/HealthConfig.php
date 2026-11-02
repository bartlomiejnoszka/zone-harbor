<?php

declare(strict_types=1);

namespace OvhDns\Health;

use OvhDns\Dns\DnsInput;
use Symfony\Component\Yaml\Yaml;

final readonly class HealthConfig
{
    public function __construct(public array $domains = [], public float $warning = 0.25, public float $critical = 0.10)
    {
        if ($critical <= 0 || $warning >= 1 || $warning <= $critical) throw new \InvalidArgumentException('Certificate thresholds must satisfy 0 < critical < warning < 1.');
        foreach ($domains as $name => $settings) {
            DnsInput::zone($name);
            if (!is_array($settings) || array_diff(array_keys($settings), ['mail', 'dkim_selectors', 'endpoints'])) throw new \InvalidArgumentException('Unsupported domain health settings.');
            if (isset($settings['mail']) && !in_array($settings['mail'], ['google', 'external', 'none'], true)) throw new \InvalidArgumentException('Mail expectation must be google, external or none.');
            foreach ($settings['dkim_selectors'] ?? [] as $selector) if (!is_string($selector) || !preg_match('/^[a-zA-Z0-9_-]{1,63}$/D', $selector)) throw new \InvalidArgumentException('Invalid DKIM selector.');
            foreach ($settings['endpoints'] ?? [] as $endpoint) self::endpoint($endpoint);
        }
    }
    public static function load(string $file): self
    {
        if (!is_file($file)) return new self();
        try {
            $data = Yaml::parseFile($file);
            if (!is_array($data) || array_diff(array_keys($data), ['domains', 'certificate'])) throw new \InvalidArgumentException();
            return new self($data['domains'] ?? [], $data['certificate']['warning_remaining'] ?? 0.25, $data['certificate']['critical_remaining'] ?? 0.10);
        } catch (\Throwable) { throw new \InvalidArgumentException('Invalid local health configuration. Check zone-harbor.local.yaml against the example.'); }
    }
    public function settings(string $domain): array { return $this->domains[$domain] ?? []; }
    public static function endpoint(array $value): Endpoint
    {
        if (array_diff(array_keys($value), ['host', 'port'])) throw new \InvalidArgumentException('Unsupported TLS endpoint settings.');
        return new Endpoint($value['host'] ?? '', $value['port'] ?? 443, ($value['port'] ?? 443) === 587);
    }
}
