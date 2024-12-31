<?php


namespace Tobuli\Services\EntityLoader;


use Tobuli\Entities\DeviceGroup;
use Tobuli\Entities\User;

class UserDevicesGroupLoader extends DevicesGroupLoader
{
    protected $user;

    public function __construct(User $user)
    {
        parent::__construct($user);

        $this->setQueryItems(
            $this->user->devices()
                ->getQuery()
                ->clearOrdersBy()
        );

        $this->setQueryGroups(
            DeviceGroup::where('user_id', $this->user->id)
        );
    }

    protected function scopeOrderDefault($query)
    {
        return $query->orderBy('group_id', 'asc')->orderBy('devices.name', 'asc');
    }
}