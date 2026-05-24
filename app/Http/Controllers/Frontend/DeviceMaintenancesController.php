<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Jobs\PushMaintenanceToTalentus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tobuli\Entities\Device;
use Tobuli\Entities\DeviceMaintenance;

class DeviceMaintenancesController extends Controller
{
    /**
     * Devuelve la lista de mantenimientos de un dispositivo (AJAX / view).
     */
    public function index(int $device_id): JsonResponse
    {
        /** @var \Tobuli\Entities\User $user */
        $user = auth()->user();

        $device = Device::findOrFail($device_id);

        if (! $user->can('edit', $device)) {
            return response()->json(['status' => 0, 'message' => 'No tienes permisos.'], 403);
        }

        $items = $device->maintenances()
            ->with('user:id,email')
            ->get()
            ->map(fn(DeviceMaintenance $m) => [
                'id'          => $m->id,
                'tipo'        => $m->tipo,
                'tipo_label'  => $m->tipo_label,
                'badge_class' => $m->tipo_badge_class,
                'fecha'       => $m->fecha->format('d/m/Y'),
                'motivo'      => $m->motivo,
                'notas'       => $m->notas,
                'user'        => $m->user?->email ?? '—',
                'created_at'  => $m->created_at->format('d/m/Y H:i'),
            ]);

        return response()->json(['status' => 1, 'items' => $items]);
    }

    /**
     * Guarda un nuevo mantenimiento manual para el dispositivo
     * y lo envía a Talentus en segundo plano.
     */
    public function store(Request $request, int $device_id): JsonResponse
    {
        /** @var \Tobuli\Entities\User $user */
        $user = auth()->user();

        $device = Device::findOrFail($device_id);

        if (! $user->can('edit', $device)) {
            return response()->json(['status' => 0, 'message' => 'No tienes permisos.'], 403);
        }

        $validated = $request->validate([
            'tipo'   => 'required|in:mantenimiento,suspension,reactivacion',
            'fecha'  => 'required|date',
            'motivo' => 'required|string|max:500',
            'notas'  => 'nullable|string|max:2000',
        ]);

        $maintenance = DeviceMaintenance::create([
            'device_id' => $device->id,
            'tipo'      => $validated['tipo'],
            'fecha'     => $validated['fecha'],
            'motivo'    => $validated['motivo'],
            'notas'     => $validated['notas'] ?? null,
            'user_id'   => $user->id,
        ]);

        PushMaintenanceToTalentus::dispatch('upsert', self::buildPayload($maintenance, $device));

        return response()->json(['status' => 1, 'id' => $maintenance->id]);
    }

    /**
     * Elimina un mantenimiento y notifica a Talentus para que lo elimine también.
     */
    public function destroy(int $device_id, int $id): JsonResponse
    {
        /** @var \Tobuli\Entities\User $user */
        $user = auth()->user();

        $device = Device::findOrFail($device_id);

        if (! $user->can('edit', $device)) {
            return response()->json(['status' => 0, 'message' => 'No tienes permisos.'], 403);
        }

        $maintenance = DeviceMaintenance::where('device_id', $device->id)->findOrFail($id);

        // Guardamos el payload antes de eliminar
        $payload = self::buildPayload($maintenance, $device);

        $maintenance->delete();

        PushMaintenanceToTalentus::dispatch('delete', $payload);

        return response()->json(['status' => 1]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el payload normalizado que se envía a Talentus.
     */
    public static function buildPayload(DeviceMaintenance $maintenance, Device $device): array
    {
        return [
            'id'          => $maintenance->id,
            'device_id'   => $device->id,
            'device_imei' => $device->imei,
            'device_name' => $device->name,
            'tipo'        => $maintenance->tipo,
            'fecha'       => $maintenance->fecha->toDateString(),
            'motivo'      => $maintenance->motivo,
            'notas'       => $maintenance->notas,
            'created_at'  => $maintenance->created_at?->toISOString(),
        ];
    }
}
