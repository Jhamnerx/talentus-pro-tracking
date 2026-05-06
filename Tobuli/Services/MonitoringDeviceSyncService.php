<?php

namespace Tobuli\Services;

use Illuminate\Support\Facades\DB;
use Tobuli\Entities\User;

class MonitoringDeviceSyncService
{
    /**
     * Copia los registros de user_device_pivot del manager al usuario Monitoreo.
     * Se llama al crear/actualizar un usuario Monitoreo o cuando el manager actualiza sus dispositivos.
     */
    public function syncFromManager(User $monitoringUser): void
    {
        if (! $monitoringUser->isMonitoring() || ! $monitoringUser->manager_id) {
            return;
        }

        $managerDevices = DB::table('user_device_pivot')
            ->where('user_id', $monitoringUser->manager_id)
            ->get(['device_id', 'group_id', 'active']);

        DB::table('user_device_pivot')
            ->where('user_id', $monitoringUser->id)
            ->delete();

        if ($managerDevices->isEmpty()) {
            return;
        }

        $rows = $managerDevices->map(function ($pivot) use ($monitoringUser) {
            return [
                'user_id'   => $monitoringUser->id,
                'device_id' => $pivot->device_id,
                'group_id'  => $pivot->group_id,
                'active'    => $pivot->active,
            ];
        })->all();

        DB::table('user_device_pivot')->insert($rows);
    }

    /**
     * Sincroniza todos los usuarios Monitoreo que dependen de un manager dado.
     * Se llama después de que el manager actualiza sus dispositivos.
     */
    public function syncManagerMonitoringUsers(User $manager): void
    {
        $monitoringUsers = User::where('manager_id', $manager->id)
            ->where('group_id', 7)
            ->get();

        foreach ($monitoringUsers as $monitoringUser) {
            $this->syncFromManager($monitoringUser);
        }
    }
}
