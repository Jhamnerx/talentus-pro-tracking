<?php

namespace Tobuli\Entities;

use Formatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use ModalHelpers\AlertModalHelper;
use Tobuli\Traits\Searchable;
use Tobuli\Traits\SentCommandActor;

class Mininter extends AbstractEntity
{
    use Searchable;

    protected $table = 'mininter';

    protected array $searchable = [
        'placa',
    ];

    protected $fillable = [
        'device_id',
        'alarma',
        'altitud',
        'angulo',
        'distancia',
        'fechaHora',
        'timestamp',
        'horasMotor',
        'idMunicipalidad',
        'ignition',
        'imei',
        'latitud',
        'longitud',
        'motion',
        'placa',
        'totalDistancia',
        'totalHorasMotor',
        'ubigeo',
        'valid',
        'velocidad',
        'other',
    ];

    protected $casts = [
        'other' => 'array',
        'valid' => 'boolean',
        //'distancia' => 'float',
    ];

    public function device()
    {

        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
