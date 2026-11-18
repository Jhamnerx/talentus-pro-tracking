<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Formatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\AiConversation;
use Tobuli\Entities\AiMessage;
use Tobuli\Entities\AiUsageLog;
use Tobuli\Entities\Timezone;
use Tobuli\Entities\User;

/**
 * Ciclo del agente: envia la pregunta a Claude, ejecuta las herramientas que
 * pida y repite hasta tener la respuesta final. El texto se emite al panel
 * mientras se genera.
 *
 * La conversacion se guarda solo si el turno termina bien, para que el
 * historial siempre sea valido (cada tool_use con su tool_result).
 */
class AiAgent
{
    /** @var User */
    private $user;

    /** @var ClaudeClient */
    private $client;

    private $config;

    public function __construct(User $user, ClaudeClient $client = null)
    {
        $this->user = $user;
        $this->client = $client ?? new ClaudeClient();
        $this->config = config('ai');
        // Modelo del plan del usuario (Admin > Talentus IA CHAT) o el general.
        $this->config['model'] = AiSettings::chatModelFor($user);
    }

    /** Fuerza un modelo (lo usa la prueba comparativa ai:compare). */
    public function setModel(string $model): self
    {
        $this->config['model'] = $model;

        return $this;
    }

    /** Esfuerzo de razonamiento para esta instancia (ai:compare). */
    public function setEffort(string $effort): self
    {
        $this->config['effort'] = $effort;

        return $this;
    }

    /**
     * @param callable $emit fn(string $event, array $data) hacia el panel
     */
    public function ask(AiConversation $conversation, string $question, array $context, callable $emit): array
    {
        Formatter::byUser($this->user);

        $registry = new AiToolRegistry($this->user);

        $stored = $conversation->messages()->get();

        if ($stored->count() >= (int) $this->config['history_messages']) {
            throw new AgentException('conversation_too_long');
        }

        $messages = $stored->map(function (AiMessage $message) {
            return $message->toApiMessage();
        })->all();

        $pending = [];

        $userMessage = (object) [
            'role' => 'user',
            'content' => [(object) [
                'type' => 'text',
                'text' => $this->contextBlock($context, $registry) . "\n\n" . $question,
            ]],
        ];
        $messages[] = $userMessage;
        $pending[] = ['message' => $userMessage, 'display' => ['text' => $question], 'visible' => true];

        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'cost' => 0.0];
        $toolsUsed = [];
        $toolCalls = 0;
        $apiCalls = 0;
        $status = 'ok';
        $model = $this->config['model'];
        $maxCalls = (int) $this->config['max_tool_calls'];

