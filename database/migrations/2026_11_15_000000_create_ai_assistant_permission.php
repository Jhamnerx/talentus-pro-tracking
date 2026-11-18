<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso "ai_assistant" (AirBot). A diferencia de addToAll(), se crea
 * DESACTIVADO para todos los usuarios y planes: el administrador lo activa.
 */
class CreateAiAssistantPermission extends Migration
{
    const KEY_NAME = 'ai_assistant';

    public function up()
    {
        DB::insert(
            'INSERT INTO user_permissions (user_id, name, view, edit, remove) '
            . 'SELECT id, ?, 0, 0, 0 FROM users WHERE billing_plan_id IS NULL '
            . 'AND id NOT IN (SELECT user_id FROM user_permissions WHERE name = ?)',
            [self::KEY_NAME, self::KEY_NAME]
        );

        DB::insert(
            'INSERT INTO billing_plan_permissions (plan_id, name, view, edit, remove) '
            . 'SELECT id, ?, 0, 0, 0 FROM billing_plans '
            . 'WHERE id NOT IN (SELECT plan_id FROM billing_plan_permissions WHERE name = ?)',
            [self::KEY_NAME, self::KEY_NAME]
        );

        $userPermissions = settings('main_settings.user_permissions');

        if ($userPermissions && empty($userPermissions[self::KEY_NAME])) {
            $userPermissions[self::KEY_NAME] = ['view' => 0, 'edit' => 0, 'remove' => 0];
            settings('main_settings.user_permissions', $userPermissions);
        }
    }

    public function down()
    {
        DB::table('user_permissions')->where('name', self::KEY_NAME)->delete();
        DB::table('billing_plan_permissions')->where('name', self::KEY_NAME)->delete();
    }
}
