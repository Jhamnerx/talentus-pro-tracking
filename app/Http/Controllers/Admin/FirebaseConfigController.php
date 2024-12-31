<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Tobuli\Helpers\FirebaseConfig;
use Tobuli\Services\FcmService;

class FirebaseConfigController extends Controller
{
    private FirebaseConfig $firebaseConfig;

    public function __construct()
    {
        parent::__construct();

        $this->firebaseConfig = new FirebaseConfig();
    }

    public function index()
    {
        return response()->download($this->firebaseConfig->getConfigPath());
    }

    public function store()
    {
        request()->validate([
            'file' => 'required_without:use_default|file|mimes:json',
            'use_default' => 'required_without:file|boolean',
        ]);

        if ($file = request()->file('file')) {
            $this->firebaseConfig->storeCustom($file);
        } else {
            $this->firebaseConfig->removeCustom();
        }

        return redirect()->route('admin.main_server_settings.index')
            ->withSuccess(trans('front.successfully_saved'));
    }

    public function test()
    {
        $user = $this->user;

        if ($user->fcmTokens()->count() == 0) {
            return redirect()->back()->withErrors(['User has no FCM tokens']);
        }

        try {
            $fcmService = new FcmService();
            $fcmService->send($user, 'Test title', 'Test body');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors([$e->getMessage()]);
        }

        return redirect()->route('admin.main_server_settings.index')
            ->withSuccess(trans('global.success'));
    }

    public function destroy()
    {
        $this->firebaseConfig->removeCustom();

        return redirect()->route('admin.main_server_settings.index')
            ->withSuccess(trans('global.success'));
    }
}