<?php

namespace App\Console\Commands;

use App\Services\Ai\AiAgent;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\ToolException;
use Formatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\AiConversation;
use Tobuli\Entities\User;

/**
 * Prueba comparativa de modelos para Talentus IA CHAT.
 *
 * Hace las mismas preguntas (resources/ai/compare-questions.php) con cada
 * modelo, como el usuario indicado y con sus permisos, y escribe un informe
 * con las respuestas lado a lado, las herramientas usadas, el costo y el
 * tiempo. Cada pregunta corre dentro de una transaccion que se deshace: no
 * quedan conversaciones ni consumo en la base. Las acciones solo preparan su
 * tarjeta; nunca se confirman.
 *
 *   php artisan ai:compare --user=cliente@correo.com
 *   php artisan ai:compare --user=12 --models=claude-haiku-5-5 --only=excesos-ayer,paradas
 *   php artisan ai:compare --user=12 --check-tools     (sin API: prueba las herramientas)
 *
 * Cuesta dinero: unas 30 preguntas con 2 modelos salen alrededor de USD 1,5.
 */
class AiCompareCommand extends Command
{
    protected $signature = 'ai:compare
        {--user= : id o correo del usuario con cuyos datos y permisos se pregunta}
        {--models=claude-sonnet-5-5,claude-haiku-5-5 : modelos separados por coma}
        {--effort= : esfuerzo para todos los modelos (low|medium|high); vacio = el de Admin}
        {--only= : ids de preguntas separados por coma}
        {--limit=0 : maximo de preguntas}
        {--unit= : nombre de unidad para {unidad}; vacio = la primera del usuario}
        {--check-tools : no llama a la API: ejecuta cada herramienta con datos de ejemplo}
        {--show-tools : no llama a la API: muestra las herramientas, atajos y pantallas que ve el usuario}';

    protected $description = 'Talentus IA CHAT: compara modelos con un banco de preguntas (o revisa las herramientas sin API)';

