<?php

namespace Tobuli\Helpers\Backup;

use Carbon\Carbon;
use Tobuli\Helpers\Backup\Destination\DriveDestination;

class BackupRetention
{
    public const PROTECT_FILE = '.conservar';
    public const ACTION_PURGE = 'purge';
    public const ACTION_STRIP = 'strip';

    private const FULL_PREFIX = 'backup_';
    private const WEB_ONLY_PREFIX = 'soloweb_';

    private DriveDestination $drive;
    private int $dailyDays;
    private int $longTermMonths;
    private int $maxDays;

    public function __construct(DriveDestination $drive, array $settings)
    {
        $this->drive = $drive;
        $this->dailyDays = max(1, (int)($settings['retention_daily_days'] ?? 30));
        $this->longTermMonths = max(1, (int)($settings['retention_long_term_months'] ?? 2));
        $this->maxDays = max($this->dailyDays, (int)($settings['retention_max_days'] ?? 365));
    }

    /**
     * @return array<int, array{action: string, folder: string}>
     */
    public function plan(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $folders = [];

        foreach ($this->drive->listFolders(self::FULL_PREFIX) as $name) {
            $date = self::folderDate($name);

            if ($date) {
                $files = $this->drive->listFiles($name);

                $folders[$name] = [
                    'date'      => $date,
                    'complete'  => in_array(BackupIndex::FILE, $files, true),
                    'protected' => in_array(self::PROTECT_FILE, $files, true),
                ];
            }
        }

        $longTerm = $this->longTermFolders($folders);
        $plan = [];

        foreach ($folders as $name => $folder) {
            if ($folder['protected']) {
                continue;
            }

            $age = $folder['date']->diffInDays($now);

            if ($age > $this->maxDays) {
                $plan[] = ['action' => self::ACTION_PURGE, 'folder' => $name];
            } elseif ($age > $this->dailyDays && !in_array($name, $longTerm, true)) {
                $plan[] = ['action' => self::ACTION_STRIP, 'folder' => $name];
            }
        }

        foreach ($this->drive->listFolders(self::WEB_ONLY_PREFIX) as $name) {
            $date = self::folderDate($name);

            if ($date && $date->diffInDays($now) > $this->maxDays
                && !in_array(self::PROTECT_FILE, $this->drive->listFiles($name), true)
            ) {
                $plan[] = ['action' => self::ACTION_PURGE, 'folder' => $name];
            }
        }

        return $plan;
    }

    public function apply(array $plan): void
    {
        foreach ($plan as $step) {
            $folder = $step['folder'];

            if ($step['action'] === self::ACTION_PURGE) {
                $this->drive->purge($folder);
            } else {
                $this->drive->purge($folder . '/' . BackupPathResolver::POSITIONS_ROOT);
                $this->drive->move($folder, self::WEB_ONLY_PREFIX . substr($folder, strlen(self::FULL_PREFIX)));
            }

            logger()->info("Backup retention: {$step['action']} $folder");
        }
    }

    /**
     * La copia completa más antigua de cada mes elegido (con 2 meses: enero, marzo, mayo…).
     */
    private function longTermFolders(array $folders): array
    {
        ksort($folders);

        $byMonth = [];

        foreach ($folders as $name => $folder) {
            $month = (int)$folder['date']->format('n');

            if ($folder['complete'] && ($month - 1) % $this->longTermMonths === 0) {
                $byMonth[$folder['date']->format('Y-m')] = $byMonth[$folder['date']->format('Y-m')] ?? $name;
            }
        }

        return array_values($byMonth);
    }

    public static function folderDate(string $folder): ?Carbon
    {
        if (!preg_match('/^(?:backup|soloweb)_(\d{4}-\d{2}-\d{2})-\d+$/', $folder, $matches)) {
            return null;
        }

        return Carbon::parse($matches[1])->startOfDay();
    }
}
