<?php


namespace Tobuli\Sensors\Types;

use Tobuli\Sensors\Tag;
use Tobuli\Entities\TraccarPosition as Position;

class EngineHours extends Numerical
{
    /**
     * @var Tag|null
     */
    protected $valueTag = null;

    protected static $defaultShowType = 'virtual';

    public static function getType(): string
    {
        return 'engine_hours';
    }

    public static function getTypeTitle(): string
    {
        return trans('front.engine_hours');
    }

    public static function isUnique() : bool
    {
        return true;
    }

    public static function isPositionValue() : bool
    {
        return true;
    }

    public static function getShowTypes()
    {
        return [
            'connected' => trans('front.connected'),
            'virtual' => trans('front.virtual_engine_hours'),
            'logical' => trans('front.logical'),
        ];
    }

    public static function getInputs() : array
    {
        return [
            'connected' => [
                'tag_name' => true,
                'skip_empty' => true,
                'formula' => true,
                'unit' => true,
                'add_to_history' => true,
                'add_to_graph' => true,
            ],
            'logical' => [
                'tag_name' => true,
                'logic_on' => true,
                'logic_off' => true,
                'value' => true,
                'add_to_history' => true,
                'add_to_graph' => true,
            ],
            'virtual' => [
                'value' => true,
                'add_to_history' => true,
                'add_to_graph' => true,
            ]
        ];
    }

    public function getDataValue($data)
    {
        if (!$this->valueTag)
            return $this->getParameterValue($data);

        $value = $this->valueTag->parse($data);

        if (is_null($value))
            return null;

        return round($value / 3600, 4);
    }

    public function setShowType($showType)
    {
        parent::setShowType($showType);

        switch ($showType) {
            case 'logical':
            case 'virtual':
                $this->setUnit(trans('front.hour_short'));
                $this->valueTag = new Tag(Position::VIRTUAL_ENGINE_HOURS_KEY);
                break;
        }
    }
}