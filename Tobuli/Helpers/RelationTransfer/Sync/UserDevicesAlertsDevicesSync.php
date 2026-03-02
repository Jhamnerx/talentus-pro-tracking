<?php

namespace Tobuli\Helpers\RelationTransfer\Sync;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\User;

/**
 * @property User $parent
 */
class UserDevicesAlertsDevicesSync extends AbstractRelationSync
{
    protected function _attach($only): void
    {
        $user = $this->parent;
        $only = $this->parseItems($only);

        $itemsQuery = $user->alerts()
            ->select(['alerts.id', 'ud.device_id'])
            ->join('user_device_pivot AS ud', 'ud.user_id', 'alerts.user_id')
            ->where('for_all_user_devices', 1);

        if ($only !== null) {
            $itemsQuery->whereIn('ud.device_id', $only);
        }

        if ($this->insertIgnores) {
            DB::statement("INSERT IGNORE INTO alert_device (alert_id, device_id) {$itemsQuery->toRaw()}");

            return;
        }

        $itemsQuery
            ->leftJoin('alert_device AS ad', fn (JoinClause $join) => $join
                ->on('ad.device_id', 'ud.device_id')
                ->on('ad.alert_id', 'alerts.id')
            )
            ->whereNull('ad.device_id');

        DB::statement("INSERT INTO alert_device (alert_id, device_id) {$itemsQuery->toRaw()}");
    }

    protected function _detach($only): void
    {
        DB::table('alert_device')
            ->join('alerts', 'alerts.id', 'alert_device.alert_id')
            ->leftJoin('user_device_pivot AS ud', fn (JoinClause $join) => $join
                ->on('ud.device_id', 'alert_device.device_id')
                ->where('ud.user_id', $this->parent->id)
            )
            ->where('alerts.for_all_user_devices', 1)
            ->where('alerts.user_id', $this->parent->id)
            ->whereNull('ud.user_id')
            ->delete();
    }
}