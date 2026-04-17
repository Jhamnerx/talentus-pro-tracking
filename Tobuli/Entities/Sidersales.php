<?php

namespace Tobuli\Entities;

use Tobuli\Traits\Searchable;

class Sidersales extends AbstractEntity
{
    use Searchable;

    protected $table = 'sidersales';

    protected array $searchable = [
        'placa',
    ];

    protected $fillable = [
        'device_id',
        'placa',
        'latitud',
        'longitud',
        'velocidadKPH',
        'fechaHoraGPS',
        'rucTransportista',
        'ProveedorGPS',
    ];

    public function device()
    {
        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }
}
