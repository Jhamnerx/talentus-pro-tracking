<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones · reporte de turno, fase A: descansos de cada turno.
 *
 * - `scheduled` (almuerzo, refrigerio): nombre + start_time/end_time dentro
 *   del rango del turno; si end <= start cruza la medianoche como el turno.
 * - `fatigue` (fatiga y somnolencia): sin horario; cuenta cada parada con
 *   motor apagado fuera de los descansos programados. Uno por turno.
 *
 * Mismas reglas de tipos que las migraciones del modulo: unsignedBigInteger
 * entre tablas operation_*, time para horas locales, dateTime (no timestamp).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operation_shift_breaks')) {
            return;
        }

        Schema::create('operation_shift_breaks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->string('name', 60);

            // scheduled | fatigue
            $table->string('kind', 16)->default('scheduled');

            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('shift_id')->references('id')->on('operation_shifts')->onDelete('cascade');
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_shift_breaks');
    }
};
