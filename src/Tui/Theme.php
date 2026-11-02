<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use Symfony\Component\Tui\Style\Style;

final class Theme
{
    public const ACCENT = '#7dd3fc';
    public const MUTED = '#94a3b8';
    public const BORDER = '#475569';
    public const SURFACE = '#1e293b';
    public const SUCCESS = '#86efac';
    public const WARNING = '#fcd34d';
    public const ERROR = '#fda4af';

    public static function accent(): Style { return new Style(color: self::ACCENT, bold: true); }
    public static function muted(): Style { return new Style(color: self::MUTED); }
    public static function selected(): Style { return new Style(color: '#0f172a', background: self::ACCENT, bold: true); }
    public static function danger(): Style { return new Style(color: self::ERROR); }
}
