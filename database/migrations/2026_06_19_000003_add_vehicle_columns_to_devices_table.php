<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_type_id')->nullable()->after('device_type_id');
            $table->unsignedBigInteger('vehicle_state_id')->nullable()->after('vehicle_type_id');

            $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('set null');
            $table->foreign('vehicle_state_id')->references('id')->on('vehicle_states')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['vehicle_type_id']);
            $table->dropForeign(['vehicle_state_id']);
            $table->dropColumn(['vehicle_type_id', 'vehicle_state_id']);
        });
    }
};
