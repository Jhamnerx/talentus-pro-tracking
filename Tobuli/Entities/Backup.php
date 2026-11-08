<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Tobuli\Traits\Searchable;

class Backup extends AbstractEntity
{
    use Searchable;

    protected $fillable = [
        'name',
        'message',
        'details',
        'launcher',
        'destinations',
        'database_bytes',
        'protected',
    ];

    protected $casts = [
        'destinations' => 'array',
        'protected'    => 'boolean',
    ];

    protected array $searchable = [
        'name',
        'message',
        'launcher',
    ];

    public function processes(): HasMany
    {
        return $this->hasMany(BackupProcess::class, 'backup_id');
    }

    public function progressTotal(): int
    {
        return $this->processes()->sum('total');
    }

    public function progressDone(): int
    {
        return $this->processes()->sum('processed') + $this->processes()->sum('skipped');
    }

    public function isCompleted(): bool
    {
        $processes = $this->processes()->get();

        return $processes->count() === $processes->whereNotNull('completed_at')->count();
    }

    public function isRunning(): bool
    {
        return (bool)$this->processes()->whereStatusReserved()->count();
    }

    public const DESTINATION_OK = 'ok';
    public const DESTINATION_PARTIAL = 'partial';
    public const DESTINATION_FAILED = 'failed';

    public function destinationStatus(string $name): ?string
    {
        $state = $this->destinations[$name] ?? null;

        if ($state === null) {
            return null;
        }

        if (empty($state['failed']) && empty($state['disabled'])) {
            return self::DESTINATION_OK;
        }

        return empty($state['uploaded']) ? self::DESTINATION_FAILED : self::DESTINATION_PARTIAL;
    }
}