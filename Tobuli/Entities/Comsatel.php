<?php

namespace Tobuli\Entities;

use Formatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use ModalHelpers\AlertModalHelper;
use Tobuli\Traits\Searchable;
use Tobuli\Traits\SentCommandActor;

class Comsatel extends AbstractEntity
{
    use Searchable;

    protected $table = 'comsatel';

    protected array $searchable = [
        'placa',
    ];

    protected $fillable = [
        //'id',
        'device_id',
        'placa',
        'velocidad',
        'satelites',
        'rumbo',
        'altitud',
        'latitud',
        'longitud',
        'timestamp',
        'evento',
        'ignition',
        'odometro',
        'horometro',
        'batery_level',
        'valid',
        'fuente',
        'other',
    ];

    protected $casts = [
        'other' => 'array',
        'valid' => 'boolean',
        'id' => 'integer',
        'altitud' => 'decimal:2',
        'ignition' => 'boolean',
    ];

    public function device()
    {

        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
