<?php namespace App\Console\Commands\Tracker;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Services\TraccarDeviceModelService;

class SyncDeviceModelsCommand extends Command {

	protected $signature = 'tracker:sync-models {--imei= : Solo este IMEI}';

	protected $description = 'Copia "Modelo del dispositivo" de cada equipo a tc_devices.model de Traccar.';

	public function handle(TraccarDeviceModelService $service)
	{
		$query = Device::select(['id', 'imei', 'device_model']);

		if ($imei = $this->option('imei')) {
			$query->where('imei', $imei);
		}

		$counts = ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0];

		$query->chunk(200, function ($devices) use ($service, &$counts) {
			foreach ($devices as $device) {
				try {
					$result = $service->sync($device);
				} catch (\RuntimeException $e) {
					$counts['failed']++;
					$this->error($e->getMessage());
					continue;
				}

				if ($result === true) {
					$counts['updated']++;
					$this->line("{$device->imei} -> " . ($service->resolveModel($device) ?? '(vacío)'));
				} elseif ($result === false) {
					$counts['unchanged']++;
				} else {
					$counts['missing']++;
				}
			}
		});

		$this->info("Actualizados: {$counts['updated']} · Sin cambios: {$counts['unchanged']}"
			. " · No existen en Traccar: {$counts['missing']} · Errores: {$counts['failed']}");

		return $counts['failed'] ? 1 : 0;
	}
}
