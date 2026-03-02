<?php

namespace Tobuli\Reports\Reports;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tobuli\Entities\UserDriver;
use Tobuli\Helpers\Formatter\Facades\Formatter;
use Tobuli\History\Actions\AppendOverspeeding;
use Tobuli\History\Actions\AppendSpeedLimitGeofenceMulti;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\Drivers;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupEngineStatus;
use Tobuli\History\Actions\GroupHarsh;
use Tobuli\History\Actions\GroupOverspeed;
use Tobuli\History\Actions\Harsh;
use Tobuli\History\Actions\Overspeed;
use Tobuli\History\Actions\Speed;
use Tobuli\History\Group;
use Tobuli\Reports\DeviceHistoryReport;
use Tobuli\Reports\RfidFormatter;

class IvmsDriversEventsReport extends DeviceHistoryReport
{
    use RfidFormatter;

    const TYPE_ID = 92;

    protected $disableFields = ['stops', 'show_addresses', 'zones_instead'];
    protected $validation = ['geofences' => 'required'];

    private array $continuousDrivings = [];

    private string $dayShiftStart;
    private string $dayShiftEnd;

    public function __construct()
    {
        parent::__construct();

        $this->formats[] = 'csv';
    }

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function getFilename()
    {
        return 'IVMS_EventReport_' . date('Ymd');
    }

    public function title()
    {
        return trans('front.ivms_events_report');
    }

    protected function getActionsList()
    {
        return [
            AppendSpeedLimitGeofenceMulti::class,
            AppendOverspeeding::class,
            Distance::class,
            Drivers::class,
            Duration::class,
            Harsh::class,
            Overspeed::class,
            Speed::class,
            GroupEngineStatus::class,
            GroupHarsh::class,
            GroupOverspeed::class,
        ];
    }

    protected function beforeGenerate()
    {
        $this->dayShiftStart = '05:00';
        $this->dayShiftEnd = '23:00';
    }

    protected function generate()
    {
        $this->getDevicesQuery()->chunk(1000, function ($devices) {
            foreach ($devices as $device) {
                $this->generateDevice($device);
            }
        });
    }

    protected function generateDevice($device)
    {
        $data = $this->getDeviceHistoryData($device);

        if ($this->isEmptyResult($data)) {
            return null;
        }

        /** @var Group $group */
        foreach ($data['groups']->all() as $group) {
            $row = $this->getDataFromGroup($group, [
                'drivers',
                'location_start',
                'location_end',
            ]);

            $row['type'] = $group->getKey();
            $row['plate_number'] = $device->plate_number;
            $row['duration'] = $group->hasStat('duration') ? $group->getStat('duration')->value() : 0;
            $row['distance'] = $group->hasStat('distance') ? $group->getStat('distance')->value() : 0;

            $startPosition = $group->getStartPosition();
            $endPosition = $group->getEndPosition();

            $row['start_at'] = $startPosition ? Formatter::time()->convert($startPosition->time, 'm/d/Y H:i') : null;
            $row['end_at'] = $endPosition ? Formatter::time()->convert($endPosition->time, 'm/d/Y H:i') : null;
            $row['date'] = $row['end_at'];
            $row['start_time'] = $startPosition ? Carbon::parse($row['start_at'])->format('H:i') : null;
            $row['end_time'] = $endPosition ? Carbon::parse($row['end_at'])->format('H:i') : null;

            $row['start_latitude'] = $startPosition->latitude ?? null;
            $row['start_longitude'] = $startPosition->longitude ?? null;
            $row['end_latitude'] = $endPosition->latitude ?? null;
            $row['end_longitude'] = $endPosition->longitude ?? null;
            $row['end_position'] = $endPosition;

            $drivers = $group->stats()->has('drivers') ? $group->stats()->get('drivers')->value() : null;

            $rows = [];

            if (empty($drivers)) {
                $row['driver_id'] = 0;
                $row['driver_name'] = $row['driver_rfid'] = trans('front.unknown');
                $rows[] = $row;
            } else {
                $drivers = runCacheEntity(UserDriver::class, $drivers);

                /** @var UserDriver $driver */
                foreach ($drivers as $driver) {
                    $row['driver_id'] = $driver->id;
                    $row['driver_name'] = $driver->name;
                    $row['driver_rfid'] = $this->formatRfid($driver->rfid);

                    $rows[] = $row;
                }
            }

            foreach ($rows as $row) {
                switch ($group->getKey()) {
                    case 'overspeed':
                        $this->checkOverspeed($group, $row);
                        break;
                    case 'harsh_acceleration':
                    case 'harsh_breaking':
                    case 'harsh_turning':
                        $this->checkSimple($row);
                        break;
                    case 'engine_on';
                        $this->prepareContinuousDriving($group, $row);
                        $this->checkDailyWork($group, $row);
                        $this->checkDailyDrive($group, $row);
                        break;
                    case 'engine_off';
                        $this->prepareContinuousDriving($group, $row);
                        $this->checkDailyRest($group, $row);
                        $this->checkWeeklyRest($group, $row);
                        break;
                }
            }
        }

        $this->checkAllContinuousDrivings();
    }

