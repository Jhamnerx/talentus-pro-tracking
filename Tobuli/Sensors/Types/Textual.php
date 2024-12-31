<?php


namespace Tobuli\Sensors\Types;

use Tobuli\Sensors\Sensor;

class Textual extends Sensor
{
    public static function getType(): string
    {
        return 'textual';
    }

    public static function getTypeTitle(): string
    {
        return trans('front.textual');
    }

    protected function getResult($value)
    {
        return $value;
    }

    public function getValueFormatted($value)
    {
        return $this->getMappingValue($value);
    }

    public static function getInputs() : array
    {
        return [
            'default' => [
                'tag_name' => true,
                'setflag' => true,
                //'bin' => true,
                'bitcut' => true,
                'mapping' => true,
                'ascii' => true,
                'add_to_history' => true,
            ]
        ];
    }
}