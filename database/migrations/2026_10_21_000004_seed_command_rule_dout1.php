<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migra el caso DOUT1 (buzzer FTC921) desde el codigo hardcodeado
 * (SendGetioProbe + AutoDisableDoutListener, eliminados) a una norma de comando:
 * getio cada 5 min al equipo vigilado; si responde "DO1:1" -> "setdigout 0".
 *
 * Solo se crea si el dispositivo existe en esta base y no hay ya una norma con
 * ese nombre. Dueño: ultimo admin que envio un comando al equipo, o el primer admin.
 */
return new class extends Migration
{
    private const NAME = 'DOUT1 buzzer FTC921';
    private const IMEI = '860693084599456';

    public function up(): void
    {
        if (!Schema::hasTable('command_rules')) {
            return;
        }

        if (DB::table('command_rules')->where('name', self::NAME)->exists()) {
            return;
        }

        $device = DB::table('devices')->where('imei', self::IMEI)->first(['id', 'imei']);
        if (!$device) {
            return;
        }

        $userId = DB::table('sent_commands')
            ->join('users', 'users.id', '=', 'sent_commands.user_id')
            ->where('sent_commands.device_imei', self::IMEI)
            ->where('users.group_id', 1)
            ->orderByDesc('sent_commands.created_at')
            ->value('users.id');

        if (!$userId) {
            $userId = DB::table('users')->where('group_id', 1)->where('active', 1)->orderBy('id')->value('id');
        }

        if (!$userId) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');

        $ruleId = DB::table('command_rules')->insertGetId([
            'user_id'               => $userId,
            'name'                  => self::NAME,
            'description'           => 'Migrado desde dout:probe-getio / AutoDisableDoutListener',
            'active'                => 1,
            'query_command'         => 'getio',
            'interval_minutes'      => 5,
            'only_connected'        => 1,
            'evaluate_any_response' => 1, // el listener original evaluaba cualquier respuesta
            'cooldown_minutes'      => 0,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        DB::table('command_rule_devices')->insert([
            'command_rule_id' => $ruleId,
            'device_id'       => $device->id,
        ]);

        DB::table('command_rule_conditions')->insert([
            'command_rule_id' => $ruleId,
            'position'        => 0,
            'match_type'      => 'regex',
            'pattern'         => '\bDO1:1\b',
            'action_command'  => 'setdigout 0',
            'stop_on_match'   => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('command_rules')) {
            DB::table('command_rules')->where('name', self::NAME)->delete();
        }
    }
};
