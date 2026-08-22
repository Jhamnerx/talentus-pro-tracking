<?php

namespace Tobuli\Helpers\Alerts\Check;

use Tobuli\Entities\Event;
use Tobuli\Services\ConditionService;

/**
 * Alarma del equipo — un solo tipo para las alarmas normalizadas de Traccar.
 *
 * El decoder deja en el parámetro `alarm` un valor estándar (hardBraking,
 * accident, jamming…) sea cual sea el protocolo, así que una sola alerta vale
 * para toda la flota: no hace falta un evento personalizado por protocolo ni
 * por puerto del equipo (io247, io252, io318…).
 *
 * El usuario elige QUÉ alarmas vigilar; se guardan en `data.alarms`.
 *
 * Ojo con la comparación: Position::addAlarm() de Traccar acumula separando por
 * comas, así que un equipo puede reportar "hardBraking,overspeed" de una vez.
 * Por eso se compara con el operador "incluye" (elemento a elemento), y no con
 * una igualdad, que se dejaría fuera justo los casos dobles.
 */
class DeviceAlarmAlertCheck extends AlertCheck
{
    /**
     * Alarmas ofrecidas, agrupadas para el formulario.
     *
     * No están todas las de Traccar a propósito: solo las que sus decoders
     * emiten para los protocolos en uso y que además significan algo para el
     * cliente. `sos` NO está: ya tiene su propio tipo de alerta y ofrecerla
     * aquí produciría dos avisos del mismo hecho.
     */
    public const ALARMAS = [
        'conduccion' => [
            'hardAcceleration' => Event::TYPE_HARSH_ACCELERATION,
            'hardBraking'      => Event::TYPE_HARSH_BRAKING,
            'hardCornering'    => Event::TYPE_HARSH_CORNERING,
            'highRpm'          => Event::TYPE_HIGH_RPM,
        ],
        'seguridad' => [
            'accident' => Event::TYPE_ACCIDENT,
        ],
        'equipo' => [
            'powerCut' => Event::TYPE_POWER_CUT,
            'jamming'  => Event::TYPE_JAMMING,
        ],
    ];

    public function checkEvents($position, $prevPosition)
    {
        if (! $position) {
            return null;
        }

        if (! $this->checkAlertPosition($position)) {
            return null;
        }

        $vigiladas = $this->alarmasVigiladas();

        if (! $vigiladas) {
            return null;
        }

        $valor = $position->getParameter('alarm');

        if ($valor === null || $valor === '') {
            return null;
        }

        $eventos = [];

        foreach ($vigiladas as $alarma) {
            if (! ConditionService::check(ConditionService::TYPE_CONTAINS, $valor, $alarma)) {
                continue;
            }

            // Repetida respecto a la posición anterior: no se avisa dos veces
            // del mismo hecho mientras el equipo lo siga reportando.
            if ($prevPosition
                && ConditionService::check(
                    ConditionService::TYPE_CONTAINS,
                    $prevPosition->getParameter('alarm'),
                    $alarma
                )
            ) {
                continue;
            }

            $event = $this->getEvent();
            $event->type = $this->tipoDeEvento($alarma);
            $event->message = trans('front.alarm_' . $alarma);

            $this->silent($event);

            $eventos[] = $event;
        }

        return $eventos ?: null;
    }

    /** Alarmas elegidas por el usuario, filtradas contra el catálogo. */
    private function alarmasVigiladas(): array
    {
        $elegidas = (array) ($this->alert->alarms ?? []);

        return array_values(array_intersect($elegidas, array_keys(self::catalogo())));
    }

    /** El tipo de evento propio de cada alarma, para reportes y puntuación. */
    private function tipoDeEvento(string $alarma): string
    {
        return self::catalogo()[$alarma] ?? Event::TYPE_CUSTOM;
    }

    /** El catálogo aplanado: alarma => tipo de evento. */
    public static function catalogo(): array
    {
        static $plano = null;

        if ($plano === null) {
            $plano = [];
            foreach (self::ALARMAS as $grupo) {
                $plano += $grupo;
            }
        }

        return $plano;
    }
}
