<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * device_expenses.date se borraba sola al editar el gasto.
 *
 * La columna estaba definida asi:
 *
 *   `date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                             ON UPDATE CURRENT_TIMESTAMP
 *
 * Es el comportamiento que MySQL le da automaticamente a la PRIMERA columna
 * TIMESTAMP de una tabla cuando explicit_defaults_for_timestamp esta
 * desactivado. Aqui no es una marca de tiempo tecnica: es la FECHA DEL GASTO,
 * que escribe el usuario.
 *
 * El efecto: Eloquent solo incluye en el UPDATE los atributos que han
 * cambiado. Al editar cualquier otro campo (la cantidad, por ejemplo), `date`
 * no viaja en la sentencia, MySQL aplica el ON UPDATE y la fecha del gasto
 * pasa a ser la del momento de guardar. Silenciosamente, y para cualquier
 * escritor: la v2, el legacy y la API.
 *
 * Se quita el ON UPDATE y se conserva el DEFAULT, que si es util para un
 * alta sin fecha.
 *
 * Las otras dos columnas del esquema con el mismo atributo
 * (history_recalculations.date y unregistered_devices_log.date) NO se tocan:
 * son tablas de registro donde "se actualiza sola" puede ser intencionado.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('device_expenses') || ! Schema::hasColumn('device_expenses', 'date')) {
            return;
        }

        DB::statement('ALTER TABLE `device_expenses`
            MODIFY `date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down()
    {
        if (! Schema::hasTable('device_expenses') || ! Schema::hasColumn('device_expenses', 'date')) {
            return;
        }

        DB::statement('ALTER TABLE `device_expenses`
            MODIFY `date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP');
    }
};
