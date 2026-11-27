<?php

namespace App\Services\Ai\Tools;

class BuscarGuiaTool extends AbstractTool
{
    public static function guides(): array
    {
        static $guides = null;

        return $guides ?? ($guides = require resource_path('ai/guides.php'));
    }

    /**
     * Permiso que necesita cada guia: [modulo, modo]. Las guias de crear o
     * editar piden "edit". Sin permiso, el asistente no explica los pasos:
     * dice que hace falta pedir acceso al administrador.
     */
    const PERMS = [
        'editar_vehiculo'    => ['devices', 'edit'],
        'tipos_vehiculo'     => ['devices', 'edit'],
        'historial'          => ['history', 'view'],
        'reportes'           => ['reports', 'view'],
        'alertas'            => ['alerts', 'edit'],
        'eventos'            => ['events', 'view'],
        'geocercas'          => ['geofences', 'edit'],
        'rutas'              => ['routes', 'edit'],
        'programacion_rutas' => ['routes', 'edit'],
        'pois'               => ['poi', 'edit'],
        'comandos'           => ['send_command', 'view'],
        'compartir'          => ['sharing', 'view'],
        'mantenimiento'      => ['maintenance', 'edit'],
        'conductores'        => ['drivers', 'view'],
        'gastos'             => ['device_expenses', 'view'],
        'tareas'             => ['tasks', 'view'],
        'operaciones'        => ['operations', 'view'],
        'seguridad'          => ['security', 'view'],
        'camara'             => ['camera', 'view'],
        'chat'               => ['chat', 'view'],
    ];

    private function permitted(string $key): bool
    {
        if (!$this->user || !isset(self::PERMS[$key])) {
            return true;
        }

        [$module, $mode] = self::PERMS[$key];

        try {
            return (bool) $this->user->perm($module, $mode);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Titulos de las guias que este usuario NO tiene permitidas (para el contexto). */
    public function denied(): array
    {
        $titles = [];
        foreach (self::guides() as $key => $guide) {
            if (!$this->permitted($key)) {
                $titles[] = $guide['titulo'];
            }
        }

        return $titles;
    }

    public function name(): string
    {
        return 'buscar_guia';
    }

    public function description(): string
    {
        $topics = [];
        foreach (self::guides() as $key => $guide) {
            if ($this->permitted($key)) {
                $topics[] = "$key ({$guide['titulo']})";
            }
        }

        return 'Devuelve la guia oficial de uso de la plataforma para un tema: pasos, errores comunes y la '
            . 'pantalla relacionada. Usala ante preguntas de "como hago...". Temas: ' . implode('; ', $topics) . '.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tema' => [
                    'type' => 'string',
                    'description' => 'Clave del tema o palabras de la pregunta del usuario.',
                ],
            ],
            'required' => ['tema'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        $guide = $this->find($input['tema'] ?? '');

        return 'Guia · ' . ($guide ? $guide['titulo'] : ($input['tema'] ?? ''));
    }

    protected function run(array $input): array
    {
        $key = $this->findKey($input['tema']);
        $guide = $key !== null ? self::guides()[$key] : null;

        if ($guide && !$this->permitted($key)) {
            return [
                'encontrada' => true,
                'permitido' => false,
                'titulo' => $guide['titulo'],
                'mensaje' => 'El usuario no tiene permiso para esto en su cuenta. No le expliques los pasos, no le '
                    . 'ofrezcas botones ni atajos para hacerlo y no sugieras otra forma de lograrlo: dile que necesita '
                    . 'que el administrador de su cuenta le de acceso.',
            ];
        }

        if (!$guide) {
            return [
                'encontrada' => false,
                'temas_disponibles' => array_values(array_map(function ($g) {
                    return $g['titulo'];
                }, array_filter(self::guides(), function ($k) {
                    return $this->permitted($k);
                }, ARRAY_FILTER_USE_KEY))),
            ];
        }

        return [
            'encontrada' => true,
            'titulo' => $guide['titulo'],
            'pasos' => $guide['pasos'],
            'errores_comunes' => $guide['errores'],
            'pantalla' => $guide['pantalla'],
            'video' => $guide['video'] ?? null,
        ];
    }

    private function find(string $topic): ?array
    {
        $key = $this->findKey($topic);

        return $key !== null ? self::guides()[$key] : null;
    }

    private function findKey(string $topic): ?string
    {
        $guides = self::guides();
        $topic = mb_strtolower(trim($topic));

        if (isset($guides[$topic])) {
            return $topic;
        }

        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $this->plain($topic)), function ($w) {
            return mb_strlen($w) > 2;
        });

        $best = null;
        $bestScore = 0;

        foreach ($guides as $key => $guide) {
            $haystack = $this->plain($key . ' ' . $guide['titulo'] . ' ' . $guide['palabras']);
            $score = 0;

            foreach ($words as $word) {
                if (mb_strpos($haystack, $word) !== false) {
                    // La palabra en el titulo o la clave pesa mas que en las palabras clave.
                    $score += mb_strpos($this->plain($key . ' ' . $guide['titulo']), $word) !== false ? 3 : 2;
                } elseif (mb_strlen($word) >= 5 && mb_strpos($haystack, mb_substr($word, 0, 4)) !== false) {
                    // Raiz de la palabra: "edito" encuentra "editar", "programo" "programar".
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function plain(string $text): string
    {
        $text = mb_strtolower($text);

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }
}
