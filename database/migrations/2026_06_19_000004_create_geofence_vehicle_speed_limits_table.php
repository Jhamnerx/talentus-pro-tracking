<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofence_vehicle_speed_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('geofence_id');
            $table->unsignedBigInteger('vehicle_type_id');
            $table->unsignedBigInteger('vehicle_state_id')->nullable();
            $table->float('speed_limit');
            $table->timestamps();

            $table->foreign('geofence_id')->references('id')->on('geofences')->onDelete('cascade');
            $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('cascade');
            $table->foreign('vehicle_state_id')->references('id')->on('vehicle_states')->onDelete('cascade');

            $table->unique(['geofence_id', 'vehicle_type_id', 'vehicle_state_id'], 'geofence_vehicle_speed_limits_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_vehicle_speed_limits');
    }
};
