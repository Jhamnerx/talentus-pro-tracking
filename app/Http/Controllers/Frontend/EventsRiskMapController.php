<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Tobuli\Services\EventsRiskMapService;

/**
 * Mapa de riesgo de eventos para la app (user_api_hash), igual que
 * Ui\V2\EventsController@mapData.
 */
class EventsRiskMapController extends Controller
{
    /**
     * Query: device_id, type, q, y el rango con hours (máx. 168) o date_from
     * ('Y-m-d H:i:s' en la zona del usuario, sin tope de horas).
     */
    public function apiPoints(EventsRiskMapService $map)
    {
        $this->checkException('events', 'view');

        $dateFrom = trim((string) request()->get('date_from', ''));

        $result = $map->points($this->user, [
            'hours'     => (int) request()->get('hours', 0),
            'date_from' => $dateFrom !== '' ? \Formatter::time()->reverse($dateFrom) : null,
            'type'      => request()->get('type', ''),
            'q'         => request()->get('q', ''),
            'device_id' => (int) request()->get('device_id', 0),
        ]);

        return ['status' => 1] + $result;
    }
}