        try {
            while (true) {
                $apiCalls++;

                $response = $this->client->stream($this->body($messages, $registry), function ($delta) use ($emit) {
                    $emit('delta', ['text' => $delta]);
                });

                $model = $response->model ?: $model;
                $this->addUsage($usage, $response);

                $invalidJson = [];
                $toolUses = [];
                $text = '';

                foreach ($response->content as $block) {
                    if (($block->type ?? null) === 'tool_use') {
                        if (isset($block->_invalid_json)) {
                            $invalidJson[$block->id] = $block->_invalid_json;
                            unset($block->_invalid_json);
                        }
                        $toolUses[] = $block;
                    } elseif (($block->type ?? null) === 'text') {
                        $text .= $block->text;
                    }
                }

                $assistant = (object) ['role' => 'assistant', 'content' => $response->content];
                $messages[] = $assistant;
                $display = ['text' => $text, 'sources' => [], 'actions' => []];

                if ($response->stop_reason === 'refusal') {
                    $status = 'refusal';
                    if (trim($text) === '') {
                        $display['text'] = 'No puedo ayudarte con esa solicitud.';
                        $emit('delta', ['text' => $display['text']]);
                    }
                    $pending[] = ['message' => $assistant, 'display' => $display, 'visible' => true];
                    break;
                }

                // Respuesta cortada a mitad de una llamada: no se ejecuta.
                $runTools = $response->stop_reason === 'tool_use' && $toolUses;

                if (!$runTools) {
                    if ($toolUses) {
                        // tool_use sin ejecutar (max_tokens): se responde con error para mantener el historial valido.
                        $pending[] = ['message' => $assistant, 'display' => $display, 'visible' => true];
                        $pending[] = [
                            'message' => (object) ['role' => 'user', 'content' => array_map(function ($b) {
                                return $this->toolResult($b->id, 'Respuesta incompleta; consulta no ejecutada.', true);
                            }, $toolUses)],
                            'display' => null,
                            'visible' => false,
                        ];
                        $status = 'max_tokens';
                    } else {
                        $pending[] = ['message' => $assistant, 'display' => $display, 'visible' => true];
                    }
                    break;
                }

                $results = [];

                foreach ($toolUses as $block) {
                    $toolCalls++;
                    $name = $block->name;
                    $input = json_decode(json_encode($block->input), true) ?: [];
                    $tool = $registry->get($name);

                    if (isset($invalidJson[$block->id])) {
                        $results[] = $this->toolResult($block->id, 'Parametros con JSON invalido: '
                            . mb_substr($invalidJson[$block->id], 0, 300) . '. Vuelve a llamar con JSON valido.', true);
                        continue;
                    }

                    if ($toolCalls > $maxCalls) {
                        $results[] = $this->toolResult($block->id, 'Se alcanzo el limite de consultas para esta '
                            . 'pregunta. Responde con la informacion que ya tienes.', true);
                        continue;
                    }

                    if (!$tool) {
                        $results[] = $this->toolResult($block->id, "Herramienta desconocida: $name", true);
                        continue;
                    }

                    $label = $tool->label($input);
                    if ($label !== '') {
                        $display['sources'][] = $label;
                        $emit('source', ['label' => $label]);
                    }

                    $toolsUsed[] = $name;

                    try {
                        $data = $tool->handle($input);

                        foreach ($tool->getActions() as $action) {
                            $display['actions'][] = $action;
                            $emit('action', $action);
                        }

                        $results[] = $this->toolResult(
                            $block->id,
                            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                            false
                        );
                    } catch (ToolException $e) {
                        $results[] = $this->toolResult($block->id, $e->getMessage(), true);
                    } catch (\Throwable $e) {
                        Log::error('AirBot tool error', ['tool' => $name, 'error' => $e->getMessage(),
                            'file' => $e->getFile() . ':' . $e->getLine()]);
                        $results[] = $this->toolResult($block->id, 'Error interno al consultar los datos. '
                            . 'Informa al usuario que no se pudo obtener esa informacion.', true);
                    }
                }

                $toolMessage = (object) ['role' => 'user', 'content' => $results];
                $messages[] = $toolMessage;

                $pending[] = ['message' => $assistant, 'display' => $display, 'visible' => true];
                $pending[] = ['message' => $toolMessage, 'display' => null, 'visible' => false];

                if ($apiCalls > $maxCalls + 1) {
                    $status = 'limit';
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logUsage($conversation, $model, $usage, $apiCalls, $toolsUsed, 'error');
            throw $e;
        }

        DB::transaction(function () use ($conversation, $pending, $question) {
            foreach ($pending as $item) {
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => $item['message']->role,
                    'content' => json_encode($item['message']->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'display' => $item['display'],
                    'visible' => $item['visible'],
                ]);
            }

            if (!$conversation->title) {
                $conversation->title = mb_substr($question, 0, 80);
            }
            $conversation->touch();
            $conversation->save();
        });

        $this->logUsage($conversation, $model, $usage, $apiCalls, $toolsUsed, $status);

        return [
            'status' => $status,
            'cost_usd' => round($usage['cost'], 5),
            'model' => $model,
            'usage' => $usage,
            'api_calls' => $apiCalls,
            'tools' => $toolsUsed,
        ];
    }

