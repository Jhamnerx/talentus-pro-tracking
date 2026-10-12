<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones, fase 3: descuentos que el usuario activa para las horas
 * trabajadas netas (abastecimiento, ralenti, paradas no autorizadas, pala
 * inactiva). JSON como texto, igual que authorized_categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operations', 'worked_hours_deductions')) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->text('worked_hours_deductions')->nullable()->after('authorized_categories');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operations', 'worked_hours_deductions')) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('worked_hours_deductions');
        });
    }
};
