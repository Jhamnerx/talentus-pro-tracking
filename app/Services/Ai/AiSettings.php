<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Tobuli\Entities\User;

/**
 * Ajustes del asistente guardados en Admin > Talentus IA CHAT (tabla configs,
 * grupo "ai_provider"): nombre del asistente, clave de la API, modelo del
 * chat, modelo de los resumenes, esfuerzo y modelo por plan.
 *
 * Lo guardado en Admin manda; lo que este vacio se toma de config/ai.php
 * (.env). apply() vuelca los valores en config('ai.*') una vez por proceso,
 * asi el resto del codigo sigue leyendo config('ai.*') sin cambios.
 */
class AiSettings
{
    const GROUP = 'ai_provider';

    const DEFAULT_NAME = 'Talentus IA CHAT';

    const EFFORTS = ['low', 'medium', 'high'];

    /** Modelos que aceptan el fallback del servidor ante un rechazo. */
    const FALLBACK_MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5'];

    private static $applied = false;

    public static function apply(bool $force = false)
    {
        if (self::$applied && !$force) {
            return;
        }

        self::$applied = true;
        $stored = self::stored();

        if (!empty($stored['api_key'])) {
            $key = self::decryptKey($stored['api_key']);
            if ($key !== null) {
                config(['ai.api_key' => $key]);
            }
        }

        foreach (['model', 'light_model'] as $field) {
            if (!empty($stored[$field]) && self::isKnownModel($stored[$field])) {
                config(["ai.$field" => $stored[$field]]);
            }
        }

        if (!empty($stored['effort']) && in_array($stored['effort'], self::EFFORTS, true)) {
            config(['ai.effort' => $stored['effort']]);
        }

        if (array_key_exists('enabled', $stored) && $stored['enabled'] !== '' && $stored['enabled'] !== null) {
            config(['ai.enabled' => (bool) $stored['enabled']]);
        }
    }

