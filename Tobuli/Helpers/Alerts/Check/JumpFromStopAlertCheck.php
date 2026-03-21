<?php

namespace Tobuli\Helpers\Alerts\Check;

use Tobuli\Entities\Event;

/**
 * Alerta de Salto de Posición desde Parada (Jump from Stop):
 *
 * Se dispara cuando el dispositivo estaba detenido (speed ≈ 0) y en el
 * siguiente reporte aparece a una distancia mayor al umbral configurado
 * ('from_stop_max_jump_meters', por defecto 150m).
 *
 * Esto indica una de estas situaciones:
 * - GPS drift acumulado que se libera cuando el vehículo arranca.
 * - Salto fantasma de coordenadas por pérdida y recuperación de señal.
 * - Primer tramo de movimiento donde el dispositivo ya estaba moviéndose
 *   antes de capturar la posición (latencia en el reporte de 60s).
 *
 * PositionsWriter::detectJumpFromStop() marca el parámetro 'jump_from_stop'
 * y, si el avg_speed supera la velocidad reportada (teleportación), invalida
 * la posición. Esta clase dispara la alerta configurable para notificar.
 */
class JumpFromStopAlertCheck extends AlertCheck
{
    protected $silentDuration = 120;

    public function checkEvents($position, $prevPosition)
    {
        if (!$position) {
            return null;
        }

        if ($position->getParameter('jump_from_stop') !== 'true') {
            return null;
        }

        if (!$this->checkAlertPosition($position)) {
            return null;
        }

        $event = $this->getEvent();
        $event->type    = Event::TYPE_JUMP_FROM_STOP;
        $event->message = 'Salto de posición detectado desde parada: primera posición en movimiento supera el umbral de distancia permitido desde reposo';

        $this->silent($event);

        return [$event];
    }
}
