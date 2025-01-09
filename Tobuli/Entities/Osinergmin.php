<?php

namespace Tobuli\Entities;

use Formatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use ModalHelpers\AlertModalHelper;
use Tobuli\Traits\Searchable;
use Tobuli\Traits\SentCommandActor;

class Osinergmin extends AbstractEntity
{
    use Searchable;

    protected $table = 'osinergmin';

    protected array $searchable = [
        'plate',
    ];

    protected $fillable = array(
        'device_id',
        'plate',
        'timestamp',
        'direction',
        'latitud',
        'longitud',
        'altitude',
        'speed',
        'time_device',
        'other',
    );

    protected $casts = [
        'other' => 'array',
    ];

    public function device()
    {

        return $this->belongsTo('Tobuli\Entities\Device');
    }
}
