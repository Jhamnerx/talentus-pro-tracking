{{--
    Tab Historial — mantenimientos / suspensiones / reactivaciones
    -------------------------------------------------------------------
    Paridad con Devices/partials/maintenances.blade.php del legacy. Consume
    los MISMOS endpoints (device_maintenances.*), que ya validan
    can('edit', $device) y sincronizan con el ERP Talentus; aquí no se
    duplica nada de esa lógica.
--}}
@php
    $isEditDev = !empty($item->id);
@endphp

@if (!$isEditDev)
    <div class="tp-form__placeholder">
        {{ trans('ui.save_vehicle_first_maintenance') }}
    </div>
@else
    @php
        $maintCfg = [
            'list'    => url('/devices/' . (int) $item->id . '/maintenances'),
            'store'   => url('/devices/' . (int) $item->id . '/maintenances'),
            'destroy' => url('/devices/' . (int) $item->id . '/maintenances') . '/{id}',
        ];
    @endphp

    <div data-tp-maint data-device-id="{{ (int) $item->id }}">
        <script type="application/json" data-maint-cfg>@json($maintCfg)</script>

        {{-- Alta de registro --}}
        <div class="tp-form__grid tp-form__grid--2" style="margin-bottom: 12px;">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.type') }}</span>
                <select class="tp-input" data-maint-tipo>
                    <option value="mantenimiento">Mantenimiento</option>
                    <option value="suspension">{{ trans('ui.suspension') }}</option>
                    <option value="reactivacion">{{ trans('ui.reactivation') }}</option>
                </select>
            </label>
            <label class="tp-form__field">
                <span class="tp-form__label">Fecha</span>
                <input type="date" class="tp-input" data-maint-fecha value="{{ date('Y-m-d') }}"
                    max="{{ date('Y-m-d') }}">
            </label>
            <label class="tp-form__field tp-col-2">
                <span class="tp-form__label">Motivo <span style="color:#ef4444;">*</span></span>
                <input type="text" class="tp-input" data-maint-motivo maxlength="500"
                    placeholder="{{ trans('ui.reactivation_example') }}">
            </label>
            <label class="tp-form__field tp-col-2">
                <span class="tp-form__label">Notas adicionales</span>
                <input type="text" class="tp-input" data-maint-notas maxlength="2000" placeholder="Opcional">
            </label>
            <div class="tp-form__field tp-col-2" style="align-items:flex-end;">
                <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-maint-save>
                    ＋ Guardar registro
                </button>
            </div>
        </div>

        {{-- Listado --}}
        <div class="tp-embed-box" style="max-height: 280px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 118px;">{{ trans('validation.attributes.type') }}</th>
                        <th style="width: 92px;">Fecha</th>
                        <th>Motivo</th>
                        <th>Notas</th>
                        <th style="width: 150px;">Usuario</th>
                        <th style="width: 70px;">Sync</th>
                        <th style="width: 44px;"></th>
                    </tr>
                </thead>
                <tbody data-maint-rows>
                    <tr>
                        <td colspan="7" class="tp-text-muted" style="padding: 14px 12px; font-size: 13px;">Cargando…
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endif
