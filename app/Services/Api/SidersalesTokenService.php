<?php

namespace App\Services\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona la obtención y renovación del token OAuth 2.0 (Azure AD — Client Credentials)
 * para el servicio Sidersales (Gerdau). El token se almacena en caché por usuario.
 */
class SidersalesTokenService
{
    const TENANT_ID  = '461fd7ef-0eb3-4420-b044-310dc2914d64';
    const SCOPE      = 'https://graph.microsoft.com/.default';
    const TOKEN_URL  = 'https://login.microsoftonline.com/' . self::TENANT_ID . '/oauth2/v2.0/token';

    /** Minutos que el token se mantiene en caché (el token real dura 3600 s ≈ 60 min) */
    const TOKEN_TTL_MINUTES = 55;

    /**
     * Devuelve la clave de caché única por usuario.
     */
    public static function cacheKey(int $userId): string
    {
        return "sidersales_access_token_{$userId}";
    }

    /**
     * Retorna un token válido. Si existe en caché lo reutiliza; si no, solicita uno nuevo.
     *
     * @param  int    $userId  ID del usuario dueño de las credenciales
     * @param  array  $service Sección sidersales de $user->services
     * @return string          Bearer token
     * @throws \RuntimeException si no se puede obtener el token
     */
    public function getToken(int $userId, array $service): string
    {
        $key = self::cacheKey($userId);

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        return $this->refreshToken($userId, $service);
    }

    /**
     * Solicita un nuevo token a Azure AD, lo almacena en caché y lo retorna.
     *
     * @param  int    $userId  ID del usuario dueño de las credenciales
     * @param  array  $service Sección sidersales de $user->services
     * @return string          Bearer token recién emitido
     * @throws \RuntimeException si las credenciales son inválidas o la solicitud falla
     */
    public function refreshToken(int $userId, array $service): string
    {
        $clientId     = $service['client_id']     ?? null;
        $clientSecret = $service['client_secret'] ?? null;

        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException("Credenciales Sidersales incompletas para el usuario {$userId}.");
        }

        try {
            $client   = new Client(['verify' => false]);
            $response = $client->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'scope'         => self::SCOPE,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (empty($body['access_token'])) {
                throw new \RuntimeException(
                    "Respuesta inválida al renovar token Sidersales para usuario {$userId}: " . json_encode($body)
                );
            }

            Cache::put(self::cacheKey($userId), $body['access_token'], now()->addMinutes(self::TOKEN_TTL_MINUTES));

            Log::channel('sidersales')->info('Token Sidersales renovado y almacenado en caché', [
                'user_id'    => $userId,
                'expires_in' => $body['expires_in'] ?? 3600,
            ]);

            return $body['access_token'];

        } catch (RequestException $e) {
            $message = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();

            Log::channel('sidersales')->error('Error al renovar token Sidersales', [
                'user_id' => $userId,
                'error'   => $message,
            ]);

            throw new \RuntimeException("Error al renovar token Sidersales para usuario {$userId}: {$message}", 0, $e);
        }
    }

    /**
     * Invalida el token almacenado en caché para el usuario dado.
     */
    public function forgetToken(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }
}