    private function checkOverspeed(Group $group, array $row): void
    {
        $row['threshold'] = $group->hasStat('overspeed_limit') ? $group->getStat('overspeed_limit')->value() : null;
        $row['max_value'] = $group->hasStat('speed_max') ? $group->getStat('speed_max')->value() : null;

        $this->items[] = $row;
    }

    private function checkSimple(array $row): void
    {
        $this->items[] = $row;
    }

    private function prepareContinuousDriving(Group $group, array $row): void
    {
        $id = $row['driver_id'];

        $this->summarizeStats($group, $row, $this->continuousDrivings, $id);

        $duration = $group->getStat('duration')->value();

        $this->continuousDrivings[$id]['pause'] = $duration + ($this->continuousDrivings[$id]['pause'] ?? 0);

        if ($this->continuousDrivings[$id]['pause'] > 300) {
            $this->checkContinuousDriving($id);
        }
    }

    private function checkAllContinuousDrivings(): void
    {
        foreach ($this->continuousDrivings as $id => $ignore) {
            $this->checkContinuousDriving($id);
        }
    }

    private function checkContinuousDriving(int $id): void
    {
        if (!isset($this->continuousDrivings[$id])) {
            return;
        }

        $minDuration = $this->isOnlyDayShift($this->continuousDrivings[$id]['start_time'], $this->continuousDrivings[$id]['end_time'])
            ? 4 * 60 * 60
            : 2 * 60 * 60;

        if ($this->continuousDrivings[$id]['duration'] < $minDuration) {
            $this->continuousDrivings[$id] = null;

            return;
        }

        $this->continuousDrivings[$id]['type'] = 'continuous_driving';
        $this->continuousDrivings[$id]['threshold'] = Formatter::duration()->human($minDuration, 'hh:mm:ss');
        $this->continuousDrivings[$id]['max_value'] = Formatter::duration()->human($this->continuousDrivings[$id]['duration'], 'hh:mm:ss');

        $this->addCompoundItem($this->continuousDrivings, $id);
    }

    private function checkDailyWork(Group $group, array $row): void
    {
        $minDuration = $this->isOnlyDayShift($row['start_time'], $row['end_time'])
            ? 15 * 60 * 60
            : 14 * 60 * 60;

        $this->checkDurationEvent($group, $row, $minDuration, 'daily_work');
    }

    private function checkDailyDrive(Group $group, array $row): void
    {
        $minDuration = $this->isOnlyDayShift($row['start_time'], $row['end_time'])
            ? 13 * 60 * 60
            : 12 * 60 * 60;

        $this->checkDurationEvent($group, $row, $minDuration, 'daily_drive');
    }

