<?php

declare(strict_types=1);

namespace OvhDns\Health;

final class PublicAddress
{
    public static function allowed(string $ip): bool
    {
        if (str_contains($ip, '%')) return false;
        if (str_starts_with(strtolower($ip), '::ffff:')) return false;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) return false;
        // Explicitly exclude special-use ranges not covered consistently by PHP's filters.
        $binary = inet_pton($ip);
        if ($binary === false) return false;
        if (strlen($binary) === 4) {
            $first = ord($binary[0]); $second = ord($binary[1]);
            return $first !== 0 && $first < 224 && !($first === 100 && $second >= 64 && $second <= 127)
                && !($first === 198 && in_array($second, [18, 19], true))
                && !($first === 192 && ($second === 0 || $second === 88 && ord($binary[2]) === 99));
        }
        return (ord($binary[0]) & 0xe0) === 0x20; // Only global-unicast IPv6 2000::/3.
    }
}
