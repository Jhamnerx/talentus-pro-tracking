<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones, fase 2: justificacion de paradas.
 *
 * - operation_stop_reasons: catalogo de motivos por usuario. `authorizes`
 *   dice si el motivo convierte la parada en autorizada o solo la explica.
 * - operation_stops gana reason_id, note, justified_by y justified_at.
 *   "Otro" no es una fila del catalogo: es reason_id nulo con nota obligatoria.
 *
 * Mismas reglas de tipos que la migracion de la fase 1: unsignedInteger hacia
 * users, unsignedBigInteger entre tablas operation_*, dateTime en UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operation_stop_reasons')) {
            Schema::create('operation_stop_reasons', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->string('name', 100);
                $table->boolean('authorizes')->default(1);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['user_id', 'position']);
            });
        }

        if (! Schema::hasColumn('operation_stops', 'reason_id')) {
            Schema::table('operation_stops', function (Blueprint $table) {
                $table->unsignedBigInteger('reason_id')->nullable()->after('doubtful');
                $table->text('note')->nullable()->after('reason_id');
                $table->unsignedInteger('justified_by')->nullable()->after('note');
                $table->dateTime('justified_at')->nullable()->after('justified_by');

                $table->foreign('reason_id')->references('id')->on('operation_stop_reasons')->onDelete('set null');
                $table->foreign('justified_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('operation_stops', 'reason_id')) {
            Schema::table('operation_stops', function (Blueprint $table) {
                $table->dropForeign(['reason_id']);
                $table->dropForeign(['justified_by']);
                $table->dropColumn(['reason_id', 'note', 'justified_by', 'justified_at']);
            });
        }

        Schema::dropIfExists('operation_stop_reasons');
    }
};
