<?php

namespace App\Services\Ai\Tools\Actions;

use App\Services\Ai\Tools\AbstractTool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tobuli\Entities\User;

/**
 * Herramienta que CAMBIA algo. Nunca ejecuta: valida, arma una tarjeta de
 * confirmacion y guarda la accion pendiente 15 minutos. Solo se ejecuta si el
 * usuario pulsa "Confirmar" (AiAssistantController::confirm), que vuelve a
 * comprobar permisos y deja registro en ai_action_logs.
 */
abstract class ActionTool extends AbstractTool
{
    const PENDING_TTL_MINUTES = 15;

    /**
     * Valida el pedido y devuelve lo que vera el usuario.
     *
     * @return array ['title' => string, 'details' => string[], 'confirm' => string, 'params' => array]
     */
    abstract protected function prepare(array $input): array;

    /**
     * Ejecuta la accion ya confirmada.
     *
     * @return string mensaje de resultado para el usuario
     * @throws \Throwable
     */
    abstract public static function execute(User $user, array $params): string;

    /** Permiso propio que exige la accion, ademas de ai_assistant (edit). */
    abstract public static function allowed(User $user, array $params): bool;

    /** Errores de una ValidationException de la plataforma en una linea. */
    protected static function errorsText(\Throwable $e): string
    {
        $errors = method_exists($e, 'getErrors') ? $e->getErrors() : null;

        if (is_object($errors) && method_exists($errors, 'all')) {
            $errors = $errors->all();
        }

        $flat = [];
        array_walk_recursive($errors, function ($value) use (&$flat) {
            $flat[] = (string) $value;
        });

        return $flat ? implode(' ', $flat) : $e->getMessage();
    }

    protected function run(array $input): array
    {
        $card = $this->prepare($input);

        $token = Str::random(40);

        Cache::put('tpai.pending.' . $token, [
            'class' => static::class,
            'action' => $this->name(),
            'user_id' => $this->user->id,
            'title' => $card['title'],
            'params' => $card['params'],
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));

        $this->actions[] = [
            'type' => 'confirm',
            'token' => $token,
            'title' => $card['title'],
            'details' => $card['details'],
            'confirm' => $card['confirm'] ?? 'Confirmar',
        ];

        return [
            'estado' => 'pendiente_de_confirmacion',
            'resumen' => $card['title'],
            'detalle' => $card['details'],
            'instruccion' => 'La accion NO se ha ejecutado. El usuario ve una tarjeta con los botones Confirmar y '
                . 'Cancelar; se ejecuta solo si pulsa Confirmar (valida ' . self::PENDING_TTL_MINUTES . ' minutos). '
                . 'Pidele que revise y confirme; no digas que ya se hizo.',
        ];
    }
}
