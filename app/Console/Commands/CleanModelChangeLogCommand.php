<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputArgument;
use Tobuli\Entities\ModelChangeLog;

class CleanModelChangeLogCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'server:modelchangelogclean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old model change log records with table locking for safe operation.';


    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $date = $this->argument('date') . ' 00:00:00';

        $this->info("Starting cleanup of model_change_log records before: {$date}");

        try {
            // Lock the table for write to prevent conflicts during deletion
            DB::statement('LOCK TABLES model_change_logs WRITE');

            $this->info("Table locked. Processing deletions...");

            // Delete records in chunks to avoid memory issues
            $deletedCount = 0;
            do {
                $deleted = ModelChangeLog::where('created_at', '<', $date)
                    ->limit(1000)
                    ->delete();

                $deletedCount += $deleted;

                if ($deleted > 0) {
                    $this->info("Deleted {$deleted} records (Total: {$deletedCount})");
                }
            } while ($deleted > 0);

            // Unlock tables
            DB::statement('UNLOCK TABLES');

            $this->info("Table unlocked.");
            $this->line("Job done [OK] - Total records deleted: {$deletedCount}\n");
        } catch (\Exception $e) {
            // Make sure to unlock tables even if an error occurs
            DB::statement('UNLOCK TABLES');

            $this->error("Error occurred: " . $e->getMessage());
            $this->error("Tables have been unlocked.");

            return 1;
        }

        return 0;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('date', InputArgument::REQUIRED, 'The date (format: YYYY-MM-DD)')
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array();
    }
}
