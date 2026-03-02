<?php

namespace Tobuli\Helpers\RelationTransfer\Sync;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;

/**
 * @property Device $parent
 */
class DeviceUsersAlertsDevicesSync extends AbstractRelationSync
{
    protected function _attach($only): void
    {
        $itemsQuery = Alert
            ::select(['id', 'ud.device_id'])
            ->join('user_device_pivot AS ud', fn (JoinClause $join) => $join
                ->on('ud.user_id', 'alerts.user_id')
                ->where('ud.device_id', $this->parent->id)
            )
            ->where('for_all_user_devices', 1);

        if ($only !== null) {
            $itemsQuery->whereIn('alerts.user_id', $only);
        }

        if ($this->insertIgnores) {
            DB::statement("INSERT IGNORE INTO alert_device (alert_id, device_id) {$itemsQuery->toRaw()}");

            return;
        }

        $itemsQuery
            ->leftJoin('alert_device AS ad', fn (JoinClause $join) => $join
                ->on('ad.alert_id', 'alerts.id')
                ->on('ad.device_id', 'ud.device_id')
            )
            ->whereNull('ad.device_id');

        DB::statement("INSERT INTO alert_device (alert_id, device_id) {$itemsQuery->toRaw()}");
    }

    protected function _detach($only): void
    {
        $itemsQuery = Alert
            ::select(['id', DB::raw($this->parent->id)])
            ->leftJoin('user_device_pivot AS ud', fn (JoinClause $join) => $join
                ->on('ud.user_id', 'alerts.user_id')
                ->where('ud.device_id', $this->parent->id)
            )
            ->where('for_all_user_devices', 1)
            ->whereNull('ud.device_id');

        if ($only !== null) {
            $itemsQuery->whereIn('alerts.user_id', $only);
        }

        DB::table('alert_device')
            ->whereRaw("(alert_id, device_id) IN ({$itemsQuery->toRaw()})")
            ->delete();
    }
}