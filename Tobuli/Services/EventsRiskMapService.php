<?php

namespace Tobuli\Services;

use Tobuli\Entities\Event;
use Tobuli\Entities\User;

/**
 * Mapa de riesgo de eventos: puntos de los eventos del usuario con su gravedad,
 * compartido entre la UI v2 (Eventos → «Mapa de riesgo») y la app móvil.
 */
class EventsRiskMapService
{
    /** Máximo de puntos por respuesta (los más recientes). */
    public const CAP = 1500;

    /** Horas hacia atrás como máximo cuando se filtra por horas. */
    public const MAX_HOURS = 168;

    /**
     * @param array{hours?: int, date_from?: string|null, type?: string, q?: string, device_id?: int} $filters
     *        hours: últimas N horas (0 = sin límite, máx. MAX_HOURS); date_from: desde esa fecha
     *        en UTC 'Y-m-d H:i:s' (tiene prioridad sobre hours).
     * @return array{points: array, capped: bool, cap: int}
     */
    public function points(User $user, array $filters = []): array
    {
        $hoursRaw = (int) ($filters['hours'] ?? 0);
        $hours    = $hoursRaw <= 0 ? 0 : min(self::MAX_HOURS, max(1, $hoursRaw));
        $dateFrom = $filters['date_from'] ?? null;
        $type     = trim((string) ($filters['type'] ?? ''));
        $q        = trim((string) ($filters['q'] ?? ''));
        $deviceId = (int) ($filters['device_id'] ?? 0);

        // Mismo alcance que la lista de eventos: propios + de alertas compartidas.
        $query = Event::userAccessible($user)
            ->with(['device' => function ($qq) { $qq->select('id', 'name'); }])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('time', 'desc');

        if ($dateFrom) {
            $query->where('time', '>=', $dateFrom);
        } elseif ($hours > 0) {
            $query->where('time', '>=', date('Y-m-d H:i:s', strtotime("-{$hours} hours")));
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }
        if ($q !== '') {
            $query->where('message', 'like', '%' . $q . '%');
        }

        $query->when(settings('plugins.event_section_alert.status'), function ($q) {
            $q->with('alert');
        });

        // alert_id hace falta para que Event::getTitleAttribute() resuelva el nombre de la alerta.
        $rows   = $query->limit(self::CAP + 1)->get(['id', 'type', 'message', 'alert_id', 'device_id', 'latitude', 'longitude', 'time']);
        $capped = $rows->count() > self::CAP;
        if ($capped) {
            $rows = $rows->slice(0, self::CAP);
        }

        $points = $rows->map(fn ($e) => [
            'id'          => (int) $e->id,
            'lat'         => (float) $e->latitude,
            'lng'         => (float) $e->longitude,
            'type'        => (string) $e->type,
            'severity'    => NotificationsTrayService::severity((string) $e->type),
            'message'     => (string) $e->message,
            'title'       => (string) $e->title,
            'device_id'   => $e->device_id ? (int) $e->device_id : null,
            'device_name' => $e->device->name ?? null,
            'time'        => \Formatter::time()->convert($e->time),
        ])->values()->all();

        return ['points' => $points, 'capped' => $capped, 'cap' => self::CAP];
    }
}
