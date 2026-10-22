<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El permiso `webservice.unigis` (config/permissions.php) no existia como fila en
 * user_permissions, y User::perm() devuelve false sin fila: el checkbox UNIGIS
 * de la ficha del objeto no aparecia ni para el admin. Se concede a los
 * administradores (group_id 1) que ya tienen `webservice.wisetrack`, igual que
 * se hizo con los demas webservices.
 */
return new class extends Migration
{
    private const PERM = 'webservice.unigis';

    public function up(): void
    {
        if (!Schema::hasTable('user_permissions')) {
            return;
        }

        $admins = DB::table('users')->where('group_id', 1)->pluck('id');
        $now = now()->format('Y-m-d H:i:s');
        $hasTimestamps = Schema::hasColumn('user_permissions', 'created_at');

        foreach ($admins as $userId) {
            $exists = DB::table('user_permissions')
                ->where('user_id', $userId)
                ->where('name', self::PERM)
                ->exists();

            if ($exists) {
                continue;
            }

            $row = ['user_id' => $userId, 'name' => self::PERM, 'view' => 1, 'edit' => 1, 'remove' => 1];
            if ($hasTimestamps) {
                $row += ['created_at' => $now, 'updated_at' => $now];
            }

            DB::table('user_permissions')->insert($row);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_permissions')) {
            DB::table('user_permissions')->where('name', self::PERM)->delete();
        }
    }
};
