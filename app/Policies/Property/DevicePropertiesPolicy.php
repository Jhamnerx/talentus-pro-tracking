<?php

namespace App\Policies\Property;

use Illuminate\Database\Eloquent\Model;
use Tobuli\Entities\User;

class DevicePropertiesPolicy extends PropertyPolicy
{
    protected $entity = 'device';

    protected $editable = [
        'active',
        'protocol',
        'imei',
        'forward',
        'sim_number',
        'expiration_date',
        'sim_activation_date',
        'sim_expiration_date',
        'installation_date',
        'msisdn',
        'custom_fields',
        'device_type_id',
        'authentication',
        'model_id',
        'max_speed',
        'lbs',
    ];

    protected $viewable = [
        'active',
        'protocol',
        'imei',
        'forward',
        'sim_number',
        'expiration_date',
        'sim_activation_date',
        'sim_expiration_date',
        'installation_date',
        'msisdn',
        'custom_fields',
        'device_type_id',
        'authentication',
        'model_id',
        'max_speed',
        'lbs',
    ];

    protected function expirationDateEditPolicy(User $user, Model $model)
    {
        if ( ! ($user->isManager() || $user->isAdmin()))
            return false;

        return true;
    }

    protected function msisdnEditPolicy(User $user, Model $model)
    {
        if (! settings('plugins.sim_blocking.status')) {
            return false;
        }

        return true;
    }

    protected function activeViewPolicy(User $user, Model $model)
    {
        if ( ! ($user->isManager() || $user->isAdmin()))
            return false;

        return true;
    }

    protected function activeEditPolicy(User $user, Model $model)
    {
        if ( ! ($user->isManager() || $user->isAdmin()))
            return false;

        return true;
    }

    protected function modelIdViewPolicy()
    {
        return config('addon.device_models');
    }

    protected function modelIdEditPolicy()
    {
        return config('addon.device_models');
    }

    protected function lbsViewPolicy()
    {
        return config('addon.lbs');
    }

    protected function lbsEditPolicy()
    {
        return config('addon.lbs');
    }
}
