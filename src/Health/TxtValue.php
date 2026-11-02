<?php

declare(strict_types=1);

namespace OvhDns\Health;

/** Decode zone-file TXT chunks for comparison with already-decoded wire TXT values. */
final class TxtValue
{
    public static function fromZoneText(string $value): string
    {
        $length = strlen($value); $i = 0; $decoded = '';
        while ($i < $length) {
            while ($i < $length && ctype_space($value[$i])) ++$i;
            if ($i === $length) break;
            if ($value[$i] !== '"') return $value;
            ++$i;
            $closed = false;
            while ($i < $length) {
                $char = $value[$i]; ++$i;
                if ($char === '"') { $closed = true; break; }
                if ($char === '\\') {
                    if ($i >= $length) return $value;
                    $digits = substr($value, $i, 3);
                    if (strlen($digits) === 3 && ctype_digit($digits)) {
                        if ((int) $digits > 255) return $value;
                        $decoded .= chr((int) $digits); $i += 3; continue;
                    }
                    $char = $value[$i]; ++$i;
                }
                $decoded .= $char;
            }
            if (!$closed) return $value;
        }
        return $decoded;
    }
}
