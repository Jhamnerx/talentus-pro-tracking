<?php

namespace App\Console\Commands;

use App\Console\ProcessManager;
use Illuminate\Console\Command;
use Tobuli\Entities\TraccarDevice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class CleanServerCommand extends Command
{
	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'server:clean';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Command description.';


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
		$this->processManager = new ProcessManager($this->name, $timeout = 3600, $limit = 1);

		if (!$this->processManager->canProcess()) {
			echo "Cant process \n";
			return -1;
		}

		$date = $this->argument('date') . ' 00:00:00';

		$devices = TraccarDevice::orderBy('id', 'asc')->get();
		$all = count($devices);
		$i = 1;

		foreach ($devices as $device) {
			try {
				$device->positions()->where('time', '<', $date)->delete();
			} catch (\Exception $e) {
			}

			$this->line("CLEAN TABLES ({$i}/{$all})\n");
			$i++;
		}

		$server = File::exists('/etc/ser') ? true : false;
		if (!$server) {
			Artisan::call('down');
		}
		Log::info("colocar el servidor en mantenimiento");

		$this->line("Job done[OK]\n");

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
			array('date', InputArgument::REQUIRED, 'The date')
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
