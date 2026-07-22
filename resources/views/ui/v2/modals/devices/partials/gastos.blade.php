{{--
    Tab Gastos — paridad con el tab Expenses legacy (device_expenses):
    buscador + botón añadir + tabla Nombre|Cantidad|Costo unit.|Total|Fecha
    con editar/eliminar. CRUD nativo contra ui.v2.api.devices.expenses*.
    El catálogo de tipos ($expenses_types) viaja en el JSON de config.
--}}
@php
    $isEdit = !empty($item->id);
    $expensesList = $expenses_list ?? [];
    $expTypes = $expenses_types_list ?? [];
@endphp

@if (!$isEdit)
    <div class="tp-form__placeholder">
        Guarda el vehículo primero; después podrás registrar gastos desde esta pestaña.
    </div>
@else
    @php
        $expensesCfg = [
            'list'    => route('ui.v2.api.devices.expenses', $item->id),
            'store'   => route('ui.v2.api.devices.expenses.store', $item->id),
            'update'  => url('/ui/v2/api/expenses') . '/{id}',
            'destroy' => url('/ui/v2/api/expenses') . '/{id}',
            'types'   => $expTypes,
        ];
    @endphp

    <div data-tp-expenses data-device-id="{{ (int) $item->id }}">
        <script type="application/json" data-expenses-cfg>@json($expensesCfg)</script>

        <div class="tp-group-box__head">
            <span class="tp-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input type="search" class="tp-input" data-expenses-search placeholder="{{ trans('front.search') }}"
                    autocomplete="off">
            </span>
            <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-expense-add
                style="margin-left: auto;">＋ {{ trans('global.add') }}</button>
        </div>

        <div class="tp-embed-box">
            <table>
                <thead>
                    <tr>
                        <th>{{ trans('validation.attributes.name') }}</th>
                        <th>{{ trans('global.quantity') }}</th>
                        <th>{{ trans('validation.attributes.unit_cost') }}</th>
                        <th>{{ trans('global.total') }}</th>
                        <th>{{ trans('global.date') }}</th>
                        <th style="width: 84px;"></th>
                    </tr>
                </thead>
                <tbody data-expenses-rows>
                    @forelse ($expensesList as $e)
                        <tr data-expense-id="{{ $e['id'] }}">
                            <td>{{ $e['name'] }}</td>
                            <td>{{ $e['quantity'] }}</td>
                            <td>{{ $e['unit_cost'] }}</td>
                            <td>{{ $e['total'] }}</td>
                            <td>{{ $e['date'] }}</td>
                            <td style="text-align: right;">
                                @include('ui.v2.modals.devices.partials.kebab', ['edit' => 'data-expense-edit', 'del' => 'data-expense-del', 'id' => $e['id']])
                            </td>
                        </tr>
                    @empty
                        <tr data-expenses-empty>
                            <td colspan="6" class="tp-text-muted" style="padding: 14px 12px; font-size: 13px;">
                                {{ trans('front.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