    private function checkDailyRest(Group $group, array $row): void
    {
        $minDuration = $this->isOnlyDayShift($row['start_time'], $row['end_time'])
            ? 10 * 60 * 60
            : 8 * 60 * 60;

        $this->checkDurationEvent($group, $row, $minDuration, 'daily_rest');
    }

    private function checkWeeklyRest(Group $group, array $row): void
    {
        $minDuration = 24 * 60 * 60;

        $this->checkDurationEvent($group, $row, $minDuration, 'weekly_rest');
    }

    private function checkDurationEvent(Group $group, array $row, int $minDuration, string $type): void
    {
        $duration = $group->getStat('duration')->value();

        if ($duration < $minDuration) {
            return;
        }

        $row['type'] = $type;
        $row['threshold'] = Formatter::duration()->human($minDuration, 'hh:mm:ss');
        $row['max_value'] = Formatter::duration()->human($duration, 'hh:mm:ss');

        $this->items[] = $row;
    }

    private function isOnlyDayShift(string $startTime, string $endTime): bool
    {
        return $startTime >= $this->dayShiftStart && $endTime >= $this->dayShiftStart
            && $startTime < $this->dayShiftEnd && $endTime < $this->dayShiftEnd
            && $startTime < $endTime;
    }

    private function summarizeStats(Group $group, array $row, array &$primary, int $id): void
    {
        $duration = $group->getStat('duration')->value();
        $distance = $group->getStat('distance')->value();

        if (isset($primary[$id])) {
            $primary[$id] = array_merge($primary[$id], Arr::only($row, ['end_at', 'end_time', 'end_position', 'type']));
            $primary[$id]['duration'] += $duration;
            $primary[$id]['distance'] += $distance;
        } else {
            $primary[$id] = $row;
            $primary[$id]['duration'] = $duration;
            $primary[$id]['distance'] = $distance;
        }
    }

    private function addCompoundItem(array &$primary, int $id): void
    {
        if (!isset($primary[$id])) {
            return;
        }

        $primary[$id]['end_latitude'] = $primary[$id]['end_position']->latitude ?? null;
        $primary[$id]['end_longitude'] = $primary[$id]['end_position']->longitude ?? null;
        $primary[$id]['location_end'] = $primary[$id]['end_position'] ? $this->getLocation($primary[$id]['end_position']) : null;

        $this->items[] = $primary[$id];

        $primary[$id] = null;
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            'Date',
            'Event Type',
            'Vehicle No',
            'Driver Name',
            'Driver Employee ID',
            'Event Key',
            'Event Start Location',
            'Event End Location',
            'Event Start Date',
            'Event End Date',
            'Event Duration',
            'Event Distance (KMs)',
            'Event Threshold',
            'Event Start Coordinates',
            'Event End Coordinates',
            'Event Max Value',
        ]);

        foreach ($this->getItems() as $item) {
            fputcsv($file, [
                $item['date'],
                $item['type'],
                $item['plate_number'],
                $item['driver_name'],
                $item['driver_rfid'],
                '',
                $item['location_start'],
                $item['location_end'],
                $item['start_at'],
                $item['end_at'],
                $item['duration'],
                $item['distance'],
                $item['threshold'],
                $item['start_coordinates'],
                $item['end_coordinates'],
                $item['max_value'],
            ]);
        }
    }

    protected function afterGenerate()
    {
        foreach ($this->items as &$item) {
            if (!isset($item['max_value'])) {
                $item['max_value'] = null;
            }

            if (!isset($item['threshold'])) {
                $item['threshold'] = null;
            }

            $item['duration'] = Formatter::duration()->human($item['duration'], 'hh:mm:ss');;
            $item['distance'] = round($item['distance'], 2);

            $item['start_coordinates'] = "{$item['start_latitude']},{$item['start_longitude']}";
            $item['end_coordinates'] = "{$item['end_latitude']},{$item['end_longitude']}";
        }
    }

    protected function getTotals(Group $group, array $only = [])
    {
        return [];
    }

    public static function isAvailable(): bool
    {
        return config('addon.reports_geofleet_in');
    }
}