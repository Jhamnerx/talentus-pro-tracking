<?php namespace Tobuli\Entities;

use Illuminate\Support\Facades\Storage;

/**
 * Grabación JT1078 descargada al servidor (ver App\Jobs\RecordVideoDownload).
 */
class DeviceVideoDownload extends AbstractEntity
{
    const STATUS_PENDING = 'pending';
    const STATUS_RECORDING = 'recording';
    const STATUS_DONE = 'done';
    const STATUS_FAILED = 'failed';

    /** playback: se graba la reproducción remota; ftp: el equipo sube el archivo original (0x9206). */
    const METHOD_PLAYBACK = 'playback';
    const METHOD_FTP = 'ftp';

    /** Duración máxima de una descarga (s): el equipo la transmite en tiempo real. */
    const MAX_DURATION = 1800;

    protected $table = 'device_video_downloads';

    protected $fillable = [
        'device_id',
        'user_id',
        'channel',
        'method',
        'upload_path',
        'start_at',
        'end_at',
        'status',
        'path',
        'size',
        'duration',
        'error',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    public $timestamps = true;

    protected static function boot()
    {
        parent::boot();

        static::deleted(function (DeviceVideoDownload $download) {
            if ($download->path) {
                Storage::disk(config('video.downloads_disk'))->delete($download->path);
            }
        });
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RECORDING]);
    }
}
