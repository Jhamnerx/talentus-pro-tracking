<?php

namespace Tobuli\Entities;

use App\Enums\WebServices;
use Tobuli\Traits\Searchable;

class WebservicesLogs extends AbstractEntity
{
    use Searchable;

    protected $table = 'logs';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at'

    ];

    protected $filterables = [
        'service_name',
        'date',
        'fecha_hora_posicion',
        'plate_number',
        'imei',
        'status',

    ];

    protected $searchable = [
        'service_name',
        'date',
        'fecha_hora_posicion',
        'plate_number',
        'imei',
        'status',
    ];

    protected $casts = [
        'fecha_hora_posicion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // public function getServiceNameAttribute($value)
    // {
    //     return WebServices::tryFrom($value);
    // }
}
