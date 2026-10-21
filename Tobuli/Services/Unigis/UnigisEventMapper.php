<?php

namespace Tobuli\Services\Unigis;

use Tobuli\Entities\TraccarPosition;

/**
 * Traduce el evento Teltonika de una posicion al codigo acordado con UNIGIS.
 *
 * Tabla acordada (plan unigis.md §2). Todo lo que no este aqui se envia como POS.
 * UNIGIS descarta (retorno negativo) cualquier codigo que no tenga mapeado,
 * asi que este catalogo debe coincidir con el que se les entrego.
 */
class UnigisEventMapper
{
    public const POS         = 'POS';
    public const IGN_ON      = 'IGN_ON';
    public const IGN_OFF     = 'IGN_OFF';
    public const MOV_ON      = 'MOV_ON';
    public const MOV_OFF     = 'MOV_OFF';
    public const TRIP_START  = 'TRIP_START';
    public const TRIP_END    = 'TRIP_END';
    public const HARSH_ACC   = 'HARSH_ACC';
    public const HARSH_BRAKE = 'HARSH_BRAKE';
    public const HARSH_TURN  = 'HARSH_TURN';

    /** Catalogo codigo => descripcion, tal como se informo a UNIGIS. */
    public static function catalog(): array
    {
        return [
            self::POS         => 'Posicion periodica (por tiempo, distancia o cambio de rumbo)',
            self::IGN_ON      => 'Encendido de motor',
            self::IGN_OFF     => 'Apagado de motor',
            self::MOV_ON      => 'Inicio de movimiento',
            self::MOV_OFF     => 'Fin de movimiento (vehiculo detenido)',
            self::TRIP_START  => 'Inicio de viaje',
            self::TRIP_END    => 'Fin de viaje',
            self::HARSH_ACC   => 'Aceleracion brusca',
            self::HARSH_BRAKE => 'Frenada brusca',
            self::HARSH_TURN  => 'Curva brusca',
        ];
    }

    /**
     * Codigo UNIGIS para una posicion.
     *
     * IDs AVL Teltonika: 0 periodico, 1 DIN1 (en estas unidades cableado a
     * ignicion), 239 ignicion, 240 movimiento, 303 movimiento instantaneo,
     * 250 viaje (io250: 1 inicio / 0 fin), 253 green driving (io253: 1 acel,
     * 2 frenada, 3 curva).
     */
    public static function codeFor(TraccarPosition $position): string
    {
        $event = (int) $position->getParameter('event', 0);

        switch ($event) {
            case 1:
            case 239:
                return self::isTrue($position->getParameter('ignition')) ? self::IGN_ON : self::IGN_OFF;

            case 240:
            case 303:
                return self::isTrue($position->getParameter('motion')) ? self::MOV_ON : self::MOV_OFF;

            case 250:
                return ((int) $position->getParameter('io250', 0)) === 1 ? self::TRIP_START : self::TRIP_END;

            case 253:
                switch ((int) $position->getParameter('io253', 0)) {
                    case 1:
                        return self::HARSH_ACC;
                    case 2:
                        return self::HARSH_BRAKE;
                    case 3:
                        return self::HARSH_TURN;
                }
                return self::POS;

            default:
                return self::POS;
        }
    }

    /**
     * Placa en el formato que exige UNIGIS: mayusculas, sin espacios ni guiones.
     */
    public static function normalizePlate(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $plate));
    }

    private static function isTrue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true'], true);
    }
}
