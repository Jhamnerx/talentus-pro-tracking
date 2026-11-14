<?php namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Tobuli\Entities\DeviceVideoDownload;

class CleanVideoDownloadsCommand extends Command
{
    protected $name = 'video:clean-downloads';

    protected $description = 'Delete video downloads older than video.downloads_days and mark stuck ones as failed';

    public function handle()
    {
        $limit = Carbon::now()->subDays(config('video.downloads_days', 7));

        // delete() uno por uno para que el modelo borre también el archivo.
        $deleted = 0;
        DeviceVideoDownload::where('created_at', '<', $limit)->get()->each(function ($download) use (&$deleted) {
            $download->delete();
            $deleted++;
        });

        // Un worker caído deja descargas a medias; ninguna dura más que el timeout del job.
        $stuck = DeviceVideoDownload::whereIn('status', [DeviceVideoDownload::STATUS_PENDING, DeviceVideoDownload::STATUS_RECORDING])
            ->where('updated_at', '<', Carbon::now()->subHours(2))
            ->update(['status' => DeviceVideoDownload::STATUS_FAILED, 'error' => trans('front.video_err_platform')]);

        $this->line("Deleted {$deleted}, failed {$stuck}");

        return 0;
    }
}
