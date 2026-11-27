<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pasa a TODOS los usuarios a la UI v2.
 *
 * La preferencia vive en users.settings (JSON), clave plana `ui_version`
 * (Ui\V2\BaseController::PREF_UI_VERSION). ObjectsController@index redirige a
 * /ui/v2/live cuando vale 'v2'. Hasta ahora solo la tenían los usuarios nuevos
 * (UserService::create) y quienes pulsaron «Nueva UI»; el resto seguía en la
 * clásica. Esta migración la pone en 'v2' para todos, incluidos los que habían
 * elegido «Versión anterior» ('legacy'). Después cada uno puede volver a la
 * clásica desde el menú, como siempre.
 *
 * Se hace en PHP (no JSON_SET) para que corra igual en MySQL 8 (dev) y
 * MariaDB 10.5 (producción), y con DB::table para no disparar eventos ni
 * ChangeLogs del modelo User.
 */
return new class extends Migration {
    public function up()
    {
        DB::table('users')
            ->select(['id', 'settings'])
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $settings = json_decode($user->settings ?? '', true);

                    if (!is_array($settings)) {
                        $settings = [];
                    }

                    if (($settings['ui_version'] ?? null) === 'v2') {
                        continue;
                    }

                    $settings['ui_version'] = 'v2';

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['settings' => json_encode($settings)]);
                }
            });
    }

    public function down()
    {
        // Sin vuelta atrás: no se guardó la preferencia anterior de cada
        // usuario, y cada uno puede volver a la clásica con «Versión anterior».
    }
};
