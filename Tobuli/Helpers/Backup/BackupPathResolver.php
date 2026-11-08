<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\Backup;
use Tobuli\Entities\BackupProcess;
use Tobuli\Entities\TraccarDevice;
use Tobuli\Helpers\Backup\Process\DatabaseBackuper;
use Tobuli\Helpers\Backup\Process\DevicesPositionsBackuper;
use Tobuli\Helpers\Backup\Process\FilesBackuper;

class BackupPathResolver
{
    /** Primer nivel de la jerarquía de posiciones dentro de una carpeta de backup. */
    public const POSITIONS_ROOT = '1000000000';

    private static array $subfolders = [];

    /**
     * @param Backup|string $backupDate
     */
    public static function backupFolder($backupDate): string
    {
        if ($backupDate instanceof Backup) {
            $backupDate = $backupDate->created_at;
        }

        $date = \Carbon::parse($backupDate)->format('Y-m-d');
        $timestamp = \Carbon::parse($backupDate)->timestamp;

        return "backup_$date-$timestamp";
    }

    /**
     * Ruta del archivo de un elemento, relativa a la carpeta del backup.
     */
    public static function itemPath(BackupProcess $process, $item): string
    {
        switch ($process->type) {
            case FilesBackuper::class:
                return basename($item) . '.tar.gz';
            case DatabaseBackuper::class:
                return self::databaseFile($item);
            case DevicesPositionsBackuper::class:
                return self::devicePositionsFile($item);
            default:
                throw new \InvalidArgumentException('Unsupported backup process type: ' . $process->type);
        }
    }

    public static function databaseFile(array $config): string
    {
        return "{$config['host']}-{$config['database']}-db.sql.gz";
    }

    public static function devicePositionsFile(TraccarDevice $device): string
    {
        $table = $device->positions()->getRelated()->getTable();

        return self::devicePositionsDir($device->id) . $table . '.sql.gz';
    }

    /**
     * Si id = 312456: 1000000000/100000000/10000000/1000000/400000/20000/3000/500/
     */
    public static function devicePositionsDir($id): string
    {
        $path = '';

        foreach (self::subfolders() as $i) {
            $path .= (ceil($id / $i) % 10 * $i) . '/';
        }

        return $path;
    }

    private static function subfolders(): array
    {
        if (self::$subfolders) {
            return self::$subfolders;
        }

        $decimal = '100';
        $dirs = [];

        while (strlen($decimal) <= 10) { // evita números de 20 dígitos, que se guardan como 1.0E+19
            array_unshift($dirs, $decimal);
            $decimal .= '0';
        }

        return self::$subfolders = $dirs;
    }
}
