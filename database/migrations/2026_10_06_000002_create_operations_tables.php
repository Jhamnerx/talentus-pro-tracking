<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo de Operaciones: control de vueltas entre puntos de carga y descarga.
 *
 * Una vuelta es un ciclo de cuatro estados (en carga -> ida -> en descarga ->
 * vuelta) y se guarda SOLO en las transiciones: 1 ciclo, 2 tramos, 2 visitas y
 * unas pocas paradas por vuelta. Nada se escribe por trama.
 *
 * Decisiones de esquema (ver "plan de operaciones.md" §5):
 * - Las PK de users/devices/geofences/device_groups son INT UNSIGNED
 *   (`increments`): las FK hacia ellas van como unsignedInteger. Entre tablas
 *   operation_* (creadas con id() = BIGINT) van como unsignedBigInteger.
 * - Horas en dateTime UTC, nunca timestamp: en MariaDB 10.5 (produccion) una
 *   TIMESTAMP NOT NULL gana ON UPDATE CURRENT_TIMESTAMP sin pedirlo (ya paso
 *   con device_expenses.date).
 * - Sin columnas espaciales ni JSON nativo: la geometria del punto es texto
 *   con el mismo formato que geofences.coordinates y se evalua en PHP con
 *   PolygonHelper, igual que alertas y reportes. Asi la migracion corre igual
 *   en MySQL 8 (desarrollo) y MariaDB 10.5 (produccion).
 * - Los puntos guardan su propia copia de la ubicacion y se versionan
 *   (active_from/active_to): mover la geocerca no reescribe vueltas cerradas.
 * - Nada en cascada desde geofences ni device_groups: una geocerca o un grupo
 *   en uso no se pueden borrar (lo rechaza el service); el SET NULL queda solo
 *   para el borrado del usuario dueño, que ocurre en la BD sin pasar por PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operations')) {
            return;
        }

        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('name');

            // Grupo de vehiculos del dueño (device_groups es por usuario).
            // Nulo solo si el grupo desaparecio por el borrado del dueño.
            $table->unsignedInteger('device_group_id')->nullable();

            $table->boolean('active')->default(1);

            // Permanencia minima dentro/fuera de un punto para confirmar la
            // entrada o la salida (rebote del GPS en el borde).
            $table->unsignedSmallInteger('min_dwell_seconds')->default(30);

            // Umbral de parada dentro de un tramo (stop_seconds del pipeline).
            $table->unsignedSmallInteger('stop_threshold_seconds')->default(60);

            // Un tramo que no llega al otro punto en este tiempo queda incompleto.
            $table->unsignedSmallInteger('max_leg_hours')->default(6);

            // Categorias de geocerca donde una parada es autorizada. JSON como
            // texto (lista de strings), p. ej. ["grifo","taller","descanso"].
            $table->text('authorized_categories')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('device_group_id')->references('id')->on('device_groups')->onDelete('set null');

            $table->index(['user_id', 'active']);
            $table->index('device_group_id');
        });

        Schema::create('operation_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->string('name', 60);

            // Un turno cuya hora de fin es menor o igual a la de inicio
            // termina al dia siguiente (Noche 19:00-07:00).
            $table->time('start_time');
            $table->time('end_time');

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->index('operation_id');
        });

        Schema::create('operation_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');

            // Geocerca de origen. Nula si la geocerca desaparecio con su dueño.
            $table->unsignedInteger('geofence_id')->nullable();

            // 'load' (carga) o 'unload' (descarga).
            $table->string('role', 20);

            // Copia del nombre y de la ubicacion en el momento de la version:
            // {"type":"polygon|circle|line","coordinates":[...],"center":{...},"radius":n}
            $table->string('name');
            $table->text('geometry');

            // Vigencia de esta version. active_to nulo = version vigente.
            $table->dateTime('active_from');
            $table->dateTime('active_to')->nullable();

            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('geofence_id')->references('id')->on('geofences')->onDelete('set null');

            $table->index(['operation_id', 'active_to']);
            $table->index('geofence_id');
        });

        Schema::create('operation_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->unsignedInteger('device_id');

            // Dia calendario de la salida de carga, en la zona horaria del dueño.
            $table->date('work_date');

            // Turno al que pertenece la vuelta (el del momento de salir de
            // carga) y el dia en que ese turno empezo. shift_name es una foto:
            // cambiar los turnos despues no altera las vueltas cerradas.
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('shift_name', 60)->nullable();
            $table->date('shift_date')->nullable();

            // Numero correlativo de la vuelta dentro del dia del vehiculo.
            $table->unsignedSmallInteger('number')->default(0);

            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            // open | completed | incomplete
            $table->string('status', 20)->default('open');

            $table->unsignedInteger('load_seconds')->nullable();
            $table->unsignedInteger('unload_seconds')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('operation_shifts')->onDelete('set null');

            $table->index(['operation_id', 'work_date']);
            $table->index(['operation_id', 'shift_date', 'shift_id'], 'operation_cycles_shift_index');
            $table->index(['device_id', 'started_at']);
        });

        Schema::create('operation_legs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedInteger('device_id');

            // 'outbound' (ida: carga -> descarga) o 'inbound' (vuelta).
            $table->string('direction', 20);

            $table->unsignedBigInteger('from_point_id')->nullable();
            $table->unsignedBigInteger('to_point_id')->nullable();

            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            // open | completed | incomplete
            $table->string('status', 20)->default('open');

            // Fecha en que operations:process calculo paradas/ralenti/etc.
            // Nula = tramo cerrado pendiente de calculo.
            $table->dateTime('calculated_at')->nullable();

            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedInteger('stop_seconds')->nullable();
            $table->unsignedInteger('idle_seconds')->nullable();
            $table->unsignedInteger('engine_off_seconds')->nullable();
            $table->unsignedInteger('gap_seconds')->nullable();
            $table->unsignedSmallInteger('stops_count')->nullable();
            $table->unsignedSmallInteger('unauthorized_stops_count')->nullable();

            $table->timestamps();

            $table->foreign('cycle_id')->references('id')->on('operation_cycles')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('from_point_id')->references('id')->on('operation_points')->onDelete('set null');
            $table->foreign('to_point_id')->references('id')->on('operation_points')->onDelete('set null');

            $table->index('cycle_id');
            $table->index(['device_id', 'started_at']);
            $table->index(['status', 'calculated_at']);
        });

        Schema::create('operation_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('operation_point_id')->nullable();
            $table->unsignedInteger('device_id');

            // Copia del rol del punto, para no depender de la fila del punto.
            $table->string('role', 20);

            $table->dateTime('entered_at');
            $table->dateTime('exited_at')->nullable();

            // Visita de paso: el segmento entre dos tramas cruzo el punto sin
            // que ninguna trama cayera dentro.
            $table->boolean('passing')->default(0);

            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('operation_point_id')->references('id')->on('operation_points')->onDelete('set null');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');

            $table->index(['device_id', 'entered_at']);
            $table->index(['operation_id', 'entered_at']);
        });

        Schema::create('operation_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leg_id');
            $table->unsignedInteger('device_id');

            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('duration_seconds');

            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->string('address')->nullable();

            // Desglose por motor. Nulos si el vehiculo no tiene sensor de
            // ignicion elegido (Device::getEngineDetect() nulo).
            $table->unsignedInteger('engine_on_seconds')->nullable();
            $table->unsignedInteger('engine_off_seconds')->nullable();

            // Geocerca autorizada donde cayo la parada, si la hay.
            $table->string('geofence_name')->nullable();
            $table->boolean('authorized')->default(0);

            // La parada termino con un salto de posicion (jump_from_stop):
            // posible GPS congelado, no cuenta como no autorizada hasta revisarla.
            $table->boolean('doubtful')->default(0);

            $table->timestamps();

            $table->foreign('leg_id')->references('id')->on('operation_legs')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');

            $table->index('leg_id');
            $table->index(['device_id', 'started_at']);
        });

        // Estado en vivo de cada vehiculo. Lo lee OperationChecker una vez por
        // lote de PositionsWriter (como las alertas) y lo escribe solo en
        // transiciones. Un vehiculo cuenta en una sola operacion a la vez.
        Schema::create('operation_device_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id');
            $table->unsignedBigInteger('operation_id');

            // unknown | at_load | outbound | at_unload | inbound | no_data
            $table->string('state', 20)->default('unknown');

            $table->unsignedBigInteger('current_point_id')->nullable();

            // Candidato a entrada (o a salida, si es nulo con current_point_id
            // puesto) y desde cuando; se confirma al cumplir min_dwell_seconds.
            $table->unsignedBigInteger('candidate_point_id')->nullable();
            $table->dateTime('pending_since')->nullable();

            $table->unsignedBigInteger('open_visit_id')->nullable();
            $table->unsignedBigInteger('open_leg_id')->nullable();
            $table->unsignedBigInteger('open_cycle_id')->nullable();

            // Hora de la ultima trama evaluada y desde cuando esta sin datos.
            $table->dateTime('last_time')->nullable();
            $table->dateTime('no_data_since')->nullable();

            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('current_point_id', 'operation_device_state_current_point_fk')
                ->references('id')->on('operation_points')->onDelete('set null');
            $table->foreign('candidate_point_id', 'operation_device_state_candidate_point_fk')
                ->references('id')->on('operation_points')->onDelete('set null');
            $table->foreign('open_visit_id', 'operation_device_state_open_visit_fk')
                ->references('id')->on('operation_visits')->onDelete('set null');
            $table->foreign('open_leg_id', 'operation_device_state_open_leg_fk')
                ->references('id')->on('operation_legs')->onDelete('set null');
            $table->foreign('open_cycle_id', 'operation_device_state_open_cycle_fk')
                ->references('id')->on('operation_cycles')->onDelete('set null');

            $table->unique('device_id');
            $table->index('operation_id');
        });

        // Cola de recalculos. No se reutiliza history_recalculations: su
        // comando borra la fila y solo recalcula distancias.
        Schema::create('operation_recalculations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->unsignedInteger('device_id');

            // Desde cuando rehacer. Las tramas atrasadas del mismo vehiculo se
            // acumulan en una sola fila pendiente con LEAST(from_time).
            $table->dateTime('from_time');

            // Ubicacion de los puntos: la vigente en cada momento (0) o la
            // actual (1, cuando el punto estaba mal dibujado).
            $table->boolean('use_current_points')->default(0);

            // pending | running | done | failed
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();

            // Nulo = recalculo automatico por tramas atrasadas.
            $table->unsignedInteger('requested_by')->nullable();

            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('set null');

            // La "una sola fila pendiente por vehiculo" la garantiza el codigo
            // (updateOrCreate con status=pending); un unique con status
            // chocaria al acumular filas done.
            $table->index(['operation_id', 'device_id', 'status'], 'operation_recalculations_lookup_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_recalculations');
        Schema::dropIfExists('operation_device_state');
        Schema::dropIfExists('operation_stops');
        Schema::dropIfExists('operation_visits');
        Schema::dropIfExists('operation_legs');
        Schema::dropIfExists('operation_cycles');
        Schema::dropIfExists('operation_points');
        Schema::dropIfExists('operation_shifts');
        Schema::dropIfExists('operations');
    }
};
