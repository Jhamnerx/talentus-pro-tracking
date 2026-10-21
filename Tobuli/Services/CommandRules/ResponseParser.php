<?php

namespace Tobuli\Services\CommandRules;

/**
 * Extrae pares clave/valor numericos de la respuesta de un equipo.
 *
 * Acepta "CLAVE:valor", "CLAVE=valor" y "CLAVE: valor", separados por espacios,
 * comas o punto y coma. Ej. "DI1:1 AIN1:91 DO1:1" => ['DI1'=>1.0,'AIN1'=>91.0,'DO1'=>1.0].
 * Las claves se devuelven en mayusculas.
 */
class ResponseParser
{
    /**
     * @return array<string, float>
     */
    public static function values(string $response): array
    {
        $values = [];

        if (!preg_match_all('/([A-Za-z_][A-Za-z0-9_\.]*)\s*[:=]\s*(-?\d+(?:[\.,]\d+)?)/', $response, $matches, PREG_SET_ORDER)) {
            return $values;
        }

        foreach ($matches as $m) {
            $values[strtoupper($m[1])] = (float) str_replace(',', '.', $m[2]);
        }

        return $values;
    }

    public static function value(string $response, string $field): ?float
    {
        $values = static::values($response);
        $key = strtoupper(trim($field));

        return array_key_exists($key, $values) ? $values[$key] : null;
    }
}
