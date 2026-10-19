<?php

namespace Tobuli\History\Actions;

/**
 * Dentro de una geocerca con limite manda la geocerca (menor area si se
 * solapan, por tipo y estado del vehiculo); fuera, el limite manual del
 * reporte (config 'speed_limit'). Sin limite manual, fuera no se evalua.
 */
class AppendSpeedLimitGeofenceOrStatic extends AppendSpeedLimitGeofenceByPriority
{
    protected $static;

    public function boot()
    {
        parent::boot();
        $this->static = $this->history->hasConfig('speed_limit') && $this->history->config('speed_limit') > 0
            ? (float) $this->history->config('speed_limit')
            : null;
    }

    public function proccess(&$position)
    {
        parent::proccess($position);

        if (empty($position->speed_limit)) {
            $position->speed_limit = $this->static;
        }
    }
}
