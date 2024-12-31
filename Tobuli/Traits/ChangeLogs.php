<?php

namespace Tobuli\Traits;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Traits\LogsActivity;
use Tobuli\Entities\DisplayInterface;
use Tobuli\Entities\ModelChangeLog;

trait ChangeLogs
{
    use LogsActivity;

    public static array $logAttributes;
    public static array $logAttributesToIgnore = ['created_at', 'updated_at'];
    public static bool $ignoreHidden = true;
    public static bool $logFillable;
    public static bool $logUnguarded;
    public static bool $logOnlyDirty = true;
    public static bool $submitEmptyLogs = false;
    public static bool $logPaused = false;

    public ?ModelChangeLog $lastActivity = null;

    public function getLogNameToUse($eventName)
    {
        return $this instanceof DisplayInterface ? $this->getDisplayName() : '';
    }

    protected static function bootLogsActivity()
    {
        static::eventsToBeRecorded()->each(function ($eventName) {
            return static::$eventName(function (Model $model) use ($eventName) {
                $class = get_class($model);

                if (!empty($class::$logPaused)) {
                    return;
                }

                if (! $model->shouldLogEvent($eventName)) {
                    return;
                }

                //tmp
                if (!auth()->user()) {
                    return;
                }

                $description = $model->getDescriptionForEvent($eventName);

                $logName = $model->getLogNameToUse($eventName);

                if ($description == '') {
                    return;
                }

                $attrs = $model->attributeValuesToBeLogged($eventName);

                if ($model->isLogEmpty($attrs) && ! $model->shouldSubmitEmptyLogs()) {
                    return;
                }

                /** @var ModelChangeLog $lastActivity */
                $lastActivity = $model->lastActivity;

                if (!$lastActivity) {
                    $logger = app(ActivityLogger::class)
                        ->useLog($logName)
                        ->performedOn($model)
                        ->withProperties($attrs);

                    if (method_exists($model, 'tapActivity')) {
                        $logger->tap([$model, 'tapActivity'], $eventName);
                    }

                    $model->lastActivity = $logger->log($description);

                    return;
                }

                $properties = $lastActivity->properties;
                $hasNew = isset($properties['attributes']) && isset($attrs['attributes']);
                $hasOld = isset($properties['old']) && isset($attrs['old']);

                if ($hasNew) {
                    $properties['attributes'] = array_merge($properties['attributes'], $attrs['attributes']);
                }

                if ($hasOld) {
                    $properties['old'] = array_merge($attrs['old'], $properties['old']);
                }

                $lastActivity->properties = $properties;
                $lastActivity->save();
            });
        });
    }
}