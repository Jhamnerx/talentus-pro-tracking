<?php

namespace Tobuli\Helpers\Alerts\Check;

use Tobuli\Entities\Event;

/**
 * Alerta de Velocidad Fantasma (Ghost Speed):
 *
 * Detecta cuando el equipo GPS reporta una velocidad distinta de cero pero
 * la posición no ha cambiado (distance = 0) con la ignición encendida (di1 = 1).
 *
 * Este problema ocurre principalmente en firmware Teltonika FMB cuando el
 * vehículo está detenido con motor encendido: el equipo envía la última
 * velocidad registrada en movimiento aunque las coordenadas sean las mismas.
 *
 * La corrección automática ya se aplica en PositionsWriter::correctGhostSpeed(),
 * que pone la velocidad a 0 y marca el parámetro 'ghost_speed' = 'true'.
 * Esta clase dispara una alerta configurable para notificar al usuario.
 */
class GhostSpeedAlertCheck extends AlertCheck
{
    /**
     * Solo debe dispararse una vez por tramo de ghost speed consecutivo,
     * no en cada posición repetida. Se usa el mecanismo de silenciado (60s).
     */
    protected $silentDuration = 60;

    public function checkEvents($position, $prevPosition)
    {
        if (!$position) {
            return null;
        }

        // Solo disparar cuando el parámetro ghost_speed fue marcado por PositionsWriter
        if ($position->getParameter('ghost_speed') !== 'true') {
            return null;
        }

        if (!$this->checkAlertPosition($position)) {
            return null;
        }

        $event = $this->getEvent();
        $event->type = Event::TYPE_GHOST_SPEED;
        $event->message = 'Velocidad fantasma detectada: equipo reportó velocidad sin desplazamiento real (ignición encendida)';
        $event->setAdditional('ghost_speed_value', $position->getParameter('ghost_speed'));

        $this->silent($event);

        return [$event];
    }
}
