<?php

declare(strict_types=1);

namespace OvhDns\Dns;

use Symfony\Component\Console\Exception\InvalidArgumentException;

final class DnsInput
{
    private const TYPES = ['A', 'AAAA', 'CAA', 'CNAME', 'DKIM', 'DMARC', 'DNAME', 'HTTPS', 'LOC', 'MX', 'NAPTR', 'NS', 'PTR', 'RP', 'SPF', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT'];

    public static function types(): array { return self::TYPES; }

    /** Validate OVH record fields, independent of Console/MCP input shapes. */
    public static function fields(array $fields, bool $creating): array
    {
        $allowed = $creating ? ['fieldType', 'subDomain', 'target', 'ttl'] : ['subDomain', 'target', 'ttl'];
        if ($fields === [] || array_diff(array_keys($fields), $allowed)) {
            throw new InvalidArgumentException('Supply supported record fields; record type cannot change.');
        }
        foreach ($creating ? ['fieldType', 'subDomain', 'target'] : [] as $required) {
            if (!array_key_exists($required, $fields)) throw new InvalidArgumentException('Missing field '.$required.'.');
        }
        foreach (['fieldType', 'subDomain', 'target'] as $key) {
            if (array_key_exists($key, $fields) && !is_string($fields[$key])) throw new InvalidArgumentException('Field '.$key.' must be a string.');
        }
        if (isset($fields['fieldType'])) $fields['fieldType'] = self::type($fields['fieldType']);
        if (array_key_exists('subDomain', $fields)) $fields['subDomain'] = self::relativeName($fields['subDomain']);
        if (isset($fields['target']) && trim($fields['target']) === '') throw new InvalidArgumentException('Target cannot be empty.');
        if (array_key_exists('ttl', $fields) && (!is_int($fields['ttl']) || $fields['ttl'] < 0 || $fields['ttl'] > 2147483647)) {
            throw new InvalidArgumentException('TTL must be an integer from 0 to 2147483647.');
        }
        if ($creating) $fields['ttl'] ??= 0;
        return $fields;
    }

    public static function type(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (!in_array($value, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported record type. Available: '.implode(', ', self::TYPES).'.');
        }

        return $value;
    }

    public static function relativeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        if ($name === '@' || $name === '') {
            return '';
        }
        if (strlen($name) > 253 || ($name !== '*' && !preg_match('/^(?:\*\.)?[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $name))) {
            throw new InvalidArgumentException('Supply a relative name without a trailing dot (e.g. www, _dmarc, * or @). Use punycode for IDNs.');
        }
        foreach (explode('.', $name) as $label) {
            if (strlen($label) > 63) {
                throw new InvalidArgumentException('DNS labels cannot exceed 63 characters.');
            }
        }

        return $name;
    }

    public static function zone(string $value): string
    {
        $value = strtolower(str_ends_with($value, '.') ? substr($value, 0, -1) : $value);
        if (!filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('Invalid zone name. Use an ASCII/punycode domain.');
        }

        return $value;
    }

    public static function id(string $value): string
    {
        if ($value === '' || strlen($value) > 256 || preg_match('/[\x00-\x20\x7f\/\\\\]/', $value)) {
            throw new InvalidArgumentException('Record reference is invalid.');
        }
        return $value;
    }
}
