<?php

namespace App\Services\Ai\Reports;

use Tobuli\Reports\DeviceHistoryReport;

/**
 * Ejecuta el mismo motor de historial que usan los reportes oficiales
 * (DeviceHistory + Actions), pero entrega los grupos y estadisticas crudas
 * dispositivo por dispositivo para que las herramientas del agente calculen
 * totales exactos sin guardar todo en memoria.
 */
class AiHistoryReport extends DeviceHistoryReport
{
    const TYPE_ID = 0;

    private $aiActions = [];

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return 'AirBot';
    }

    public function setActions(array $actions)
    {
        $this->aiActions = $actions;

        return $this;
    }

    protected function getActionsList()
    {
        return $this->aiActions;
    }

    /**
     * @param callable $callback fn(Device $device, ?array $data) donde $data
     *                           tiene 'root' (Group) y 'groups' o null si no hay datos
     */
    public function each(callable $callback)
    {
        $this->beforeGenerate();

        $this->getDevicesQuery()->chunk(100, function ($devices) use ($callback) {
            foreach ($devices as $device) {
                $data = $this->getDeviceHistoryData($device);

                $callback($device, $this->isEmptyResult($data) ? null : $data);
            }
        });
    }

    /**
     * Valores legibles de un grupo con el mismo formato que el reporte oficial
     * (por ejemplo fuel_level_difference, group_geofence, drivers).
     */
    public function groupData($group, array $keys): array
    {
        return $this->getDataFromGroup($group, $keys);
    }

    /** Direccion de una posicion (geocodificacion del sistema). */
    public function address($position): ?string
    {
        if (!$position) {
            return null;
        }

        $this->show_addresses = true;

        try {
            $address = $this->getAddress($position);
        } catch (\Exception $e) {
            $address = null;
        }

        return $address ? html_entity_decode($address) : null;
    }

    protected function generate()
    {
        // No se usa: las herramientas llaman a each().
    }
}
