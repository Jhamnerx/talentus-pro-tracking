<?php

namespace App\Services\Ai;

/**
 * Costo en USD de una respuesta de la API segun la tabla config('ai.pricing').
 * Lo usan el chat (AiAgent), las llamadas sueltas (AiCompletion) y la prueba
 * comparativa (ai:compare).
 */
class AiPricing
{
    /**
     * @param string $model modelo que respondio
     * @param object $usage bloque usage de la respuesta
     */
    public static function cost(string $model, $usage): float
    {
        $price = config("ai.pricing.$model") ?? config('ai.pricing.' . config('ai.model'));

        if (!$price || !$usage) {
            return 0.0;
        }

        $input = (int) ($usage->input_tokens ?? 0);
        $output = (int) ($usage->output_tokens ?? 0);
        $read = (int) ($usage->cache_read_input_tokens ?? 0);
        $write = (int) ($usage->cache_creation_input_tokens ?? 0);

        // La escritura de 1 h cuesta 2x la entrada; la de 5 min, 1.25x.
        $write1h = (int) ($usage->cache_creation->ephemeral_1h_input_tokens ?? 0);
        $write5m = max(0, $write - $write1h);

        $cost = ($input * $price['input'] + $output * $price['output']
            + $read * $price['cache_read'] + $write5m * $price['cache_write']
            + $write1h * ($price['cache_write_1h'] ?? $price['input'] * 2)) / 1000000;

        $long = $price['long_context'] ?? null;
        if ($long && ($input + $read + $write) > (int) $long['threshold']) {
            $cost *= (float) $long['multiplier'];
        }

        return $cost;
    }
}
