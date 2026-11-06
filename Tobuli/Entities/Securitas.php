<?php

namespace Tobuli\Entities;

/**
 * Evento pendiente de enviar a Securitas GlobalAVL (cola).
 *
 * Una fila por trama recibida de un dispositivo con `devices.securitas = 1`.
 * La escribe PositionsWriter::saveDataSecuritas() y la consume SendDataSecuritasJob.
 * `payload` es el evento GlobalAVL ya armado (SecuritasEventMapper::build()).
 */
class Securitas extends AbstractEntity
{
    protected $table = 'securitas';

    protected $fillable = [
        'device_id',
        'event_type',
        'event_time',
        'payload',
    ];

    protected $casts = [
        'id'         => 'integer',
        'device_id'  => 'integer',
        'event_type' => 'integer',
        'event_time' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo('Tobuli\Entities\Device', 'device_id', 'id');
    }

    /** Evento decodificado, listo para meter en `events[]`. */
    public function event(): array
    {
        return json_decode($this->payload, true) ?: [];
    }
}
