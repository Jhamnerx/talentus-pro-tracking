<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tobuli\Entities\DeviceExpensesType;

class DeviceExpensesTypesSeeder extends Seeder
{
    /**
     * Tipos de gastos de flota vehicular.
     * Solo inserta si la tabla está vacía.
     */
    public function run()
    {
        if (DeviceExpensesType::count() > 0) {
            $this->command->info('DeviceExpensesTypes: ya existen registros, se omite el seeder.');
            return;
        }

        $types = [
            // Operación diaria
            ['name' => 'Combustible'],
            ['name' => 'Peaje'],
            ['name' => 'Lavado'],

            // Mantenimiento preventivo
            ['name' => 'Mantenimiento Preventivo'],
            ['name' => 'Cambio de Aceite'],
            ['name' => 'Cambio de Llantas'],
            ['name' => 'Alineación y Balanceo'],
            ['name' => 'Cambio de Frenos'],
            ['name' => 'Cambio de Filtros'],

            // Mantenimiento correctivo
            ['name' => 'Reparación Motor'],
            ['name' => 'Reparación Transmisión'],
            ['name' => 'Reparación Eléctrica'],
            ['name' => 'Reparación Carrocería'],
            ['name' => 'Reparación Suspensión'],

            // Documentación / Legal
            ['name' => 'SOAT / Seguro Vehicular'],
            ['name' => 'Revisión Técnica'],
            ['name' => 'Multa de Tránsito'],
            ['name' => 'Impuesto Vehicular'],

            // Otros
            ['name' => 'Accesorio / Repuesto'],
            ['name' => 'Otros'],
        ];

        foreach ($types as $type) {
            DeviceExpensesType::create($type);
        }

        $this->command->info('DeviceExpensesTypes: ' . count($types) . ' tipos creados correctamente.');
    }
}
