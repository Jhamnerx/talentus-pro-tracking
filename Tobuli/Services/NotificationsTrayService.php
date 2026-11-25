<?php

namespace Tobuli\Services;

use Carbon\Carbon;
use Tobuli\Entities\Event;
use Tobuli\Entities\User;

/**
 * Bandeja de notificaciones (últimos eventos de alerta del usuario) y su estado
 * «leído», compartido entre la UI v2 y la app móvil.
 *
 * El leído se guarda en la cuenta (users.settings → SEEN_KEY) como el id del último
 * evento marcado como visto: solo avanza, así un dispositivo con la lista desfasada
 * no desmarca lo que otro ya leyó. Las cuentas demo no guardan nada (seen_id null).
 */
class NotificationsTrayService
{
    public const SEEN_KEY = 'tp_notif_seen_id';

    public const PER_PAGE = 30;

    /** Tope del contador de no leídos (la app muestra «99+»). */
    public const UNREAD_CAP = 100;

    /** Gravedad del tipo de evento (convención de la UI). */
    public static function severity(string $type): string
    {
        $critical = ['sos', 'overspeed', 'geofence_overspeed', 'unplugged', 'fuel_theft', 'jump_from_stop', 'ghost_speed'];
        $warning  = ['zone_out', 'idle_duration', 'stop_duration', 'operation_stop', 'offline_duration', 'expiring_device', 'expiring_sim', 'expiring_user', 'driver_unauthorized'];

        if (in_array($type, $critical, true)) return 'critical';
        if (in_array($type, $warning, true))  return 'warning';

        return 'info';
    }

    /** Id del último evento visto, o null en cuentas demo. */
    public function seenId(User $user): ?int
    {
        return isDemoUser($user) ? null : (int) $user->getSettings(self::SEEN_KEY);
    }

    /**
     * Página de la bandeja, del más nuevo al más viejo. Con $beforeId se piden los
     * más viejos que ese id (scroll infinito).
     *
     * @param callable|null $urlFor fn(Event): ?string — enlace propio del cliente (la web lo usa)
     */
    public function page(User $user, int $beforeId = 0, ?callable $urlFor = null): array
    {
        if (! $user->perm('events', 'view')) {
            return ['notifications' => [], 'has_more' => false, 'seen_id' => $this->seenId($user)];
        }

        $query = Event::userAccessible($user)
            ->with(['device', 'geofence'])
            ->orderBy('events.id', 'desc');

        if ($beforeId > 0) {
            $query->where('events.id', '<', $beforeId);
        }

        $events = $query->take(self::PER_PAGE)->get();
        $seenId = $this->seenId($user);

        $items = $events->map(function (Event $event) use ($seenId, $urlFor) {
            $deviceName = $event->device->name ?? null;

            $item = [
                'id'          => (int) $event->id,
                'type'        => (string) $event->type,
                'title'       => (string) $event->title,
                'description' => trim(implode(' · ', array_filter([
                    $deviceName,
                    $event->geofence->name ?? null,
                ]))),
                'variant'     => self::severity((string) $event->type),
                'category'    => 'alerts',
                'read'        => $seenId !== null && (int) $event->id <= $seenId,
                // `time` está en UTC; `created_at` lo escribe MySQL en la hora local del servidor.
                'created_at'  => $event->time
                    ? Carbon::parse((string) $event->time, 'UTC')->toIso8601String()
                    : optional($event->created_at)->toIso8601String(),
                // En la zona del usuario.
                'time'        => $event->time ? \Formatter::time()->human($event->time) : null,
                'device_id'   => $event->device_id ? (int) $event->device_id : null,
                'device_name' => $deviceName,
                'lat'         => $event->latitude !== null ? (float) $event->latitude : null,
                'lng'         => $event->longitude !== null ? (float) $event->longitude : null,
                // Para abrir el detalle del evento en la app (y «Atender» si viene de una alerta).
                'alert_id'    => $event->alert_id ? (int) $event->alert_id : null,
                'speed'       => $event->speed !== null ? (float) $event->speed : null,
                'address'     => $event->address ?: null,
                'message'     => (string) ($event->message ?? ''),
            ];

            if ($urlFor) {
                $item['url'] = $urlFor($event);
            }

            return $item;
        })->all();

        return [
            'notifications' => $items,
            'has_more'      => count($items) === self::PER_PAGE,
            'seen_id'       => $seenId,
        ];
    }

    /** Cuántos eventos hay después del último visto, con tope UNREAD_CAP. Null en demo. */
    public function unreadCount(User $user): ?int
    {
        $seenId = $this->seenId($user);

        if ($seenId === null || ! $user->perm('events', 'view')) {
            return null;
        }

        return Event::userAccessible($user)
            ->where('events.id', '>', $seenId)
            ->select('events.id')
            ->limit(self::UNREAD_CAP)
            ->get()
            ->count();
    }

    /**
     * «Marcar todo como leído» hasta $lastId. Solo avanza y no acepta ids que no
     * existen (el contador quedaría en cero para siempre).
     *
     * @return array{ok: bool, seen_id?: int, demo?: bool, error?: string}
     */
    public function markSeen(User $user, int $lastId): array
    {
        if ($lastId <= 0) {
            return ['ok' => false, 'error' => 'invalid_id'];
        }

        if (isDemoUser($user)) {
            return ['ok' => true, 'demo' => true];
        }

        $max = (int) Event::userAccessible($user)->max('events.id');
        $id = min($lastId, $max);

        $current = (int) $user->getSettings(self::SEEN_KEY);
        if ($id > $current) {
            $user->setSettings(self::SEEN_KEY, $id);
            $current = $id;
        }

        return ['ok' => true, 'seen_id' => $current];
    }
}
