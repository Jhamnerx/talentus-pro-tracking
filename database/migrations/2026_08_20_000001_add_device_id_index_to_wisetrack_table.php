<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice de `device_id` en `wisetrack`.
 *
 * Es la única cola de reenvío que no lo tenía: sidersales, sutran, osinergmin,
 * comsatel y mininter sí. Sin él, cualquier consulta por vehículo —el propio
 * job de envío, y el panel de cumplimiento que viene después— recorre la tabla
 * entera. En producción llegó a 694.191 filas.
 *
 * OJO al aplicarla en producción: crear el índice sobre una tabla grande la
 * bloquea mientras dura. Conviene hacerlo en hora valle.
 *
 * Idempotente: si el índice ya está, no hace nada.
 */
return new class extends Migration
{
    private const TABLA = 'wisetrack';
    private const INDICE = 'wisetrack_device_id_index';

    public function up()
    {
        if (! Schema::hasTable(self::TABLA)) {
            return;
        }

        if ($this->indiceExiste()) {
            return;
        }

        Schema::table(self::TABLA, function ($table) {
            $table->index('device_id', self::INDICE);
        });
    }

    public function down()
    {
        if (! Schema::hasTable(self::TABLA) || ! $this->indiceExiste()) {
            return;
        }

        Schema::table(self::TABLA, function ($table) {
            $table->dropIndex(self::INDICE);
        });
    }

    /** SHOW INDEX en vez de doctrine/dbal: no hace falta la dependencia. */
    private function indiceExiste(): bool
    {
        foreach (DB::select('SHOW INDEX FROM ' . self::TABLA) as $indice) {
            if ($indice->Key_name === self::INDICE) {
                return true;
            }
        }

        return false;
    }
};