    /** Lo guardado en Admin, sin mezclar con .env. */
    public static function stored(): array
    {
        try {
            return (array) (settings(self::GROUP) ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function save(array $values)
    {
        settings(self::GROUP, $values);
        self::apply(true);
        self::forget();
    }

    /** Nombre visible del asistente (panel, correos, instrucciones del modelo). */
    public static function name(): string
    {
        $name = trim((string) (self::stored()['name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 40) : self::DEFAULT_NAME;
    }

    /** Clave principal configurada y asistente encendido (sin mirar resellers). */
    public static function isConfigured(): bool
    {
        self::apply();

        return (bool) config('ai.enabled') && !empty(config('ai.api_key'));
    }

    /**
     * El asistente funciona para este usuario: con la clave de su reseller o,
     * si el reseller no tiene y el admin se lo permite, con la principal.
     */
    public static function isConfiguredFor(User $user = null): bool
    {
        if (!$user) {
            return self::isConfigured();
        }

        $config = self::configFor($user);

        return (bool) $config['enabled'] && !empty($config['api_key']);
    }

    /** Modelo del chat para el usuario (reseller con clave propia > plan > general). */
    public static function chatModelFor(User $user = null): string
    {
        if ($user) {
            return (string) self::configFor($user)['model'];
        }

        return self::mainChatModel(null);
    }

    private static function mainChatModel(User $user = null): string
    {
        self::apply();

        $planModels = (array) (self::stored()['plan_models'] ?? []);
        $planId = $user ? (int) $user->billing_plan_id : 0;

        if ($planId && !empty($planModels[$planId]) && self::isKnownModel($planModels[$planId])) {
            return $planModels[$planId];
        }

        return (string) config('ai.model');
    }

    /* ------------------------------------------------------------------
     | Clave por reseller
     * ------------------------------------------------------------------ */

    private static $resellerCache = [];
    private static $configCache = [];

    /**
     * Reseller que atiende al usuario: el mismo si es reseller, si no el
     * primero subiendo por manager_id (hasta 5 niveles). null = cliente
     * directo del admin principal.
     */
    public static function resellerFor(User $user)
    {
        if (array_key_exists($user->id, self::$resellerCache)) {
            return self::$resellerCache[$user->id];
        }

        $current = $user;
        $found = null;

        for ($i = 0; $i < 5 && $current; $i++) {
            if ($current->isReseller()) {
                $found = $current;
                break;
            }
            $current = $current->manager_id ? User::find($current->manager_id) : null;
        }

        return self::$resellerCache[$user->id] = $found;
    }

    /** Ajustes guardados del reseller (o null si nunca los configuro). */
    public static function resellerSettings($reseller)
    {
        return $reseller ? \Tobuli\Entities\AiResellerSetting::find($reseller->id) : null;
    }

    /**
     * Configuracion efectiva de la API para un usuario: clave, modelos,
     * esfuerzo y quien paga (key_owner_id: id del reseller o null = principal).
     */
    public static function configFor(User $user): array
    {
        if (isset(self::$configCache[$user->id])) {
            return self::$configCache[$user->id];
        }

        self::apply();
        $config = config('ai');
        $config['model'] = self::mainChatModel($user);
        $config['key_owner_id'] = null;

        $reseller = self::resellerFor($user);
        $settings = self::resellerSettings($reseller);
        $resellerKey = $settings && $settings->api_key ? self::decryptKey($settings->api_key) : null;

        if ($reseller && $resellerKey) {
            $config['api_key'] = $resellerKey;
            $config['key_owner_id'] = $reseller->id;
            foreach (['model', 'light_model'] as $field) {
                if ($settings->$field && self::isKnownModel($settings->$field)) {
                    $config[$field] = $settings->$field;
                }
            }
            if ($settings->effort && in_array($settings->effort, self::EFFORTS, true)) {
                $config['effort'] = $settings->effort;
            }
        } elseif ($reseller && $settings && !$settings->allow_main_key) {
            // Reseller sin clave propia al que el admin no le deja usar la principal.
            $config['api_key'] = null;
        }

        return self::$configCache[$user->id] = $config;
    }

    /** Quien paga las llamadas de este usuario: id del reseller o null (principal). */
    public static function keyOwnerFor(User $user)
    {
        return self::configFor($user)['key_owner_id'];
    }

    /** Limpia la cache del proceso (tras guardar ajustes). */
    public static function forget()
    {
        self::$resellerCache = self::$configCache = [];
    }

    public static function saveReseller(int $resellerId, array $values)
    {
        \Tobuli\Entities\AiResellerSetting::updateOrCreate(['user_id' => $resellerId], $values);
        self::forget();
    }

    /** Modelos con precio en config/ai.php (los unicos que se pueden elegir). */
    public static function models(): array
    {
        return (array) config('ai.models', []);
    }

    public static function isKnownModel($model): bool
    {
        return is_string($model) && array_key_exists($model, (array) config('ai.pricing'));
    }

    public static function supportsFallbacks(string $model): bool
    {
        return (bool) config('ai.fallbacks') && in_array($model, self::FALLBACK_MODELS, true);
    }

    /** De donde sale la clave activa: 'admin', 'env' o null. */
    public static function keySource()
    {
        $stored = self::stored();

        if (!empty($stored['api_key']) && self::decryptKey($stored['api_key']) !== null) {
            return 'admin';
        }

        return env('ANTHROPIC_API_KEY') ? 'env' : null;
    }

    /** Ultimos 4 caracteres de la clave activa, para mostrarla en Admin. */
    public static function maskedKey(): string
    {
        self::apply();
        $key = (string) config('ai.api_key');

        return $key === '' ? '' : '••••' . substr($key, -4);
    }

    public static function encryptKey(string $key): string
    {
        return encrypt($key);
    }

    private static function decryptKey($value)
    {
        try {
            return decrypt($value);
        } catch (\Throwable $e) {
            // Cambio de APP_KEY: la clave guardada ya no se puede leer.
            Log::warning('IA: no se pudo descifrar la clave de la API guardada en Admin');
            return null;
        }
    }
}
