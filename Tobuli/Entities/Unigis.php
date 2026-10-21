<?php

namespace Tobuli\Entities;

use Tobuli\Traits\Searchable;

/**
 * Posicion pendiente de enviar a UNIGIS (cola).
 *
 * Una fila por trama recibida de un dispositivo con `devices.unigis = 1`.
 * La escribe PositionsWriter::saveDataUnigis() y la consume SendDataUnigisJob.
 */
class Unigis extends AbstractEntity
{
    use Searchable;

    protected $table = 'unigis';

    protected array $searchable = [
        'dominio',
    ];

    protected $fillable = [
        'device_id',
        'dominio',
        'codigo',
        'latitud',
        'longitud',
        'altitud',
        'velocidad',
        'rumbo',
        'fecha_hora_evento',
        'fecha_hora_recepcion',
        'crudo',
    ];

    protected $casts = [
        'id'                   => 'integer',
        'device_id'            => 'integer',
        'latitud'              => 'float',
        'longitud'             => 'float',
        'altitud'              => 'integer',
        'velocidad'            => 'integer',
        'rumbo'                => 'integer',
        'fecha_hora_evento'    => 'datetime',
        'fecha_hora_recepcion' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
