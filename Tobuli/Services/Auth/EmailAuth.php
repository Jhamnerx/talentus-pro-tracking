<?php

namespace Tobuli\Services\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class EmailAuth implements AuthInterface
{
    public static function getKey(): string
    {
        return 'email';
    }

    public function prepareLogout(Authenticatable $authenticatable)
    {
    }
}