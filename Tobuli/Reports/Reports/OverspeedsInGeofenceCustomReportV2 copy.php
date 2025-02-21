<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Tobuli\History\Group;
use Tobuli\Entities\Geofence;
use Tobuli\Reports\DeviceReport;
use Tobuli\History\Actions\Harsh;
use Tobuli\History\Actions\Speed;
use Tobuli\History\Actions\Drivers;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\Overspeed;
use Illuminate\Database\QueryException;
use Tobuli\History\Actions\GeofencesIn;
use Tobuli\History\Actions\GroupDriver;
use Tobuli\Reports\DeviceHistoryReport;
use Tobuli\History\Actions\BreakOverspeed;
use Tobuli\History\Actions\GroupOverspeed;
use Tobuli\History\Actions\OverspeedStatic;
use Tobuli\History\Actions\AppendSpeedLimitGeofence;
use Tobuli\History\Actions\AppendOverspeedingProcessOnly;
use Tobuli\History\Actions\AppendOverspeedOnlyInGeofences;

class OverspeedsInGeofenceCustomReportV2 extends DeviceHistoryReport
{
    const TYPE_ID = 95;


    protected $disableFields = ['stops', 'zones_instead'];
    protected $validation = ['geofences' => 'required'];

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return 'Overspeeds in geofences';
    }

    protected function getActionsList()
    {
        return [
            Duration::class,
            Distance::class,
            Speed::class,
            AppendSpeedLimitGeofence::class,
            Overspeed::class,
            Harsh::class,
            Drivers::class,
            GeofencesIn::class,
            AppendOverspeedOnlyInGeofences::class,
            GroupOverspeed::class,
        ];
    }

    protected function getTotals(Group $group, array $only = [])
    {
        return [];
    }

    protected function getTable($data)
    {

        $rows = [];

        foreach ($data['groups']->all() as $group) {
            $distance = $group->stats()->get('distance')->get();


            $rows[] = $this->getDataFromGroup($group, [
                'start_at',
                'end_at',
                'duration',
                'speed',
                'speed_max',
                'speed_avg',
                'location',
                'geofences_in',
                'drivers'
            ]);

            $row['distance'] = round($distance, 2);
            $row['excesive_speed'] = $group->stats()->get('overspeed_count')->get();

            $this->group->applyArray($group->stats()->only([
                'duration',
                'speed_max',
                'speed_avg',
            ]));
        }


        return [
            'rows'   => $rows,
            'totals' => [],
        ];
    }
}
