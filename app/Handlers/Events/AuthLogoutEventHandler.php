<?php

namespace App\Handlers\Events;

use App\Http\Middleware\OneSessionPerUser;
use Illuminate\Auth\Events\Logout;
use Tobuli\Entities\User;
use Tobuli\Services\Auth\AuthInterface;


class AuthLogoutEventHandler {

    /**
     * Create the event handler.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  Logout $event
     * @return void
     */
    public function handle(Logout $event)
    {
        /** @var AuthInterface $auth */
        foreach (app()->tagged('auths') as $auth) {
            $auth->prepareLogout($event->user);
        }

        session()->forget('hash');

        if (config('addon.one_session_per_user') && $event->user instanceof User) {
            if (!OneSessionPerUser::hasOtherSession($event->user))
                OneSessionPerUser::forgetSession($event->user);
        }

        User::clearBootedModels();
    }

}