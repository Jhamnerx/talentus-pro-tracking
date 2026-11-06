<?php

namespace Tobuli\Services\Securitas;

use Tobuli\Entities\Device;
use Tobuli\Entities\TraccarPosition;

/**
 * Arma un evento GlobalAVL (Inbound Event Messaging v2.0) a partir de una posicion.
 *
 * Acordado con Securitas (plan securitas.md §2.1, 6-nov-2026):
 *  - version "2.0"; `valid` es boolean; `status.ignition` 1 = ON, 0 = OFF
 *    (se envia solo en status, no como entrada digital 13).
 *  - No se envian eventos de area (1/2): las areas las calcula GlobalAVL.
 *  - Datos esenciales; cada campo opcional solo si la trama lo trae.
 */
class SecuritasEventMapper
{
    public const VERSION = '2.0';

    // Tipos de evento GlobalAVL
    public const EV_UNDEFINED         = 0;
    public const EV_IGNITION_ON       = 13;
    public const EV_IGNITION_OFF      = 23;
    public const EV_MAIN_POWER_LOST   = 59;
    public const EV_MAIN_POWER_BACK   = 60;
    public const EV_HARSH_TURN        = 196;
    public const EV_HARSH_BRAKING     = 512;
    public const EV_HARSH_ACCEL       = 513;

    // Tipos de entrada
    public const ANALOG_MAIN_BATTERY_MV   = 3;
    public const ANALOG_BACKUP_BATTERY_MV = 4;
    public const CANBUS_TOTAL_MILEAGE_M   = 15;
    public const SERIAL_DRIVER_ID         = 2;

    // Tipos de info
    public const INFO_BATTERY  = 1;
    public const INFO_COVERAGE = 2;

    /** Catalogo de eventos que puede emitir este mapper (para logs / comando de prueba). */
    public static function catalog(): array
    {
        return [
            self::EV_UNDEFINED       => 'Posicion periodica',
            self::EV_IGNITION_ON     => 'Ignition ON',
            self::EV_IGNITION_OFF    => 'Ignition OFF',
            self::EV_MAIN_POWER_LOST => 'Main Power Lost',
            self::EV_MAIN_POWER_BACK => 'Main Power Restored',
            self::EV_HARSH_TURN      => 'Harsh Turn',
            self::EV_HARSH_BRAKING   => 'Harsh braking',
            self::EV_HARSH_ACCEL     => 'Harsh acceleration',
        ];
    }

    /** Evento GlobalAVL listo para `events[]`. */
    public static function build(Device $device, TraccarPosition $position): array
    {
        $event = [
            'created'  => self::isoUtc($position->device_time),
            'device'   => self::deviceId($device),
            'type'     => self::eventType($position),
            'location' => [
                'altitude'   => round((float) $position->altitude, 3),
                'course'     => (float) (((int) round((float) $position->course)) % 360),
                'latitude'   => (float) $position->latitude,
                'longitude'  => (float) $position->longitude,
                'precision'  => 0,
                'satellites' => (int) $position->getParameter('sat', 0),
                'speed'      => round((float) $position->speed, 1),
                'type'       => 0,
                'valid'      => (bool) $position->valid,
            ],
        ];

        $status = [];
        $ignition = $position->getParameter('ignition');
        if ($ignition !== null && $ignition !== '') {
            $status['ignition'] = self::isTrue($ignition) ? 1 : 0;
        }
        $batteryLevel = self::number($position->getParameter('batterylevel'));
        if ($batteryLevel !== null) {
            $status['battery'] = (int) round(max(0, min(100, $batteryLevel)));
        }
        if ($status) {
            $event['status'] = $status;
        }

        $infos = [];
        $rssi = self::number($position->getParameter('rssi'));
        if ($rssi !== null) {
            // Teltonika io21: 0-5 -> %
            $infos[] = ['type' => self::INFO_COVERAGE, 'value' => (int) round(max(0, min(5, $rssi)) * 20)];
        }
        if ($infos) {
            $event['infos'] = $infos;
        }

        $inputs = [];

        $analog = [];
        $power = self::number($position->getParameter('power'));
        if ($power !== null) {
            $analog[] = ['index' => 0, 'type' => self::ANALOG_MAIN_BATTERY_MV, 'value' => self::voltsToMv($power)];
        }
        $battery = self::number($position->getParameter('battery'));
        if ($battery !== null) {
            $analog[] = ['index' => 0, 'type' => self::ANALOG_BACKUP_BATTERY_MV, 'value' => self::voltsToMv($battery)];
        }
        if ($analog) {
            $inputs['analog'] = $analog;
        }

        $totalDistance = self::number($position->getParameter('totaldistance'));
        if ($totalDistance !== null && $totalDistance > 0) {
            $inputs['canbus'] = [
                ['index' => 0, 'type' => self::CANBUS_TOTAL_MILEAGE_M, 'value' => (string) (int) round($totalDistance)],
            ];
        }

        $driver = trim((string) $position->getParameter('driverUniqueId', ''));
        if ($driver !== '' && $driver !== '0') {
            $inputs['serial'] = [
                ['index' => 0, 'type' => self::SERIAL_DRIVER_ID, 'value' => $driver],
            ];
        }

        if ($inputs) {
            $event['inputs'] = $inputs;
        }

        return $event;
    }

    /**
     * Tipo de evento GlobalAVL segun el evento Teltonika de la trama.
     *
     * IDs AVL: 1 DIN1 (cableado a ignicion en estas unidades) y 239 ignicion,
     * 252 unplug (1 = sin alimentacion externa), 253 green driving
     * (io253: 1 aceleracion, 2 frenada, 3 curva). Mismo criterio que UNIGIS.
     */
    public static function eventType(TraccarPosition $position): int
    {
        switch ((int) $position->getParameter('event', 0)) {
            case 1:
            case 239:
                return self::isTrue($position->getParameter('ignition'))
                    ? self::EV_IGNITION_ON
                    : self::EV_IGNITION_OFF;

            case 252:
                return ((int) $position->getParameter('io252', 0)) === 1
                    ? self::EV_MAIN_POWER_LOST
                    : self::EV_MAIN_POWER_BACK;

            case 253:
                switch ((int) $position->getParameter('io253', 0)) {
                    case 1:
                        return self::EV_HARSH_ACCEL;
                    case 2:
                        return self::EV_HARSH_BRAKING;
                    case 3:
                        return self::EV_HARSH_TURN;
                }
                return self::EV_UNDEFINED;

            default:
                return self::EV_UNDEFINED;
        }
    }

    /** Identificador del equipo en GlobalAVL: el IMEI. */
    public static function deviceId(Device $device): string
    {
        return trim((string) $device->imei);
    }

    /** ISO 8601 UTC con milisegundos: 2025-04-23T14:18:03.000Z */
    public static function isoUtc($time): string
    {
        $dt = new \DateTime($time ?: 'now', new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function voltsToMv(float $volts): float
    {
        return (float) (int) round($volts * 1000);
    }

    private static function number($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function isTrue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true'], true);
    }
}
