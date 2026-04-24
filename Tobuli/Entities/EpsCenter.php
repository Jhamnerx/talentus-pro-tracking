<?php

namespace Tobuli\Entities;

class EpsCenter extends AbstractEntity
{
    protected $table = 'epscenter';

    protected $fillable = [
        'device_id',
        'id_empresa',
        'id_transporte',
        'telefono',
        'latitud',
        'longitud',
        'fecha',
        'hora',
        'velocidad',
        'bateria',
        'mensaje',
    ];

    public function device()
    {
        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
