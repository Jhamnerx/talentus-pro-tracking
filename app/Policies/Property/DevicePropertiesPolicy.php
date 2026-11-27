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
        'device_model',
        'plate_number',
        'registration_number',
        'object_owner',
        'vin',
        'additional_notes',
        'comment',
        'custom_fields',
        'device_type_id',
        'authentication',
        'model_id',
        'max_speed',
        'lbs',
        'tags',
        'has_camera',
        'camera_channels',
        'camera_channel_roles',
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
        'device_model',
        'plate_number',
        'registration_number',
        'object_owner',
        'vin',
        'additional_notes',
        'comment',
        'custom_fields',
        'device_type_id',
        'authentication',
        'model_id',
        'max_speed',
        'lbs',
        'tags',
        'has_camera',
        'camera_channels',
        'camera_channel_roles',
    ];

    protected function expirationDateEditPolicy(User $user, Model $model)
    {
        if (! ($user->isManager() || $user->isAdmin()))
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
        if (! ($user->isManager() || $user->isAdmin()))
            return false;

        return true;
    }

    protected function activeEditPolicy(User $user, Model $model)
    {
        if (! ($user->isManager() || $user->isAdmin()))
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

    // Video JT1078: configurable con el permiso device_video.

    protected function hasCameraViewPolicy(User $user, Model $model)
    {
        return $user->perm('device_video', 'view');
    }

    protected function hasCameraEditPolicy(User $user, Model $model)
    {
        return $user->perm('device_video', 'edit');
    }

    protected function cameraChannelsViewPolicy(User $user, Model $model)
    {
        return $user->perm('device_video', 'view');
    }

    protected function cameraChannelsEditPolicy(User $user, Model $model)
    {
        return $user->perm('device_video', 'edit');
    }

    protected function cameraChannelRolesViewPolicy(User $user, Model $model)
    {
        return $user->perm('device_video', 'view');
    }

    protected function cameraChannelRolesEditPolicy(User $user, Model $model)
    {
        return $user->perm('device_video', 'edit');
    }

    protected function tagsViewPolicy()
    {
        return config('addon.tags');
    }

    protected function tagsEditPolicy()
    {
        return config('addon.tags');
    }
}
