<?php namespace App\Console\Commands\Tracker;

use Illuminate\Console\Command;
use Tobuli\Helpers\Tracker;
use Illuminate\Support\Facades\DB;

class RestartCommand extends Command {
	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'tracker:restart';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Tracker service restart.';

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
		
		$date = date('Y-m-d H:i:s', strtotime('-1 days'));
		DB::statement("DELETE FROM sms_events_queue WHERE created_at < '{$date}'");

		$tracker = new Tracker();

		try {
		    $tracker->restart();

		    if ( ! $tracker->status())
                $tracker->restartRemote();

            $this->line('Ok');
        } catch (\Exception $exception) {
		    $this->error($exception->getMessage());
        }
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
