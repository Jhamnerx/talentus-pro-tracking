<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;
use Carbon\Carbon;
use Formatter;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;

/**
 * Herramienta del agente. Todas son de solo lectura y trabajan siempre con
 * los permisos del usuario conectado ($user->devices()).
 */
abstract class AbstractTool
{
    /** @var User */
    protected $user;

    /** Acciones para el panel (botones) generadas por la herramienta. */
    protected $actions = [];

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function schema(): array;

    /**
     * @return array datos compactos que se devuelven a Claude
     */
    abstract protected function run(array $input): array;

    /** Linea "Consulte ..." que ve el usuario. */
    abstract public function label(array $input): string;

    public function setUser(User $user)
    {
        $this->user = $user;

        return $this;
    }

    public function handle(array $input): array
    {
        $this->actions = [];
        $this->validate($input);

        return $this->run($input);
    }

    public function getActions(): array
    {
        return $this->actions;
    }

    public function definition(): array
    {
        return [
            'name'                  => $this->name(),
            'description'           => $this->description(),
            'input_schema'          => $this->schema(),
            'eager_input_streaming' => true,
        ];
    }

    /**
     * Validacion basica contra el esquema: obligatorios y tipos simples.
     * Con eager_input_streaming la API no valida el input, lo hacemos aqui.
     */
    protected function validate(array $input)
    {
        $schema = $this->schema();

        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
                throw new ToolException("Falta el parametro obligatorio '$field'.");
            }
        }

        foreach ($schema['properties'] ?? [] as $field => $def) {
            if (!array_key_exists($field, $input) || is_null($input[$field])) {
                continue;
            }

            $value = $input[$field];
            $type = $def['type'] ?? null;

            $ok = true;
            switch ($type) {
                case 'string':  $ok = is_string($value); break;
                case 'integer': $ok = is_int($value) || (is_numeric($value) && (int) $value == $value); break;
                case 'number':  $ok = is_numeric($value); break;
                case 'boolean': $ok = is_bool($value); break;
                case 'array':   $ok = is_array($value); break;
            }

            if (!$ok) {
                throw new ToolException("El parametro '$field' debe ser de tipo $type.");
            }

            if (isset($def['enum']) && !in_array($value, $def['enum'], true)) {
                throw new ToolException("Valor no valido para '$field'. Opciones: " . implode(', ', $def['enum']));
            }
        }
    }

    /* ------------------------------------------------------------------
     | Esquemas comunes
     * ------------------------------------------------------------------ */

    protected function unitsProperty(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Unidades a consultar: nombres, placas, IMEI, IDs o nombres de grupo. '
                . 'Usa ["todas"] para toda la flota. Si el usuario no indica unidades, usa ["todas"].',
        ];
    }

    protected function periodProperties(): array
    {
        return [
            'desde' => [
                'type' => 'string',
                'description' => 'Inicio del periodo en hora local del usuario, formato "YYYY-MM-DD HH:MM".',
            ],
            'hasta' => [
                'type' => 'string',
                'description' => 'Fin del periodo en hora local del usuario, formato "YYYY-MM-DD HH:MM".',
            ],
        ];
    }

    /* ------------------------------------------------------------------
     | Unidades
     * ------------------------------------------------------------------ */

    protected function devicesQuery()
    {
        return $this->user->devices()
            ->with('sensors')
            ->when(!$this->user->isAdmin(), function ($query) {
                return $query->unexpired();
            });
    }

    /**
     * Resuelve los textos del usuario a IDs de sus unidades.
     *
     * @return int[]
     */
    protected function resolveDeviceIds($units, bool $allowAll = true): array
    {
        $units = array_values(array_filter(array_map('trim', (array) $units), 'strlen'));

        if (empty($units)) {
            $units = ['todas'];
        }

        $all = ['todas', 'todos', 'all', 'flota', 'toda la flota'];

        if (count(array_intersect(array_map('mb_strtolower', $units), $all))) {
            if (!$allowAll) {
                throw new ToolException('Indica una unidad especifica.');
            }

            $ids = $this->user->devices()->pluck('devices.id')->all();
        } else {
            $ids = [];
            $notFound = [];

            foreach ($units as $text) {
                $found = $this->matchDevices($text);

                if (empty($found)) {
                    $notFound[] = $text;
                }

                $ids = array_merge($ids, $found);
            }

            if ($notFound && empty($ids)) {
                throw new ToolException('No encontre unidades que coincidan con: ' . implode(', ', $notFound)
                    . '. Usa buscar_unidades para ver los nombres exactos.');
            }
        }

        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            throw new ToolException('El usuario no tiene unidades asignadas.');
        }

        $max = (int) config('ai.max_devices', 100);
        if (count($ids) > $max) {
            throw new ToolException("La consulta abarca " . count($ids) . " unidades y el maximo es $max. "
                . 'Pide al usuario que la acote a un grupo o a unidades concretas.');
        }

        return $ids;
    }

    protected function matchDevices(string $text): array
    {
        $query = $this->user->devices();

        if (ctype_digit($text)) {
            $ids = (clone $query)->where(function ($q) use ($text) {
                $q->where('devices.id', (int) $text)->orWhere('devices.imei', $text);
            })->pluck('devices.id')->all();

            if ($ids) {
                return $ids;
            }
        }

        $exact = (clone $query)->where(function ($q) use ($text) {
            $q->where('devices.name', $text)
                ->orWhere('devices.plate_number', $text)
                ->orWhere('devices.imei', $text)
                ->orWhere('devices.registration_number', $text);
        })->pluck('devices.id')->all();

        if ($exact) {
            return $exact;
        }

        // Grupo del usuario.
        $groupIds = \DB::table('device_groups')
            ->where('user_id', $this->user->id)
            ->where('title', 'like', $text)
            ->pluck('id')->all();

        if ($groupIds) {
            return \DB::table('user_device_pivot')
                ->where('user_id', $this->user->id)
                ->whereIn('group_id', $groupIds)
                ->pluck('device_id')->all();
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $text) . '%';

        $ids = (clone $query)->where(function ($q) use ($like) {
            $q->where('devices.name', 'like', $like)
                ->orWhere('devices.plate_number', 'like', $like)
                ->orWhere('devices.imei', 'like', $like);
        })->limit(50)->pluck('devices.id')->all();

        if ($ids) {
            return $ids;
        }

        // Placa escrita sin guion ni espacios ("t6g611" = "T6G-611"): se
        // compara solo letras y numeros, sin mayusculas.
        $plain = preg_replace('/[^a-z0-9]/', '', mb_strtolower($text));
        if (strlen($plain) < 3) {
            return [];
        }

        $strip = function (string $column) {
            return "REPLACE(REPLACE(REPLACE(REPLACE(LOWER($column), '-', ''), ' ', ''), '.', ''), '_', '')";
        };
        $plainLike = '%' . $plain . '%';

        return (clone $query)->where(function ($q) use ($strip, $plainLike) {
            $q->whereRaw($strip('devices.name') . ' LIKE ?', [$plainLike])
                ->orWhereRaw($strip('devices.plate_number') . ' LIKE ?', [$plainLike])
                ->orWhereRaw($strip('devices.registration_number') . ' LIKE ?', [$plainLike]);
        })->limit(50)->pluck('devices.id')->all();
    }

    /* ------------------------------------------------------------------
     | Periodo
     * ------------------------------------------------------------------ */

    /**
     * @return array [desde_local, hasta_local, desde_utc, hasta_utc]
     */
    protected function parsePeriod(array $input): array
    {
        $from = $this->parseLocal($input['desde'] ?? null, 'desde');
        $to = $this->parseLocal($input['hasta'] ?? null, 'hasta');

        if ($to->lt($from)) {
            throw new ToolException("'hasta' es anterior a 'desde'.");
        }

        $maxDays = (int) config('ai.max_range_days', 31);
        if ($from->diffInMinutes($to) > $maxDays * 1440) {
            throw new ToolException("El periodo maximo por consulta es de $maxDays dias. "
                . 'Pide al usuario un rango mas corto o divide la consulta.');
        }

        $fromLocal = $from->format('Y-m-d H:i:s');
        $toLocal = $to->format('Y-m-d H:i:s');

        return [
            $fromLocal,
            $toLocal,
            Formatter::time()->reverse($fromLocal),
            Formatter::time()->reverse($toLocal),
        ];
    }

    private function parseLocal($value, string $field): Carbon
    {
        if (!$value) {
            throw new ToolException("Falta '$field'. Pregunta al usuario el periodo.");
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            throw new ToolException("Fecha no valida en '$field': $value. Usa \"YYYY-MM-DD HH:MM\".");
        }
    }

    protected function periodLabel(array $input): string
    {
        $from = substr($input['desde'] ?? '', 0, 16);
        $to = substr($input['hasta'] ?? '', 0, 16);

        if (!$from) {
            return '';
        }

        $dFrom = substr($from, 0, 10);
        $dTo = substr($to, 0, 10);

        if ($dFrom === $dTo) {
            return Carbon::parse($dFrom)->format('d/m/Y');
        }

        return Carbon::parse($dFrom)->format('d/m') . '–' . Carbon::parse($dTo)->format('d/m/Y');
    }

    protected function unitsLabel(array $input): string
    {
        $units = (array) ($input['unidades'] ?? ['todas']);

        if (empty($units) || in_array(mb_strtolower($units[0] ?? ''), ['todas', 'todos', 'all'])) {
            return 'toda la flota';
        }

        return count($units) === 1 ? $units[0] : count($units) . ' unidades';
    }

    /* ------------------------------------------------------------------
     | Formato
     * ------------------------------------------------------------------ */

    protected function localTime($utc): ?string
    {
        if (empty($utc) || substr($utc, 0, 4) === '0000') {
            return null;
        }

        return Formatter::time()->convert($utc, 'Y-m-d H:i');
    }

    protected function duration($seconds): string
    {
        return Formatter::duration()->human((int) $seconds);
    }

    protected function speed($kph): string
    {
        return Formatter::speed()->human(round((float) $kph));
    }

    protected function distance($km): string
    {
        return Formatter::distance()->human(round((float) $km, 1));
    }

    protected function coords($lat, $lng): ?string
    {
        if (empty($lat) && empty($lng)) {
            return null;
        }

        return round($lat, 5) . ',' . round($lng, 5);
    }

    protected function deviceLabel(Device $device): string
    {
        $plate = trim((string) $device->plate_number);

        return $plate && stripos($device->name, $plate) === false
            ? "{$device->name} ($plate)"
            : (string) $device->name;
    }
}
