<?php

namespace Tobuli\Entities;

use Tobuli\Traits\Searchable;

class WebservicesLogs extends AbstractEntity
{
    use Searchable;

    protected array $searchable = [
        'logs',
    ];

    protected $table = 'logs';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at'

    ];

    protected $casts = [
        'fecha_hora_posicion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'

    ];
}
