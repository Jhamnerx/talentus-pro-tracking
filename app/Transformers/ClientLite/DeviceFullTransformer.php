<?php

namespace App\Transformers\ClientLite;

use Tobuli\Entities\Device;
use Formatter;

class DeviceFullTransformer extends DeviceTransformer  {

    protected $defaultIncludes = [
        'status',
        //'position',
        'coordinates',
        'sensors',
        'services',
        'driver',
        'stats'
    ];

    protected static function requireLoads()
    {
        return ['traccar', 'sensors', 'services', 'driver'];
    }

    public function transform(Device $entity)
    {
        return [
            'id'       => (int)$entity->id,
            'group_id' => $entity->pivot ? (int)$entity->pivot->group_id : 0,
            'name'     => $entity->name,
            'active'   => $entity->pivot ? (bool)$entity->pivot->active : null,
            'time'     => $this->serializeDateTime($entity->time),
            'speed'    => $this->serializeFormatter(Formatter::speed(), $entity->getSpeed()),
            'engine_status' => $entity->getEngineStatus(),
        ];
    }
}