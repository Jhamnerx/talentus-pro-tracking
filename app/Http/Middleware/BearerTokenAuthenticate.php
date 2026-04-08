<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tobuli\Entities\User;

class BearerTokenAuthenticate
{
    public function __construct()
    {
        Config::set('tobuli.api', 1);
    }

    public function handle($request, Closure $next)
    {
        $token = $this->getBearerToken($request);

        if (empty($token)) {
            return response()->json([
                'responseCode' => 401,
                'message'      => 'Error en la autenticación',
                'items'        => [],
                'errors'       => ['Token de autenticación requerido'],
            ], 401);
        }

        $user = User::where('api_hash', $token)->first();

        if (empty($user)) {
            return response()->json([
                'responseCode' => 401,
                'message'      => 'Error en la autenticación',
                'items'        => [],
                'errors'       => ['Token de autenticación inválido o expirado'],
            ], 401);
        }

        if (! $user->active) {
            return response()->json([
                'responseCode' => 401,
                'message'      => 'Error en la autenticación',
                'items'        => [],
                'errors'       => ['El usuario se encuentra desactivado'],
            ], 401);
        }

        Auth::onceUsingId($user->id);
        setActingUser(Auth::user());

        return $next($request);
    }

    private function getBearerToken($request): ?string
    {
        $header = $request->header('Authorization', '');

        if (Str::startsWith($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
