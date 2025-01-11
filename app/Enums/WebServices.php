<?php

namespace App\Enums;

class WebServices
{
    const SUTRAN = "Sutran";
    const OSINERGMIN = "Osinergmin";
    const MININTER = "Mininter";
    const CONSATEL = "Consatel";

    public static function labels(string $service): string
    {
        return match ($service) {
            self::SUTRAN => "🚛 Sutran",
            self::OSINERGMIN => "🏭 Osinergmin",
            self::MININTER => "👮 Mininter",
            self::CONSATEL => "📡 Consatel",
            default => "Unknown Service",
        };
    }
}
