<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools;
use App\Services\Ai\Tools\AbstractTool;
use Tobuli\Entities\User;

class AiToolRegistry
{
    /** Orden fijo: cambiarlo invalida el cache de prompts. */
    const TOOLS = [
        Tools\BuscarUnidadesTool::class,
        Tools\EstadoUnidadesTool::class,
        Tools\UbicacionActualTool::class,
        Tools\DiagnosticoOfflineTool::class,
        Tools\ExcesosVelocidadTool::class,
        Tools\ParadasTool::class,
        Tools\RecorridosParadasTool::class,
        Tools\ResumenGeneralTool::class,
        Tools\OperacionDiariaTool::class,
        Tools\AlertasEventosTool::class,
        Tools\GeocercasTool::class,
        Tools\CombustibleTool::class,
        Tools\HorasMotorTool::class,
        Tools\OdometroTool::class,
        Tools\ConductoresTool::class,
        Tools\MantenimientoTool::class,
        Tools\VencimientosTool::class,
        Tools\CompararPeriodosTool::class,
        Tools\ExportarReporteTool::class,
        Tools\ExportarRecorridoTool::class,
        Tools\VerEnMapaTool::class,
        Tools\BuscarGuiaTool::class,
        Tools\AbrirPantallaTool::class,
        Tools\AtajoUiTool::class,
    ];

    /**
     * Acciones que cambian datos: solo con el permiso ai_assistant (edit) y
     * siempre con confirmacion del usuario.
     */
    const ACTION_TOOLS = [
        Tools\Actions\ProgramarReporteTool::class,
        Tools\Actions\CrearAlertaTool::class,
        Tools\Actions\EnviarComandoTool::class,
        Tools\Actions\RenombrarUnidadTool::class,
        Tools\Actions\RenovarLicenciaTool::class,
    ];

    /** @var AbstractTool[] */
    private $tools = [];

    public function __construct(User $user)
    {
        $classes = self::TOOLS;

        // Cada accion solo llega al modelo si el usuario tiene su permiso
        // (ademas de ai_assistant edit): sin el, ni la propone ni la explica.
        if (self::canAct($user)) {
            foreach (self::ACTION_TOOLS as $class) {
                try {
                    $allowed = $class::allowed($user, []);
                } catch (\Throwable $e) {
                    $allowed = false;
                }
                if ($allowed) {
                    $classes[] = $class;
                }
            }
        }

        foreach ($classes as $class) {
            /** @var AbstractTool $tool */
            $tool = new $class();
            $tool->setUser($user);
            $this->tools[$tool->name()] = $tool;
        }
    }

    public static function canAct(User $user): bool
    {
        return (bool) $user->perm('ai_assistant', 'edit');
    }

    public function definitions(): array
    {
        return array_values(array_map(function (AbstractTool $tool) {
            return $tool->definition();
        }, $this->tools));
    }

    /**
     * Funciones de la plataforma que esta cuenta no tiene: van en el contexto
     * de cada pregunta para que el modelo no explique como hacerlas.
     */
    public function deniedFeatures(): array
    {
        $out = [];
        foreach (['buscar_guia', 'atajo_ui'] as $name) {
            if (isset($this->tools[$name]) && method_exists($this->tools[$name], 'denied')) {
                $out = array_merge($out, $this->tools[$name]->denied());
            }
        }

        return array_values(array_unique($out));
    }

    public function get(string $name): ?AbstractTool
    {
        return $this->tools[$name] ?? null;
    }
}