    public function handle()
    {
        $user = $this->findUser($this->option('user'));
        if (!$user) {
            $this->error('Indica un usuario existente con --user=<id o correo>.');
            return 1;
        }

        Formatter::byUser($user);
        $unit = $this->option('unit') ?: $this->firstDeviceName($user);

        if ($this->option('show-tools')) {
            return $this->showTools($user);
        }

        if ($this->option('check-tools')) {
            return $this->checkTools($user, $unit);
        }

        if (!AiSettings::isConfiguredFor($user)) {
            $this->error('El asistente no esta configurado: falta la clave de la API en Admin > Talentus IA CHAT.');
            return 1;
        }

        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));
        foreach ($models as $model) {
            if (!AiSettings::isKnownModel($model)) {
                $this->error("Modelo sin precio en config/ai.php: $model");
                return 1;
            }
        }

        $questions = $this->questions($unit);
        if (!$questions) {
            $this->error('No hay preguntas que coincidan.');
            return 1;
        }

        $this->info(sprintf('Usuario %s · %d preguntas · modelos: %s · unidad de ejemplo: %s',
            $user->email, count($questions), implode(', ', $models), $unit ?: '-'));
        $this->warn('Esta prueba usa la API y tiene costo. Ctrl+C para cancelar.');

        $results = [];
        foreach ($questions as $q) {
            foreach ($models as $model) {
                $results[$q['id']][$model] = $this->runQuestion($user, $model, $q['pregunta']);
                $r = $results[$q['id']][$model];
                $this->line(sprintf('%-20s %-20s %-8s USD %.4f  %5.1f s  %s', $q['id'], $model, $r['status'],
                    $r['cost'], $r['seconds'], implode(',', $r['tools']) ?: '-'));
            }
        }

        $this->printTotals($models, $questions, $results);
        $path = $this->writeReport($user, $models, $questions, $results);
        $this->info("Informe: $path");

        return 0;
    }

    /** Una pregunta con un modelo, dentro de una transaccion que se deshace. */
    private function runQuestion(User $user, string $model, string $question): array
    {
        $text = '';
        $actions = [];
        $started = microtime(true);
        $out = ['status' => 'error', 'cost' => 0.0, 'tools' => [], 'api_calls' => 0, 'text' => '', 'actions' => [], 'error' => null];

        DB::beginTransaction();

        try {
            $conversation = AiConversation::create(['user_id' => $user->id, 'title' => 'ai:compare']);
            $agent = (new AiAgent($user))->setModel($model);
            if ($this->option('effort')) {
                $agent->setEffort($this->option('effort'));
            }

            $result = $agent->ask($conversation, $question, ['screen' => 'live'], function ($event, $data) use (&$text, &$actions) {
                if ($event === 'delta') {
                    $text .= $data['text'] ?? '';
                } elseif ($event === 'action') {
                    $actions[] = ($data['type'] ?? '?') . ': ' . ($data['label'] ?? $data['title'] ?? '');
                }
            });

            $out['status'] = $result['status'];
            $out['cost'] = (float) $result['cost_usd'];
            $out['tools'] = array_values(array_unique($result['tools'] ?? []));
            $out['api_calls'] = (int) ($result['api_calls'] ?? 0);
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        } finally {
            DB::rollBack();
        }

        $out['text'] = trim($text);
        $out['actions'] = $actions;
        $out['seconds'] = microtime(true) - $started;

        return $out;
    }

    private function printTotals(array $models, array $questions, array $results)
    {
        $rows = [];
        foreach ($models as $model) {
            $cost = $secs = $hits = $expected = $errors = 0;
            foreach ($questions as $q) {
                $r = $results[$q['id']][$model];
                $cost += $r['cost'];
                $secs += $r['seconds'];
                $errors += $r['status'] === 'ok' ? 0 : 1;
                if ($q['herramientas']) {
                    $expected++;
                    $hits += array_intersect($q['herramientas'], $r['tools']) ? 1 : 0;
                }
            }
            $n = max(1, count($questions));
            $rows[] = [$model, sprintf('%.4f', $cost), sprintf('%.4f', $cost / $n), sprintf('%.1f', $secs / $n),
                $expected ? sprintf('%d/%d', $hits, $expected) : '-', $errors];
        }

        $this->table(['Modelo', 'USD total', 'USD/pregunta', 'Seg/pregunta', 'Herramienta esperada', 'No ok'], $rows);
    }

    private function writeReport(User $user, array $models, array $questions, array $results): string
    {
        $dir = storage_path('app/ai-compare');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $md = ['# Prueba comparativa de modelos · ' . date('Y-m-d H:i'), '',
            'Usuario: ' . $user->email . ' · Modelos: ' . implode(', ', $models)
                . ' · Esfuerzo: ' . ($this->option('effort') ?: config('ai.effort')), '',
            'Revisa cada respuesta: cifras correctas, herramienta adecuada, tono y si pidio lo que faltaba.', ''];

        $csv = [['pregunta', 'modelo', 'estado', 'usd', 'segundos', 'llamadas_api', 'herramientas', 'esperadas', 'acierto']];

        foreach ($questions as $q) {
            $md[] = '## ' . $q['id'] . ': ' . $q['pregunta'];
            $md[] = '';
            if ($q['herramientas']) {
                $md[] = '_Herramientas esperadas: ' . implode(', ', $q['herramientas']) . '_';
            }
            if (!empty($q['nota'])) {
                $md[] = '_Revisar: ' . $q['nota'] . '_';
            }
            $md[] = '';

            foreach ($models as $model) {
                $r = $results[$q['id']][$model];
                $hit = $q['herramientas'] ? (array_intersect($q['herramientas'], $r['tools']) ? 'si' : 'no') : '-';
                $md[] = sprintf('### %s · %s · USD %.4f · %.1f s · herramientas: %s', $model, $r['status'], $r['cost'],
                    $r['seconds'], implode(', ', $r['tools']) ?: 'ninguna');
                $md[] = '';
                $md[] = $r['error'] ? '**Error:** ' . $r['error'] : ($r['text'] !== '' ? $r['text'] : '_(sin texto)_');
                if ($r['actions']) {
                    $md[] = '';
                    $md[] = 'Botones/tarjetas: ' . implode(' · ', $r['actions']);
                }
                $md[] = '';

                $csv[] = [$q['id'], $model, $r['status'], round($r['cost'], 5), round($r['seconds'], 1), $r['api_calls'],
                    implode(' ', $r['tools']), implode(' ', $q['herramientas']), $hit];
            }
        }

        $base = $dir . '/' . date('Ymd-His');
        file_put_contents($base . '.md', implode("\n", $md) . "\n");

        $fh = fopen($base . '.csv', 'w');
        foreach ($csv as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $base . '.md (y .csv)';
    }

    /** Lo que el modelo recibe para este usuario (permisos aplicados). */
    private function showTools(User $user): int
    {
        $rows = [];
        foreach ((new AiToolRegistry($user))->definitions() as $definition) {
            $enum = [];
            foreach (($definition['input_schema']['properties'] ?? []) as $prop) {
                if (!empty($prop['enum'])) {
                    $enum = $prop['enum'];
                }
            }
            $rows[] = [$definition['name'], $enum ? implode(', ', $enum) : '-'];
        }

        $this->table(['Herramienta', 'Opciones permitidas'], $rows);

        return 0;
    }

    /** Ejecuta cada herramienta con datos de ejemplo, sin llamar a la API. */
    private function checkTools(User $user, $unit): int
    {
        $registry = new AiToolRegistry($user);
        $definitions = $registry->definitions();
        $rows = [];
        $failed = 0;

        foreach ($definitions as $definition) {
            $name = $definition['name'];
            $input = $this->sampleInput($definition['input_schema'] ?? [], $unit);
            $started = microtime(true);

            try {
                $data = $registry->get($name)->handle($input);
                $status = 'ok';
                $detail = mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 90);
            } catch (ToolException $e) {
                $status = 'aviso';
                $detail = $e->getMessage();
            } catch (\Throwable $e) {
                $status = 'ERROR';
                $detail = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                $failed++;
            }

            $rows[] = [$name, $status, sprintf('%.1f', microtime(true) - $started), mb_substr($detail, 0, 110)];
        }

        $this->table(['Herramienta', 'Estado', 'Seg', 'Resultado'], $rows);
        $this->line('"aviso" = la herramienta respondio un error controlado (permiso, sin datos, parametro); el modelo lo explicaria al usuario.');

        return $failed ? 1 : 0;
    }

    private function sampleInput(array $schema, $unit): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $weekAgo = date('Y-m-d', strtotime('-8 days'));
        $values = [
            'desde' => "$yesterday 00:00", 'hasta' => "$yesterday 23:59",
            'desde_a' => "$yesterday 00:00", 'hasta_a' => "$yesterday 23:59",
            'desde_b' => "$weekAgo 00:00", 'hasta_b' => "$weekAgo 23:59",
            'fecha' => $yesterday, 'limite' => 80, 'meses' => 1,
            'unidad' => $unit, 'unidades' => [$unit], 'tema' => 'historial',
            'nombre' => 'Prueba IA', 'nuevo_nombre' => $unit, 'consulta' => $unit, 'texto' => $unit,
        ];

        $input = [];
        foreach ((array) ($schema['required'] ?? []) as $field) {
            $prop = $schema['properties'][$field] ?? [];

            if (!empty($prop['enum'])) {
                $input[$field] = $prop['enum'][0];
            } elseif (array_key_exists($field, $values)) {
                $input[$field] = $values[$field];
            } elseif (($prop['type'] ?? null) === 'integer' || ($prop['type'] ?? null) === 'number') {
                $input[$field] = 1;
            } elseif (($prop['type'] ?? null) === 'array') {
                $input[$field] = [$unit];
            } else {
                $input[$field] = (string) $unit;
            }
        }

        return $input;
    }

    private function questions($unit): array
    {
        $all = require resource_path('ai/compare-questions.php');
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $list = array_values(array_filter($all, function ($q) use ($only) {
            return !$only || in_array($q['id'], $only, true);
        }));

        if ((int) $this->option('limit') > 0) {
            $list = array_slice($list, 0, (int) $this->option('limit'));
        }

        return array_map(function ($q) use ($unit) {
            $plate = strtolower(preg_replace('/[^A-Za-z0-9]/', '', strtok((string) $unit, ' ') ?: ''));
            $q['pregunta'] = str_replace(['{unidad}', '{placa}'], [$unit ?: 'mi unidad', $plate ?: 'mi unidad'], $q['pregunta']);
            $q['herramientas'] = $q['herramientas'] ?? [];

            return $q;
        }, $list);
    }

    private function findUser($value)
    {
        if (!$value) {
            return null;
        }

        return is_numeric($value) ? User::find((int) $value) : User::where('email', $value)->first();
    }

    private function firstDeviceName(User $user)
    {
        $device = $user->devices()->orderBy('devices.name')->first(['devices.id', 'devices.name']);

        return $device ? trim($device->name) : null;
    }
}
