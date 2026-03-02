<?php

namespace Tobuli\Helpers\Dashboard\Blocks;

use Carbon\Carbon;
use CustomFacades\ModalHelpers\AlertModalHelper;
use Tobuli\Lookups\Tables\DevicesExpiredLookupTable;
use Tobuli\Lookups\Tables\DevicesLookupTable;
use Tobuli\Lookups\Tables\DevicesNeverConnectedLookupTable;
use Tobuli\Lookups\Tables\DevicesOfflineLookupTable;
use Tobuli\Lookups\Tables\DevicesOnlineLookupTable;

class DeviceStatusCountsBlock extends Block
{
    protected function getName()
    {
        return 'device_status_counts';
    }

    protected function getContent()
    {
        $devices = $this->user->devices();

        $total    = $devices->count();
        $moving   = $devices->move()->count();
        $stopped  = $devices->stop()->online()->count();
        $offline  = $devices->offline()->count();
        $expired  = $devices->expired()->count();
        $never    = $devices->neverConnected()->count();

        // Alertas disparadas hoy
        $alertsToday = 0;
        if ($this->user->perm('events', 'view')) {
            try {
                $todayStart  = Carbon::now()->startOfDay();
                $alertsToday = AlertModalHelper::summary($todayStart)->sum('count');
            } catch (\Exception $e) {
                $alertsToday = 0;
            }
        }

        return [
            // Tarjetas grandes superiores
            'cards' => [
                [
                    'label' => trans('front.moving'),
                    'count' => $moving,
                    'color' => 'card-moving',
                    'icon'  => 'glyphicon glyphicon-arrow-right',
                    'url'   => DevicesOnlineLookupTable::route('index'),
                ],
                [
                    'label' => trans('front.stopped'),
                    'count' => $stopped,
                    'color' => 'card-stopped',
                    'icon'  => 'glyphicon glyphicon-stop',
                    'url'   => DevicesOnlineLookupTable::route('index'),
                ],
                [
                    'label' => trans('front.offline'),
                    'count' => $offline,
                    'color' => 'card-offline',
                    'icon'  => 'glyphicon glyphicon-ban-circle',
                    'url'   => DevicesOfflineLookupTable::route('index'),
                ],
                [
                    'label' => trans('front.alert'),
                    'count' => $alertsToday,
                    'color' => 'card-alerts',
                    'icon'  => 'glyphicon glyphicon-bell',
                    'url'   => null,
                ],
            ],
            // Fila inferior con detalle
            'statuses' => [
                [
                    'label' => trans('front.count'),
                    'data'  => $total,
                    'url'   => DevicesLookupTable::route('index'),
                ],
                [
                    'label' => trans('global.online'),
                    'data'  => $moving + $stopped,
                    'url'   => DevicesOnlineLookupTable::route('index'),
                ],
                [
                    'label' => trans('front.never_connected'),
                    'data'  => $never,
                    'url'   => DevicesNeverConnectedLookupTable::route('index'),
                ],
                [
                    'label' => trans('front.expired'),
                    'data'  => $expired,
                    'url'   => DevicesExpiredLookupTable::route('index'),
                ],
            ],
        ];
    }
}
