<?php namespace Tobuli\Entities;

use Carbon\Carbon;
use Tobuli\Traits\ChangeLogs;
use Tobuli\Traits\DisplayTrait;

class Report extends AbstractEntity implements DisplayInterface
{
    use ChangeLogs;
    use DisplayTrait;

    public static string $displayField = 'title';

	protected $table = 'reports';

    protected $fillable = [
        'user_id',
        'title',
        'type',
        'format',
        'show_addresses',
        'zones_instead',
        'stops',
        'speed_limit',
        'daily',
        'daily_time',
        'weekly',
        'weekly_time',
        'weekly_day',
        'monthly',
        'monthly_time',
        'monthly_day',
        'period_weekdays',
        'period_time_from',
        'period_time_to',
        'email',
        'ftp',
        'weekly_email_sent',
        'daily_email_sent',
        'monthly_email_sent',
        'period',
        'from_format',
        'to_format',
        'parameters',
        'metas',
        'skip_blank_results',
        'for_all_user_devices',
    ];

    protected $appends = array('from_formated', 'to_formated');

    protected $casts = [
        'parameters' => 'array',
        'metas' => 'array',
        'period_weekdays' => 'array',
    ];

    public $timestamps = false;

    public function user() {
        return $this->belongsTo('Tobuli\Entities\User', 'user_id', 'id');
    }

    public function devices() {
        return $this->belongsToMany('Tobuli\Entities\Device', 'report_device_pivot', 'report_id', 'device_id');
    }
    
    public function geofences() {
        return $this->belongsToMany('Tobuli\Entities\Geofence', 'report_geofence_pivot', 'report_id', 'geofence_id');
    }

    public function pois() {
        return $this->belongsToMany('Tobuli\Entities\Poi', 'report_poi_pivot', 'report_id', 'poi_id');
    }

    /**
     * Resuelve un periodo relativo contra el dia de HOY DEL USUARIO.
     *
     * El periodo no se guarda como fecha absoluta sino como desplazamiento
     * ("-195 days 00:00"). ReportModalHelper lo calcula contra el dia del
     * usuario, y ReportsDailyCommand lo resuelve igual al ejecutar el
     * reporte programado. Los accessors eran la pieza descolocada: usaban
     * Carbon::parse() a secas, o sea la zona de la aplicacion (UTC).
     *
     * Con un usuario en UTC-5, a partir de las 19:00 hora local el
     * calendario UTC ya ha cambiado de dia, asi que al reabrir un reporte
     * guardado TODO el periodo aparecia corrido una jornada. Un reporte
     * programado cubria el dia equivocado.
     *
     * @param  string $formato   p. ej. "-195 days 00:00"
     * @param  string $respaldo  hora a usar si no hay formato guardado
     */
    private function resuelveRelativo($formato, $respaldo)
    {
        if (empty($formato)) {
            return Carbon::parse($respaldo)->format('Y-m-d H:i');
        }

        try {
            $hoyUsuario = strtotime(date('Y-m-d', \Formatter::time()->now()));
        } catch (\Throwable $e) {
            // sin usuario en contexto (consola, colas): la zona de la app
            $hoyUsuario = strtotime(date('Y-m-d'));
        }

        $ts = strtotime($formato, $hoyUsuario);

        return $ts ? date('Y-m-d H:i', $ts) : Carbon::parse($respaldo)->format('Y-m-d H:i');
    }

    public function getFromFormatedAttribute()
    {
        return $this->resuelveRelativo($this->from_format, '00:00:00');
    }

    public function getFormatAttribute($value)
    {
        if (empty($value))
            $value = 'html';

        return $value;
    }

    public function getToFormatedAttribute()
    {
        return $this->resuelveRelativo($this->to_format, '23:45:00');
    }

    public function isSchedule()
    {
        return $this->daily || $this->weekly || $this->monthly;
    }
}
