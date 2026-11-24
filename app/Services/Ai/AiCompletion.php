<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Tobuli\Entities\AiUsageLog;
use Tobuli\Entities\User;

/**
 * Llamada de una sola respuesta (sin herramientas) para las funciones fuera
 * del chat: explicar reporte, resumen en correos, dashboard y resumen de
 * alertas. Registra el consumo en ai_usage_logs con su "feature".
 */
class AiCompletion
{
    /** @var ClaudeClient */
    private $client;

    public function __construct(ClaudeClient $client = null)
    {
        // Sin cliente inyectado se crea uno por llamada con la clave del usuario
        // (la de su reseller si tiene propia).
        $this->client = $client;
    }

    /**
     * @param string   $model  modelo (por defecto el del chat)
     * @param callable $onText fn(string $delta) opcional, para streaming
     * @return string texto de la respuesta
     */
    public function complete(User $user, string $feature, string $system, string $content, string $model = null,
                             string $effort = 'low', int $maxTokens = 4000, callable $onText = null): string
    {
        // Sin modelo: el del chat para el plan del usuario (explicar reporte).
        $model = $model ?: AiSettings::chatModelFor($user);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => [['type' => 'text', 'text' => $system]],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $content]]]],
            'output_config' => ['effort' => $effort],
            // Sin cache: son llamadas unicas con contenido distinto cada vez, y el
            // system es corto; cachear solo pagaria la escritura (1.25x) sin lecturas.
        ];

        // El fallback de servidor solo aplica a los modelos que lo soportan.
        if (AiSettings::supportsFallbacks($model)) {
            $body['fallbacks'] = 'default';
        }

        $status = 'ok';
        $response = null;

        try {
            $client = $this->client ?? new ClaudeClient(AiSettings::configFor($user));
            $response = $client->stream($body, $onText);

            if ($response->stop_reason === 'refusal') {
                $status = 'refusal';
                return '';
            }

            $text = '';
            foreach ($response->content as $block) {
                if (($block->type ?? null) === 'text') {
                    $text .= $block->text;
                }
            }

            return trim($text);
        } catch (\Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            $this->log($user, $feature, $response->model ?? $model, $response->usage ?? null, $status);
        }
    }

    private function log(User $user, string $feature, string $model, $usage, string $status)
    {
        try {
            $input = (int) ($usage->input_tokens ?? 0);
            $output = (int) ($usage->output_tokens ?? 0);
            $read = (int) ($usage->cache_read_input_tokens ?? 0);
            $write = (int) ($usage->cache_creation_input_tokens ?? 0);

            AiUsageLog::create([
                'user_id' => $user->id,
                'key_owner_id' => AiSettings::keyOwnerFor($user),
                'feature' => $feature,
                'model' => $model,
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_read_tokens' => $read,
                'cache_write_tokens' => $write,
                'api_calls' => 1,
                'cost_usd' => round(AiPricing::cost($model, $usage), 5),
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::error('AirBot usage log error: ' . $e->getMessage());
        }
    }
}
