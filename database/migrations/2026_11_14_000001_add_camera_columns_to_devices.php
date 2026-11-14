<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Video JT1078 (portado de master-tracking): camara por equipo, canales habilitados,
 * rol de cada canal y capacidades informadas por la camara (0x1003).
 *
 * Migra `devices.video_channels` (cuantos canales: 0..6) al modelo nuevo:
 * n > 0 => has_camera = 1 y camera_channels = [1..n]. `video_channels` queda
 * deprecada (ya no se lee ni se escribe) y se borrara en una migracion posterior.
 *
 * Idempotente: cada columna se agrega solo si falta y solo se migran equipos
 * que todavia no tienen canales en el modelo nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'has_camera'           => fn (Blueprint $t) => $t->boolean('has_camera')->default(0),
            'camera_channels'      => fn (Blueprint $t) => $t->text('camera_channels')->nullable(),
            'camera_channel_roles' => fn (Blueprint $t) => $t->text('camera_channel_roles')->nullable(),
            'camera_attributes'    => fn (Blueprint $t) => $t->text('camera_attributes')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('devices', $column)) {
                continue;
            }

            Schema::table('devices', function (Blueprint $t) use ($definition) {
                $definition($t);
            });
        }

        if ( ! Schema::hasColumn('devices', 'video_channels')) {
            return;
        }

        DB::table('devices')
            ->select('id', 'video_channels')
            ->where('video_channels', '>', 0)
            ->where(fn ($q) => $q->whereNull('camera_channels')->orWhere('camera_channels', '')->orWhere('camera_channels', '[]'))
            ->orderBy('id')
            ->chunk(500, function ($devices) {
                foreach ($devices as $device) {
                    DB::table('devices')->where('id', $device->id)->update([
                        'has_camera'      => 1,
                        'camera_channels' => json_encode(range(1, min(8, (int)$device->video_channels))),
                    ]);
                }
            });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(
            ['has_camera', 'camera_channels', 'camera_channel_roles', 'camera_attributes'],
            fn ($c) => Schema::hasColumn('devices', $c)
        ));

        if ($existing) {
            Schema::table('devices', function (Blueprint $t) use ($existing) {
                $t->dropColumn($existing);
            });
        }
    }
};
