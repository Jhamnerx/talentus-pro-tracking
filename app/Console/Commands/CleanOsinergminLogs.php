<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class CleanOsinergminLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:clean-osinergmin {--days=7 : Number of days to keep}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old Osinergmin log files to prevent accumulation';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = $this->option('days');
        $logsPath = storage_path('logs');
        $cutoffDate = Carbon::now()->subDays($days);

        $deletedFiles = 0;
        $totalSize = 0;

        // Buscar archivos de log de Osinergmin
        $logFiles = File::glob($logsPath . '/osinergmin-*.log');

        foreach ($logFiles as $file) {
            $fileDate = $this->extractDateFromFilename(basename($file));

            if ($fileDate && $fileDate->lt($cutoffDate)) {
                $fileSize = File::size($file);
                $totalSize += $fileSize;

                if (File::delete($file)) {
                    $deletedFiles++;
                    $this->info("Deleted: " . basename($file) . " (" . $this->formatBytes($fileSize) . ")");
                } else {
                    $this->error("Failed to delete: " . basename($file));
                }
            }
        }

        if ($deletedFiles > 0) {
            $this->info("Cleanup completed: {$deletedFiles} files deleted, " . $this->formatBytes($totalSize) . " freed");
        } else {
            $this->info("No old Osinergmin log files found to delete (older than {$days} days)");
        }

        return 0;
    }

    /**
     * Extract date from log filename
     *
     * @param string $filename
     * @return Carbon|null
     */
    private function extractDateFromFilename($filename)
    {
        // Formato esperado: osinergmin-2025-01-14.log
        if (preg_match('/osinergmin-(\d{4}-\d{2}-\d{2})\.log/', $filename, $matches)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $matches[1]);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2) . ' ' . $units[$index];
    }
}
