<?php

namespace App\Enums;

class WebServices
{
    const SUTRAN = "Sutran";
    const OSINERGMIN = "Osinergmin";
    const MININTER = "Mininter";
    const CONSATEL = "Consatel";
    const WISETRACK = "Wisetrack";
    const SIDERSALES = "Sidersales";
    const EPS_CENTER = "EpsCenter";

    public static function labels(string $service): string
    {
        return match ($service) {
            self::SUTRAN => "🚛 Sutran",
            self::OSINERGMIN => "🏭 Osinergmin",
            self::MININTER => "👮 Mininter",
            self::CONSATEL => "📡 Consatel",
            self::WISETRACK => "🚚 Wisetrack",
            self::SIDERSALES => "🏗️ Sidersales (Gerdau)",
            self::EPS_CENTER => "📍 EPS Center",
            default => "Unknown Service",
        };
    }
}
