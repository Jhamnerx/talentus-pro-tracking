<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User::perm() devuelve false si no hay fila en user_permissions, asi que el
 * checkbox Securitas de la ficha del objeto no apareceria ni para el admin.
 * Se concede `webservice.securitas` a los administradores (group_id 1), igual
 * que se hizo con UNIGIS.
 */
return new class extends Migration
{
    private const PERM = 'webservice.securitas';

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
