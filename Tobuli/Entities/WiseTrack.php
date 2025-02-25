<?php

namespace Tobuli\Entities;

use Formatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use ModalHelpers\AlertModalHelper;
use Tobuli\Traits\Searchable;
use Tobuli\Traits\SentCommandActor;

class WiseTrack extends AbstractEntity
{
    use Searchable;

    protected $table = 'wisetrack';

    protected array $searchable = [
        'placa',
    ];

    protected $fillable = [
        //'id',
        'device_id',
        'patente',
        'fecha_hora',
        'latitud',
        'longitud',
        'direccion',
        'velocidad',
        'estado_registro',
        'estado_ignicion',
        'numero_evento',
        'odometro',
        'imei',
        'transportista',
    ];

    protected $casts = [
        'other' => 'array',
        'estado_registro' => 'boolean',
        'id' => 'integer',
        'estado_ignicion' => 'boolean',
    ];

    public function device()
    {

        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
