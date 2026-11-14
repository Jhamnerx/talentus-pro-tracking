<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso `device_video` (video JT1078). NO se da a todos (addToAll): para no cambiar
 * de golpe lo que ve cada cuenta, solo lo reciben
 *  - los administradores (configuran las camaras),
 *  - los usuarios con algun equipo con camara (has_camera = 1, ya migrado desde
 *    video_channels) y sus managers, hacia arriba en la cadena (perm() del usuario
 *    exige tambien el del manager),
 *  - los planes de facturacion de esos usuarios (con plan, los permisos salen del plan).
 *
 * El resto se concede a mano desde la ficha del usuario.
 */
return new class extends Migration
{
    private array $value = ['view' => 1, 'edit' => 1, 'remove' => 0];

    public function up(): void
    {
        $owners = DB::table('user_device_pivot')
            ->join('devices', 'devices.id', '=', 'user_device_pivot.device_id')
            ->where('devices.has_camera', 1)
            ->distinct()
            ->pluck('user_device_pivot.user_id')
            ->all();

        $userIds = array_merge($owners, DB::table('users')->where('group_id', 1)->pluck('id')->all());

        // Managers de los dueños, hasta la raiz.
        $pending = $owners;
        while ($pending) {
            $managers = DB::table('users')->whereIn('id', $pending)->whereNotNull('manager_id')
                ->pluck('manager_id')->all();
            $pending = array_diff($managers, $userIds);
            $userIds = array_merge($userIds, $pending);
        }

        $users = DB::table('users')->whereIn('id', array_unique($userIds))->get(['id', 'billing_plan_id']);

        foreach ($users as $user) {
            DB::table('user_permissions')->updateOrInsert(
                ['user_id' => $user->id, 'name' => 'device_video'],
                $this->value
            );
        }

        $plans = $users->pluck('billing_plan_id')->filter()->unique();

        foreach ($plans as $planId) {
            DB::table('billing_plan_permissions')->updateOrInsert(
                ['plan_id' => $planId, 'name' => 'device_video'],
                $this->value
            );
        }
    }

    public function down(): void
    {
        DB::table('user_permissions')->where('name', 'device_video')->delete();
        DB::table('billing_plan_permissions')->where('name', 'device_video')->delete();
    }
};
