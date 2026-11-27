<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\AlertasEventosTool;
use App\Services\Ai\Tools\EstadoUnidadesTool;
use App\Services\Ai\Tools\RecorridosParadasTool;
use Carbon\Carbon;
use Formatter;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\User;

/**
 * Funciones del asistente fuera del chat. Ninguna recibe el historial GPS crudo:
 * explicar reporte usa el texto que el usuario ya ve; el resto, los
 * resumenes compactos de las herramientas.
 */
class AiFeatures
{
    const MAX_REPORT_CHARS = 40000;

    const STYLE = 'Responde en espanol neutro, breve y concreto. Usa solo las cifras que aparecen en los datos; '
        . 'no inventes ni estimes. Si los datos no alcanzan para una conclusion, dilo. Cuando un valor sea 0, '
        . 'dilo de forma natural ("no hubo eventos"), sin dobles negaciones ni ceros sueltos.';

    public static function enabled(User $user, string $feature): bool
    {
        return AiSettings::isConfiguredFor($user)
            && (bool) config("ai.features.$feature")
            && $user->perm('ai_assistant', 'view');
    }

    /** "Explicar este reporte": resumen ejecutivo de lo que el usuario esta viendo. */
    public static function explainReport(User $user, string $title, string $text, callable $onText = null): string
    {
        $text = self::clip($text, self::MAX_REPORT_CHARS);

        $system = 'Eres ' . AiSettings::name() . ', analista de flotas GPS de la plataforma TalentusPro. Te doy el contenido de un '
            . 'reporte que el usuario tiene abierto. Escribe: 1) una linea con la conclusion principal y su cifra; '
            . '2) hasta 5 hallazgos en vinetas (unidades destacadas, anomalias, patrones por hora o lugar); '
            . '3) una recomendacion practica si los datos la justifican. Usa **negrita** para unidades y cifras. '
            . self::STYLE;

        return (new AiCompletion())->complete($user, 'explain_report', $system,
            "Reporte: $title\n\n<reporte>\n$text\n</reporte>", null, 'low', 3000, $onText);
    }

    /** Parrafo de conclusiones para el correo de un reporte programado (HTML). */
    public static function reportEmailSummary(User $user, string $title, string $json): ?string
    {
        $system = 'Eres ' . AiSettings::name() . '. Resume en 2 a 4 frases, para el cuerpo de un correo, los datos de un reporte de '
            . 'flota GPS en JSON: total principal, unidad o evento mas destacado y cualquier anomalia. Texto plano, '
            . 'sin titulos ni vinetas. ' . self::STYLE;

        $text = (new AiCompletion())->complete($user, 'report_email', $system,
            "Reporte: $title\n\n" . self::clip($json, self::MAX_REPORT_CHARS), AiSettings::configFor($user)['light_model'], 'low', 800);

        return $text === '' ? null : nl2br(e($text));
    }

    /** Bloque "Resumen del asistente" del dashboard: actividad de ayer y estado actual. Cache 6 h. */
    public static function dashboardSummary(User $user): array
    {
        $key = 'tpai.dashboard.' . $user->id . '.' . date('Y-m-d');

        return Cache::remember($key, now()->addHours(6), function () use ($user) {
            Formatter::byUser($user);
            $data = self::yesterdayData($user, true);

            $system = 'Eres ' . AiSettings::name() . '. Con estos datos de la flota escribe un resumen para el dashboard: maximo 4 '
                . 'vinetas cortas (estado actual, actividad de ayer, eventos destacados y una alerta o '
                . 'recomendacion si corresponde). Usa **negrita** para cifras. ' . self::STYLE;

            $text = (new AiCompletion())->complete($user, 'dashboard', $system,
                json_encode($data, JSON_UNESCAPED_UNICODE), AiSettings::configFor($user)['light_model'], 'low', 800);

            return ['text' => $text, 'generated_at' => Formatter::time()->convert(gmdate('Y-m-d H:i:s'), 'H:i')];
        });
    }

    /** Resumen diario de alertas para el correo (HTML) o null si no hubo eventos. */
    public static function alertsDigest(User $user): ?string
    {
        Formatter::byUser($user);
        $data = self::yesterdayData($user, false);

        if (empty($data['eventos_ayer']['total_eventos'])) {
            return null;
        }

        $system = 'Eres ' . AiSettings::name() . '. Escribe el resumen diario de alertas de una flota GPS para un correo: una frase '
            . 'con el total, luego hasta 5 puntos con los tipos de evento y unidades que mas se repitieron, y '
            . 'que conviene revisar. Texto plano con guiones para los puntos. ' . self::STYLE;

        $text = (new AiCompletion())->complete($user, 'alerts_digest', $system,
            json_encode($data, JSON_UNESCAPED_UNICODE), AiSettings::configFor($user)['light_model'], 'low', 1000);

        return $text === '' ? null : nl2br(e($text));
    }

    /** Datos compactos de ayer (hora local del usuario) usando las herramientas del agente. */
    private static function yesterdayData(User $user, bool $withActivity): array
    {
        $day = Carbon::parse(Formatter::time()->convert(gmdate('Y-m-d H:i:s'), 'Y-m-d'))->subDay()->format('Y-m-d');
        $period = ['desde' => "$day 00:00", 'hasta' => "$day 23:59"];
        $data = ['fecha_ayer' => $day];

        $run = function ($tool, array $input) use ($user) {
            try {
                return $tool->setUser($user)->handle($input);
            } catch (\Throwable $e) {
                return ['no_disponible' => $e->getMessage()];
            }
        };

        $data['estado_actual'] = $run(new EstadoUnidadesTool(), ['estado' => 'todos']);
        $data['eventos_ayer'] = $run(new AlertasEventosTool(), $period);

        if ($withActivity) {
            $data['actividad_ayer'] = $run(new RecorridosParadasTool(), $period);
        }

        return $data;
    }

    private static function clip(string $text, int $max): string
    {
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[...recortado]" : $text;
    }
}
