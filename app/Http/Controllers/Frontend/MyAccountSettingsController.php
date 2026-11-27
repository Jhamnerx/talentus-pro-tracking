<?php namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use CustomFacades\ModalHelpers\MyAccountSettingsModalHelper;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class MyAccountSettingsController extends Controller
{
    public function edit()
    {
        $data = MyAccountSettingsModalHelper::editData();

        return ! $this->api ? view('front::MyAccountSettings.edit')->with($data) : $data;
    }

    public function update()
    {
        return MyAccountSettingsModalHelper::edit();
    }
    
    public function ChangePassword()
    {
        return MyAccountSettingsModalHelper::changePassword();
    }

    public function changeLang($lang)
    {
        // El idioma llega por la URL. Antes se guardaba tal cual, asi que
        // /change_language/xx dejaba al usuario con un idioma inexistente
        // guardado en su ficha.
        if (! in_array($lang, $this->activeLanguages(), true)) {
            return redirect()->to($this->langReturnUrl());
        }

        Session::put('language', $lang);

        if ( !isDemoUser() && $user_id = $this->user->id ) {
            UserRepo::update($user_id, ['lang' => $lang]);
        }

        return redirect()->to($this->langReturnUrl());
    }

    /** Claves de los idiomas marcados como activos en los ajustes. */
    private function activeLanguages(): array
    {
        $idiomas = (array) settings('languages');

        return array_keys(array_filter($idiomas, function ($idioma) {
            // 'active' viene como true, '1' o '0' segun quien lo grabara.
            return ! empty($idioma['active']);
        }));
    }

    /**
     * A donde volver tras cambiar el idioma.
     *
     * Quien quiera volver a su pagina manda ?return=/la/ruta (lo hace el rail
     * de la UI v2): sin eso se redirigia siempre a 'home', o sea al mapa
     * legacy, y cambiar el idioma desde la v2 te echaba de la v2.
     */
    private function langReturnUrl(): string
    {
        $volver = (string) request()->get('return', '');

        // Solo rutas de esta misma aplicacion: admitir un host convertiria
        // esto en un redirector abierto hacia cualquier sitio.
        if ($volver !== '' && $volver[0] === '/' && substr($volver, 0, 2) !== '//') {
            return $volver;
        }

        return route('home');
    }

    public function changeTopToolbar() {
        $status = request()->get('status');
        $status = $status == 1 ? 1 : 0;

        Auth::User()->update(['top_toolbar_open' => $status]);

        return ['status' => 1];
    }

    public function changeMapSettings(Request $request) {
        if ( isDemoUser() )
            return ['status' => 1];

        $settings = array_flip([
            'm_open',
            'm_objects',
            'm_geofences',
            'm_routes',
            'm_poi',
            'm_show_names',
            'm_show_tails',
            'm_traffic',
            'm_aircraft',
            'm_objects_cluster',
            'm_hitos',
            'history_control_arrows',
            'history_control_route',
            'history_control_stops',
            'history_control_events',
        ]);

        $param = $request->get('param');
        $value = $request->get('value');
        if (array_key_exists($param, $settings)) {
            $array = Auth::User()->map_controls;
            $array[$param] = ($value == 'true' ? 1 : 0);

            Auth::User()->update(['map_controls' => $array]);
        }

        return ['status' => 1];
    }
}