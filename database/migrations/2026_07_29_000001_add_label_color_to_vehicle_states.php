<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_states', function (Blueprint $table) {
            // label: identificador corto para el badge junto al nombre (ej. "V"/"C").
            $table->string('label', 24)->nullable()->after('name');
            // color: color del badge del estado (#RRGGBB / #RRGGBBAA).
            $table->string('color', 9)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_states', function (Blueprint $table) {
            $table->dropColumn(['label', 'color']);
        });
    }
};
