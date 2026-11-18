<?php

/*
|--------------------------------------------------------------------------
| Talentus IA CHAT - asistente IA (API de Claude)
|--------------------------------------------------------------------------
| El proyecto corre en PHP 7.4/8.0, por eso no se usa el SDK oficial de
| Anthropic (requiere 8.1+). App\Services\Ai\ClaudeClient habla con la API
| por HTTP con Guzzle.
|
| Nombre, clave de la API, modelos, esfuerzo, interruptor y modelo por plan
| se configuran en Admin > Talentus IA CHAT (App\Services\Ai\AiSettings).
| Los valores de este archivo (.env) solo se usan si el campo de Admin
| esta vacio.
*/

return [

    // Interruptor general. Sin clave de API el asistente queda apagado.
    'enabled' => env('AI_ENABLED', true),

    'api_key'     => env('ANTHROPIC_API_KEY'),
    'base_url'    => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    'api_version' => env('ANTHROPIC_VERSION', '2026-10-21'),

    // Modelo del chat y profundidad de razonamiento (low|medium|high).
    'model'      => env('AI_MODEL', 'claude-sonnet-5-5'),
    'effort'     => env('AI_EFFORT', 'medium'),
    'prefix_cache_ttl' => env('AI_PREFIX_CACHE_TTL', '1h'), // cache de tools+system: '1h' o '5m'
    'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),

    // Reintento automatico en el servidor si el modelo rechaza una peticion
    // por sus filtros de seguridad (beta server-side-fallback). Solo se envia
    // con los modelos que lo aceptan (AiSettings::FALLBACK_MODELS).
    'fallbacks' => env('AI_FALLBACKS', true),

    // Timeouts de la conexion con la API (segundos).
    'connect_timeout' => 10,
    'timeout'         => (int) env('AI_TIMEOUT', 180),
    'max_retries'     => 2,

    // Limites del agente.
    'max_tool_calls'   => 6,     // llamadas a herramientas por pregunta
    'max_range_days'   => (int) env('AI_MAX_RANGE_DAYS', 31),
    'max_devices'      => (int) env('AI_MAX_DEVICES', 100),
    'history_messages' => 40,    // mensajes de la conversacion que se reenvian

    // Modelo para tareas simples y masivas (resumenes automaticos).
    'light_model' => env('AI_LIGHT_MODEL', 'claude-haiku-5-5'),

    // Funciones fuera del chat (todas requieren el permiso ai_assistant).
    'features' => [
        'explain_report' => env('AI_EXPLAIN_REPORT', true),   // boton en reportes HTML
        'report_email'   => env('AI_REPORT_EMAIL', true),     // resumen en reportes programados
        'dashboard'      => env('AI_DASHBOARD', true),        // tarjeta de resumen en el tablero v2
        'alerts_digest'  => env('AI_ALERTS_DIGEST', false),   // correo diario (apagado: escribe a clientes)
    ],

    // Cuotas.
    'monthly_questions_per_user' => (int) env('AI_MONTHLY_QUESTIONS', 100), // 0 = sin limite
    'monthly_budget_usd'         => (float) env('AI_MONTHLY_BUDGET_USD', 0), // 0 = sin tope

    // Modelos que se pueden elegir en Admin (deben tener precio abajo).
    'models' => [
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (equilibrado)',
        'claude-haiku-5-5'  => 'Claude Haiku 5.5 (economico)',
        'claude-opus-5-5'   => 'Claude Opus 5.5 (maxima calidad)',
    ],

    // Precios por millon de tokens (USD) para calcular el costo en ai_usage_logs.
    // long_context: Haiku 5.5 cobra 5x la peticion entera cuando el prompt
    // (input + cache) pasa de 100k tokens.
    'pricing' => [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50, 'cache_write_1h' => 4.00],
        'claude-haiku-5-5'  => ['input' => 0.10, 'output' => 0.50,  'cache_read' => 0.01, 'cache_write' => 0.125, 'cache_write_1h' => 0.20,
                                'long_context' => ['threshold' => 100000, 'multiplier' => 5]],
        'claude-opus-5-5'   => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00, 'cache_write_1h' => 8.00],
    ],
];
