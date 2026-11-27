<?php

namespace Tobuli\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\SetFlag;

class ConditionService
{
    const TYPE_EQUAL = 1;
    const TYPE_MORE = 2;
    const TYPE_LESS = 3;
    const TYPE_NOT_EQUAL = 4;
    const TYPE_EMPTY = 5;

    /**
     * "Incluye": el valor es una LISTA separada por comas y se busca uno de sus
     * elementos.
     *
     * Existe por el parámetro `alarm` de Traccar: Position::addAlarm() acumula
     * separando por comas, así que un equipo puede reportar
     * "hardBraking,overspeed" de una vez y una condición de igualdad no lo
     * detectaría.
     *
     * Compara elemento a elemento, NO como subcadena: "geofence" no debe dar
     * positivo sobre "geofenceEnter", que son dos alarmas distintas de Traccar.
     */
    const TYPE_CONTAINS = 6;

    /**
     * @return array
     */
    public static function getList()
    {
        return [
            self::TYPE_EQUAL     => trans('front.event_type_1'),
            self::TYPE_MORE      => trans('front.event_type_2'),
            self::TYPE_LESS      => trans('front.event_type_3'),
            self::TYPE_NOT_EQUAL => trans('front.event_type_4'),
            self::TYPE_EMPTY     => trans('front.event_type_5'),
            self::TYPE_CONTAINS  => trans('front.event_type_6'),
        ];
    }

    /**
     * @param $type
     * @param $value
     * @param $equal
     * @return bool
     */
    public static function check($type, $value, $equal)
    {
        switch ($type) {
            case self::TYPE_EQUAL:
                return $value == $equal;

            case self::TYPE_MORE:
                $number = parseNumber($value);
                return is_numeric($number) && $number > $equal;

            case self::TYPE_LESS:
                $number = parseNumber($value);
                return is_numeric($number) && $number < $equal;

            case self::TYPE_NOT_EQUAL:
                return $value != $equal;

            case self::TYPE_EMPTY:
                return !is_numeric($value) && empty($value);

            case self::TYPE_CONTAINS:
                return self::listaIncluye($value, $equal);
        }

        return false;
    }

    /**
     * ¿La lista separada por comas de $value incluye exactamente $equal?
     *
     * Se comparan elementos completos y sin espacios sobrantes; así
     * "hardBraking,overspeed" incluye "hardBraking" pero "geofenceEnter" NO
     * incluye "geofence".
     */
    private static function listaIncluye($value, $equal): bool
    {
        if ($value === null || $equal === null || $equal === '') {
            return false;
        }

        foreach (explode(',', (string) $value) as $item) {
            if (trim($item) === trim((string) $equal)) {
                return true;
            }
        }

        return false;
    }

    public static function validate($type, $equal)
    {
        switch ($type) {
            case self::TYPE_EQUAL:
            case self::TYPE_NOT_EQUAL:
            case self::TYPE_CONTAINS:
                return is_numeric($equal) || !empty($equal);
            case self::TYPE_MORE:
            case self::TYPE_LESS:
                if ($setflag = SetFlag::singleCropValue($equal)) {
                    $equal = $setflag->getValue();
                }
                return is_numeric($equal);
            case self::TYPE_EMPTY:
                return true;
        }

        return false;
    }
}
