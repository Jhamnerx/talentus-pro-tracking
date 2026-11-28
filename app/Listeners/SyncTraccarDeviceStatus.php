<?php

namespace App\Listeners;

use App\Events\Device\DeviceEventInterface;
use App\Jobs\SyncTraccarDeviceModel;

/**
 * Al habilitar o deshabilitar un equipo (devices.active) lleva el cambio a tc_devices.disabled de Traccar.
 * Escucha los eventos y no el saved() del Device porque la acción masiva del admin
 * (ObjectsController::setActiveMulti) actualiza con un UPDATE directo y solo dispara los eventos.
 */
class SyncTraccarDeviceStatus
{
    public function handle(DeviceEventInterface $event)
    {
        dispatch(new SyncTraccarDeviceModel($event->device));
    }
}
