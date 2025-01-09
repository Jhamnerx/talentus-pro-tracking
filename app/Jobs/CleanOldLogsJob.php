<?php

namespace App\Jobs;


use Illuminate\Bus\Queueable;
use Tobuli\Entities\LogEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tobuli\Entities\WebservicesLogs;

class CleanOldLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $days;

    /**
     * Create a new job instance.
     *
     * @param int $days Número de días antes de los cuales los logs deben ser eliminados
     * @return void
     */
    public function __construct($days = 30)
    {
        $this->days = $days;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Fecha límite para los logs que se deben eliminar
        $cutoffDate = now()->subDays($this->days);

        // Eliminar los logs que son anteriores a la fecha límite
        $deletedLogs = WebservicesLogs::where('created_at', '<', $cutoffDate)->delete();

        Log::info("CleanOldLogsJob: $deletedLogs logs eliminados que son anteriores a $cutoffDate.");
    }
}
