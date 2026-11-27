<?php

namespace Tobuli\Helpers\Alerts\Check;

use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\DeviceSensor;
use Tobuli\Entities\Event;

/**
 * Umbral sobre el valor de un sensor.
 *
 * Un solo tipo de alerta para cualquier magnitud numerica: temperatura,
 * voltaje, carga, RPM, nivel... En vez de un tipo por magnitud, el usuario
 * elige QUE tipo de sensor vigilar, el lado del umbral y cuanto tiene que
 * aguantar la condicion antes de avisar.
 *
 * Se elige por TIPO de sensor y no por sensor concreto porque una alerta cubre
 * muchos vehiculos y cada uno tiene sus propios ids de sensor: por id no
 * serviria mas que para uno.
 *
 * La duracion minima es lo que separa un aviso util de una lluvia de avisos:
 * una sonda de temperatura cruza el umbral decenas de veces al abrir la puerta.
 * Mientras la condicion se mantiene solo se avisa UNA vez; no se vuelve a
 * avisar hasta que el valor se normaliza.
 */
class SensorValueAlertCheck extends AlertCheck
{
    public const OPERATOR_ABOVE = 'above';
    public const OPERATOR_BELOW = 'below';

    /** Tipos de sensor que tiene sentido vigilar por umbral. */
    public const TIPOS = [
        'temperature',
        'fuel_tank',
        'battery',
        'battery_external',
        'load',
        'tachometer',
        'speed_ecm',
        'counter',
        'numerical',
    ];

    public static function operadores(): array
    {
        return [
            self::OPERATOR_ABOVE => trans('front.sensor_value_above'),
            self::OPERATOR_BELOW => trans('front.sensor_value_below'),
        ];
    }

    public static function tipos(): array
    {
        $opciones = [];

        foreach (self::TIPOS as $tipo) {
            $clase = "Tobuli\\Sensors\\Types\\" . str_replace(' ', '', ucwords(str_replace('_', ' ', $tipo)));

            $opciones[$tipo] = class_exists($clase) ? $clase::getTypeTitle() : $tipo;
        }

        return $opciones;
    }

    public function checkEvents($position, $prevPosition)
    {
        if (! $position) {
            return null;
        }

        $tipo = $this->alert->sensor_type;

        if (! $tipo || ! in_array($tipo, self::TIPOS, true)) {
            return null;
        }

        if (! $this->checkAlertPosition($position)) {
            return null;
        }

        // Devuelve null cuando el equipo no tiene sensores cargados
        $sensores = $this->device->getSensorsByType([$tipo]) ?: [];

        $eventos = [];

        foreach ($sensores as $sensor) {
            $evento = $this->checkSensor($sensor, $position);

            if ($evento) {
                $eventos[] = $evento;
            }
        }

        return $eventos ?: null;
    }

    private function checkSensor(DeviceSensor $sensor, $position): ?Event
    {
        $valor = $sensor->getValuePosition($position);

        // Sin lectura no se toca el estado: un hueco de datos no es ni cruce
        // ni vuelta a la normalidad.
        if ($valor === null || ! is_numeric($valor)) {
            return null;
        }

        $valor = (float) $valor;
        $umbral = (float) $this->alert->sensor_value;

        $cruza = $this->alert->sensor_operator === self::OPERATOR_BELOW
            ? $valor < $umbral
            : $valor > $umbral;

        $claveInicio = $this->cacheKey($sensor, 'start');
        $claveAviso  = $this->cacheKey($sensor, 'fired');

        if (! $cruza) {
            // Vuelve a la normalidad: se puede volver a avisar
            Cache::forget($claveInicio);
            Cache::forget($claveAviso);

            return null;
        }

        if (Cache::get($claveAviso)) {
            return null;
        }

        $duracion = (int) $this->alert->sensor_duration * 60;

        if ($duracion > 0) {
            $inicio = Cache::get($claveInicio);

            if (! $inicio) {
                Cache::put($claveInicio, time(), now()->addHours(24));

                return null;
            }

            if ((time() - $inicio) < $duracion) {
                return null;
            }

            Cache::forget($claveInicio);
        }

        Cache::put($claveAviso, true, now()->addHours(24));

        return $this->buildEvent($sensor, $valor, $umbral);
    }

    private function buildEvent(DeviceSensor $sensor, float $valor, float $umbral): Event
    {
        $event = $this->getEvent();

        $event->type = Event::TYPE_SENSOR_VALUE;
        $event->message = '';

        $event->setAdditional('sensor_id', $sensor->id);
        $event->setAdditional('sensor_name', $sensor->formatName());
        $event->setAdditional('sensor_value', $valor);
        $event->setAdditional('sensor_unit', $sensor->unit_of_measurement);
        $event->setAdditional('sensor_limit', $umbral);
        $event->setAdditional('sensor_operator', $this->alert->sensor_operator);

        $this->silent($event);

        return $event;
    }

    private function cacheKey(DeviceSensor $sensor, string $sufijo): string
    {
        return 'sensor_value_' . $sufijo . '_' . $this->alert->id . '_' . $this->device->id . '_' . $sensor->id;
    }
}
