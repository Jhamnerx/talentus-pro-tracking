<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clasificacion de vehiculo (tipo + estado) POR USUARIO.
 *
 * Hasta ahora devices.vehicle_type_id / vehicle_state_id eran columnas globales
 * aunque los tipos pertenecen a un usuario y los dispositivos se comparten
 * entre cuentas. Esta tabla guarda una fila por (device, user) y la migracion
 * copia la clasificacion actual al usuario dueño del tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('device_vehicle_classifications')) {
            Schema::create('device_vehicle_classifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('device_id');
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('vehicle_type_id');
                $table->unsignedBigInteger('vehicle_state_id')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('cascade');
                $table->foreign('vehicle_state_id')->references('id')->on('vehicle_states')->onDelete('set null');

                $table->unique(['device_id', 'user_id'], 'device_vehicle_classifications_device_user_unique');
                $table->index(['user_id', 'vehicle_type_id'], 'device_vehicle_classifications_user_type_index');
            });
        }

        $this->copyLegacyClassifications();
    }

    public function down(): void
    {
        Schema::dropIfExists('device_vehicle_classifications');
    }

    /**
     * Copia devices.vehicle_type_id / vehicle_state_id al usuario dueño del tipo.
     * El estado solo se copia si pertenece a ese tipo; si no, queda null.
     */
    private function copyLegacyClassifications(): void
    {
        if (!Schema::hasColumn('devices', 'vehicle_type_id'))
            return;

        $now = date('Y-m-d H:i:s');

        $select = DB::table('devices')
            ->join('vehicle_types', 'vehicle_types.id', '=', 'devices.vehicle_type_id')
            ->leftJoin('vehicle_states', function ($join) {
                $join->on('vehicle_states.id', '=', 'devices.vehicle_state_id')
                    ->on('vehicle_states.vehicle_type_id', '=', 'devices.vehicle_type_id');
            })
            ->leftJoin('device_vehicle_classifications as dvc', function ($join) {
                $join->on('dvc.device_id', '=', 'devices.id')
                    ->on('dvc.user_id', '=', 'vehicle_types.user_id');
            })
            ->whereNotNull('devices.vehicle_type_id')
            ->whereNull('dvc.id')
            ->select([
                'devices.id',
                'vehicle_types.user_id',
                'devices.vehicle_type_id',
                'vehicle_states.id',
                DB::raw("'{$now}'"),
                DB::raw("'{$now}'"),
            ]);

        DB::table('device_vehicle_classifications')->insertUsing(
            ['device_id', 'user_id', 'vehicle_type_id', 'vehicle_state_id', 'created_at', 'updated_at'],
            $select
        );
    }
};
