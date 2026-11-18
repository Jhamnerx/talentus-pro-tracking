<?php

namespace App\Http\Middleware;

use App\Services\Ai\ClaudeClient;
use Closure;

/**
 * Acceso a AirBot: asistente configurado + permiso ai_assistant (view).
 * Ocultar el boton no basta: las rutas /ai/* tambien se protegen aqui.
 */
class AiAssistantAccess
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->perm('ai_assistant', 'view')) {
            return response()->json(['message' => trans('front.dont_have_permission')], 403);
        }

        if (!ClaudeClient::isConfigured()) {
            return response()->json(['message' => trans('front.ai_disabled')], 503);
        }

        return $next($request);
    }
}
