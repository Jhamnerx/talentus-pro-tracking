<?php

namespace Tobuli\Entities;

use Formatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use ModalHelpers\AlertModalHelper;
use Tobuli\Traits\Searchable;
use Tobuli\Traits\SentCommandActor;

class Sutran extends AbstractEntity
{
    use Searchable;

    protected $table = 'sutran';

    protected array $searchable = [
        'plate',
    ];

    protected $fillable = array(
        'device_id',
        'plate',
        'direction',
        'latitud',
        'longitud',
        'speed',
        'time_device',
        'other',
    );

    protected $casts = [
        'other' => 'array',
    ];

    public function device()
    {

        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
