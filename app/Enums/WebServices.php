<?php

namespace App\Enums;

class WebServices
{
    const SUTRAN = "Sutran";
    const OSINERGMIN = "Osinergmin";
    const MININTER = "Mininter";
    const CONSATEL = "Comsatel";
    const WISETRACK = "Wisetrack";

    public static function labels(string $service): string
    {
        return match ($service) {
            self::SUTRAN => "🚛 Sutran",
            self::OSINERGMIN => "🏭 Osinergmin",
            self::MININTER => "👮 Mininter",
            self::CONSATEL => "📡 Consatel",
            self::WISETRACK => "🚚 Wisetrack",
            default => "Unknown Service",
        };
    }
}
