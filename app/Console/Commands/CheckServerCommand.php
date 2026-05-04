<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Console\ProcessManager;
use Tobuli\Helpers\Tracker;
use Tobuli\Services\DatabaseService;

class CheckServerCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'server:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
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
        $tracker = new Tracker();

        $this->processManager = new ProcessManager($this->name, $timeout = 3600, $limit = 1);

        if (! $this->processManager->canProcess()) {
            echo "Cant process \n";
            return -1;
        }

        // Restart Traccar if the service is not running
        if (! $tracker->status()) {
            $tracker->restart();
        }

        $date = date('Y-m-d H:i:s', strtotime('-1 days'));
        DB::statement("DELETE FROM sms_events_queue WHERE created_at < '{$date}'");

        $this->line('Ok');

        return 0;
    }

    protected function getDBS()
    {
        $dbs = [];
        $dbService = new DatabaseService();

        foreach ($dbService->getDatabases() as $database) {
            if (!$database->id)
                continue;

            $sizes = $dbService->getDatabaseSizes($database->id);

            $dbs[] = [
                'active'   => $database->active,
                'driver'   => $database->driver,
                'host'     => $database->host,
                'port'     => $database->port,
                'database' => $database->database,
                'sizes'    => $sizes->values()->toArray() ?? null
            ];
        }

        return $dbs;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array();
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
