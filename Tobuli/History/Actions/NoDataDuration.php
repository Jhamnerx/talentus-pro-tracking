<?php

namespace Tobuli\History\Actions;

use Tobuli\History\Stats\Duration as DurationStat;
use Tobuli\History\Stats\StatCount;

/**
 * Tiempo sin datos: suma los huecos entre tramas que superan `gap_seconds`
 * (config, por defecto 90 s). El resto del pipeline (DriveStop, Duration,
 * EngineOnOff) cuenta esos huecos como parada o como marcha; el modulo de
 * Operaciones los quiere aparte para no confundir una falla del equipo con
 * una parada.
 */
class NoDataDuration extends ActionStat
{
    protected $gapSeconds = 90;

    static public function required()
    {
        return [
            AppendDuration::class,
        ];
    }

    public function boot()
    {
        if ($this->history->hasConfig('gap_seconds')) {
            $this->gapSeconds = (int) $this->history->config('gap_seconds');
        }

        $this->registerStat('no_data_duration', (new DurationStat()));
        $this->registerStat('no_data_count', new StatCount());
    }

    public function proccess($position)
    {
        if ($position->duration > $this->gapSeconds) {
            $this->history->applyStat('no_data_duration', $position->duration);
            $this->history->applyStat('no_data_count', 1);
        }
    }
}