    private function body(array $messages, AiToolRegistry $registry): array
    {
        $body = [
            'model'         => $this->config['model'],
            'max_tokens'    => (int) $this->config['max_tokens'],
            // Herramientas + system son iguales para todos los usuarios con el
            // mismo permiso (~11-13k tokens): punto de cache explicito de 1 h,
            // compartido entre conversaciones y usuarios.
            'system'        => [[
                'type' => 'text',
                'text' => SystemPrompt::text(),
                'cache_control' => ['type' => 'ephemeral', 'ttl' => $this->config['prefix_cache_ttl'] ?? '1h'],
            ]],
            'tools'         => $registry->definitions(),
            'messages'      => $messages,
            'output_config' => ['effort' => $this->config['effort']],
            // Cache automatico (5 min) del resto: la conversacion en curso.
            'cache_control' => ['type' => 'ephemeral'],
        ];

        // Haiku 5.5 no tiene fallback de servidor: solo Sonnet/Opus lo reciben.
        if (AiSettings::supportsFallbacks($this->config['model'])) {
            $body['fallbacks'] = 'default';
        }

        return $body;
    }

    private function toolResult(string $id, string $content, bool $isError)
    {
        $result = (object) [
            'type' => 'tool_result',
            'tool_use_id' => $id,
            'content' => $content,
        ];

        if ($isError) {
            $result->is_error = true;
        }

        return $result;
    }

    private function contextBlock(array $context, AiToolRegistry $registry = null): string
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $local = Formatter::time()->convert($nowUtc, 'Y-m-d H:i');
        $day = Carbon::parse($local)->locale('es')->isoFormat('dddd');

        $timezone = Timezone::find($this->user->timezone_id);
        $tzName = $timezone ? $timezone->title : 'UTC';

        $lines = [
            "Fecha y hora actual del usuario: $local ($day)",
            "Zona horaria: $tzName",
            'Unidad de velocidad: ' . Formatter::speed()->getUnit()
                . ', distancia: ' . Formatter::distance()->getUnit(),
        ];

        if (!empty($context['screen'])) {
            $lines[] = 'Pantalla actual: ' . mb_substr(preg_replace('/[^\p{L}\p{N} _\-\/]/u', '', $context['screen']), 0, 60);
        }

        // Va en el mensaje (no en las instrucciones) para no partir el cache
        // de 1 h entre usuarios con distintos permisos.
        $denied = $registry ? $registry->deniedFeatures() : [];
        if ($denied) {
            $lines[] = 'Esta cuenta NO tiene permiso para: ' . implode('; ', $denied)
                . '. Si pregunta por algo de esto, no expliques pasos ni des botones ni alternativas: '
                . 'dile que su cuenta no tiene acceso y que lo pida al administrador.';
        }

        return "<contexto>\n" . implode("\n", $lines) . "\n</contexto>";
    }

    private function addUsage(array &$usage, $response)
    {
        $u = $response->usage ?? new \stdClass();

        $input = (int) ($u->input_tokens ?? 0);
        $output = (int) ($u->output_tokens ?? 0);
        $cacheRead = (int) ($u->cache_read_input_tokens ?? 0);
        $cacheWrite = (int) ($u->cache_creation_input_tokens ?? 0);

        $usage['input'] += $input;
        $usage['output'] += $output;
        $usage['cache_read'] += $cacheRead;
        $usage['cache_write'] += $cacheWrite;

        $usage['cost'] += AiPricing::cost($response->model ?? $this->config['model'], $u);
    }

    private function logUsage(AiConversation $conversation, string $model, array $usage, int $apiCalls, array $tools, string $status)
    {
        try {
            AiUsageLog::create([
                'user_id' => $this->user->id,
                'conversation_id' => $conversation->id,
                'model' => $model,
                'input_tokens' => $usage['input'],
                'output_tokens' => $usage['output'],
                'cache_read_tokens' => $usage['cache_read'],
                'cache_write_tokens' => $usage['cache_write'],
                'api_calls' => $apiCalls,
                'cost_usd' => round($usage['cost'], 5),
                'tools' => array_values(array_unique($tools)),
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::error('AirBot usage log error: ' . $e->getMessage());
        }
    }
}
