<?php

namespace Tobuli\Helpers\Teltonika;

use Formatter;

/**
 * Catálogo de los IO de evento de Teltonika y cómo leerlos de una posición.
 *
 * El parámetro `event` trae el AVL ID que disparó el registro, pero el VALOR
 * de ese IO no siempre llega como `io{ID}`: el TeltonikaProtocolDecoder de
 * nuestro fork de Traccar solo guarda `io{ID}` para los IO sin handler. Los
 * que tienen handler dejan otra cosa:
 *
 *   2         → `in2` (bool) + `di2` (1/0)
 *   9         → `adc1` (voltios)
 *   175       → alarm geofenceEnter / geofenceExit
 *   236       → alarm general
 *   246       → alarm tow          (solo si el valor > 0)
 *   247       → alarm accident     (solo si el valor > 0; se pierde el subtipo)
 *   249       → alarm jamming      (solo si el valor > 0)
 *   251       → alarm idle         (solo si el valor > 0)
 *   252       → alarm powerCut     (solo si el valor > 0)
 *   253       → alarm hardAcceleration (1) / hardBraking (2) / hardCornering (3)
 *   254, 255, 318 → `io254`, `io255`, `io318` (sin handler)
 *
 * Con valor 0 (fin del evento: jamming terminado, batería reconectada…) el
 * decoder no añade alarma, así que «evento sin su alarma» = fin del evento.
 * `alarm` puede venir acumulada con comas ("hardBraking,overspeed").
 *
 * Las posiciones antiguas (decoder anterior al fork) pueden traer el `io{ID}`
 * crudo; por eso cada lectura cae a `io{ID}` si no encuentra lo del fork.
 */
class TeltonikaEvents
{
    /** AVL IDs ofrecidos en el filtro del reporte, en este orden. */
    public const EVENTS = [9, 2, 253, 255, 247, 246, 252, 249, 318, 251];

    /**
     * Opciones para el multiselect: [id => etiqueta traducida].
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::EVENTS as $id) {
            $options[$id] = self::label($id);
        }

        return $options;
    }

    public static function label($event): string
    {
        $key = 'front.teltonika_event_' . $event;
        $text = trans($key);

        return $text === $key
            ? trans('front.teltonika_event_unknown', ['id' => $event])
            : $text;
    }

    /**
     * Texto del detalle del evento a partir de los parámetros de la posición.
     */
    public static function detail($event, array $params): string
    {
        $event = (int) $event;
        $alarms = self::alarms($params);

        switch ($event) {
            case 2:
                $value = $params['di2'] ?? $params['in2'] ?? $params['io2'] ?? null;

                return self::onOff($value, 'front.teltonika_door_open', 'front.teltonika_door_closed');

            case 9:
                $value = $params['adc1'] ?? null;

                if ($value === null && isset($params['io9'])) {
                    // Decoder antiguo: io9 crudo en mV.
                    $value = $params['io9'] / 1000;
                }

                return $value === null ? '' : round((float) $value, 2) . ' V';

            case 253:
                $type = self::greenDrivingType($params);

                if ($type === null) {
                    return '';
                }

                $alarm = [1 => 'hardAcceleration', 2 => 'hardBraking', 3 => 'hardCornering'][$type];

                return self::withIntensity(trans('front.alarm_' . $alarm), $params);

            case 255:
                return isset($params['io255'])
                    ? Formatter::speed()->human($params['io255'])
                    : '';

            case 318:
                if (! isset($params['io318'])) {
                    return '';
                }

                $level = (int) $params['io318'];

                return in_array($level, [0, 1, 2], true)
                    ? trans('front.teltonika_gnss_jamming_' . $level)
                    : (string) $params['io318'];

            case 175:
                if (in_array('geofenceEnter', $alarms, true)) {
                    return trans('front.alarm_geofenceEnter');
                }
                if (in_array('geofenceExit', $alarms, true)) {
                    return trans('front.alarm_geofenceExit');
                }

                return '';
        }

        // Eventos que el decoder convierte en alarma solo cuando el valor es > 0.
        $active = self::isActive($event, $params);

        if ($active !== null) {
            return trans('front.teltonika_' . self::START_END_ALARMS[$event] . ($active ? '_start' : '_end'));
        }

        // Evento fuera del catálogo: valor crudo si existe.
        return (string) ($params['io' . $event] ?? '');
    }

    /**
     * Alarma Traccar que el decoder pone para cada IO de inicio/fin.
     */
    public const START_END_ALARMS = [
        236 => 'general',
        246 => 'tow',
        247 => 'accident',
        249 => 'jamming',
        251 => 'idle',
        252 => 'powerCut',
    ];

    /**
     * ¿El IO de inicio/fin está activo en esta posición?
     *
     * true = inicio (alarma presente o io{ID} > 0), false = fin (el decoder
     * no añade alarma con valor 0), null = el evento no es de este tipo.
     */
    public static function isActive(int $event, array $params): ?bool
    {
        if (! isset(self::START_END_ALARMS[$event])) {
            return null;
        }

        if (in_array(self::START_END_ALARMS[$event], self::alarms($params), true)) {
            return true;
        }

        $raw = $params['io' . $event] ?? null;

        return $raw !== null && $raw !== '' && (int) $raw > 0;
    }

    /**
     * Tipo de green driving: 1 aceleración, 2 frenado, 3 giro; null si no hay.
     */
    public static function greenDrivingType(array $params): ?int
    {
        $alarms = self::alarms($params);

        foreach (['hardAcceleration' => 1, 'hardBraking' => 2, 'hardCornering' => 3] as $alarm => $type) {
            if (in_array($alarm, $alarms, true)) {
                return $type;
            }
        }

        $raw = isset($params['io253']) ? (int) $params['io253'] : 0;

        return in_array($raw, [1, 2, 3], true) ? $raw : null;
    }

    /**
     * @return string[]
     */
    public static function alarms(array $params): array
    {
        if (empty($params['alarm'])) {
            return [];
        }

        return array_map('trim', explode(',', (string) $params['alarm']));
    }

    /**
     * io254 = intensidad del green driving en g × 100.
     */
    protected static function withIntensity(string $text, array $params): string
    {
        if (! isset($params['io254']) || ! is_numeric($params['io254'])) {
            return $text;
        }

        return $text . ' (' . round($params['io254'] / 100, 2) . ' g)';
    }

    protected static function onOff($value, string $onKey, string $offKey): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $on = in_array(strtolower((string) $value), ['1', 'true'], true);

        return trans($on ? $onKey : $offKey);
    }
}
