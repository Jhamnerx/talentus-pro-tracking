<?php


namespace Tobuli\Helpers;


class SetFlag
{
    const TYPE_CROP = 1;
    const TYPE_VALUE = 2;


    static public function buildCrop($start, $count)
    {
        return "%SETFLAG[$start,$count]%";
    }

    static public function buildCropValue($start, $count, $value)
    {
        return "%SETFLAG[$start,$count,$value]%";
    }

    static public function singleCrop($string)
    {
        preg_match(self::typePattern(self::TYPE_CROP), $string, $match);

        return self::typeMatches(self::TYPE_CROP, $match);
    }

    static public function singleCropValue($string)
    {
        preg_match(self::typePattern(self::TYPE_VALUE), $string, $match);

        return self::typeMatches(self::TYPE_VALUE, $match);
    }

    static public function multiCrop($string)
    {
        preg_match_all(self::typePattern(self::TYPE_CROP), $string, $matches);

        $groups = [];

        foreach ($matches as $match) {
            foreach ($match as $i => $value) {
                $groups[$i][] = $value;
            }
        }

        $result = [];

        foreach ($groups as $match) {
            $result[] = self::typeMatches(self::TYPE_CROP, $match);
        }

        return $result;
    }

    static protected function typeMatches($type, $matches)
    {
        switch ($type) {
            case self::TYPE_CROP:
                if (isset($matches[1]) && isset($matches[2])) {
                    return [
                        'place' => $matches[0],
                        'start' => $matches[1],
                        'count' => $matches[2],
                    ];
                }
                break;
            case self::TYPE_VALUE:
                if (isset($matches[1]) && isset($matches[2]) && isset($matches[3])) {
                    return [
                        'place' => $matches[0],
                        'start' => $matches[1],
                        'count' => $matches[2],
                        'value' => $matches[3],
                    ];
                }
                break;
            default:
                throw new \InvalidArgumentException("Undefined setflag type '$type'");
        }

        return null;
    }

    static protected function typePattern($type)
    {
        switch ($type) {
            case self::TYPE_CROP:
                return '/\%SETFLAG\[([0-9]+)\,([0-9]+)\]\%/';
            case self::TYPE_VALUE:
                return '/\%SETFLAG\[([0-9]+)\,([0-9]+)\,([\s\S]+)\]\%/';
            default:
                throw new \InvalidArgumentException("Undefined setflag type '$type'");
        }
    }
}