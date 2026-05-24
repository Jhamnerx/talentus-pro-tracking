{{--
    Historial de Mantenimientos / Suspensiones / Reactivaciones
    Partial incluido en el tab "Historial" del formulario de edición de dispositivo.
    Requiere: $item (Device)
--}}
<div id="device-maintenances-tab" class="device-maintenances-panel">

    {{-- Formulario para añadir registro manual --}}
    <div class="form-group" style="margin-bottom: 16px;">
        <div class="row">
            <div class="col-sm-3">
                <label class="control-label">{{ trans('validation.attributes.type') }}</label>
                <select id="dm-tipo" class="form-control input-sm">
                    <option value="mantenimiento">Mantenimiento</option>
                    <option value="suspension">Suspensión</option>
                    <option value="reactivacion">Reactivación</option>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="control-label">Fecha</label>
                <input type="date" id="dm-fecha" class="form-control input-sm" value="{{ date('Y-m-d') }}"
                    max="{{ date('Y-m-d') }}">
            </div>
            <div class="col-sm-4">
                <label class="control-label">Motivo <span class="required">*</span></label>
                <input type="text" id="dm-motivo" class="form-control input-sm" maxlength="500"
                    placeholder="Ej: Reactivación luego de 6 meses suspendido">
            </div>
            <div class="col-sm-2" style="padding-top: 24px;">
                <button type="button" class="btn btn-action btn-sm btn-block" id="dm-save-btn">
                    <i class="fa fa-plus"></i> Guardar
                </button>
            </div>
        </div>
        <div class="row" style="margin-top: 8px;">
            <div class="col-sm-10">
                <label class="control-label">Notas adicionales</label>
                <input type="text" id="dm-notas" class="form-control input-sm" maxlength="2000"
                    placeholder="Opcional">
            </div>
        </div>
    </div>

    {{-- Tabla de registros --}}
    <div id="dm-loading" style="text-align:center; padding: 20px; display:none;">
        <i class="fa fa-spinner fa-spin"></i> Cargando...
    </div>
    <div id="dm-empty" style="text-align:center; color:#999; padding: 20px; display:none;">
        Sin registros aún.
    </div>

    <table class="table table-condensed table-striped" id="dm-table" style="font-size:12px; display:none;">
        <thead>
            <tr>
                <th width="80">Tipo</th>
                <th width="90">Fecha</th>
                <th>Motivo</th>
                <th>Notas</th>
                <th width="90">Usuario</th>
                <th width="60">Sync</th>
                <th width="40"></th>
            </tr>
        </thead>
        <tbody id="dm-tbody">
        </tbody>
    </table>

</div>

<script>
    (function() {
        'use strict';

        var deviceId = {{ $item->id }};
        var baseUrl = '/' + deviceId;

        // Badges según tipo
        var badgeMap = {
            mantenimiento: '<span class="label" style="background:#3b82f6;">Mantenimiento</span>',
            suspension: '<span class="label" style="background:#ef4444;">Suspensión</span>',
            reactivacion: '<span class="label" style="background:#22c55e;">Reactivación</span>',
        };

        function loadMaintenances() {
            $('#dm-loading').show();
            $('#dm-table, #dm-empty').hide();

            $.getJSON('/devices/' + deviceId + '/maintenances')
                .done(function(res) {
                    $('#dm-loading').hide();
                    if (!res.items || res.items.length === 0) {
                        $('#dm-empty').show();
                        return;
                    }
                    var rows = '';
                    $.each(res.items, function(i, m) {
                        rows += '<tr data-id="' + m.id + '">' +
                            '<td>' + (badgeMap[m.tipo] || m.tipo) + '</td>' +
                            '<td>' + m.fecha + '</td>' +
                            '<td>' + escHtml(m.motivo || '—') + '</td>' +
                            '<td>' + escHtml(m.notas || '—') + '</td>' +
                            '<td style="font-size:11px;">' + escHtml(m.user) + '</td>' +
                            '<td>' + (m.synced ?
                                '<i class="fa fa-check text-success" title="Sincronizado con Talentus"></i>' :
                                '<i class="fa fa-clock-o text-warning" title="Pendiente de sync"></i>'
                            ) + '</td>' +
                            '<td><a href="#" class="dm-delete text-danger" data-id="' + m.id +
                            '"><i class="fa fa-trash"></i></a></td>' +
                            '</tr>';
                    });
                    $('#dm-tbody').html(rows);
                    $('#dm-table').show();
                })
                .fail(function() {
                    $('#dm-loading').hide();
                    $('#dm-empty').text('Error al cargar los datos.').show();
                });
        }

        function escHtml(str) {
            return $('<div>').text(str).html();
        }

        // Guardar
        $(document).on('click', '#dm-save-btn', function() {
            var tipo = $('#dm-tipo').val();
            var fecha = $('#dm-fecha').val();
            var motivo = $.trim($('#dm-motivo').val());
            var notas = $.trim($('#dm-notas').val());

            if (!motivo) {
                alert('El campo Motivo es obligatorio.');
                return;
            }

            var btn = $(this).attr('disabled', true).text('Guardando...');

            $.ajax({
                url: '/devices/' + deviceId + '/maintenances',
                method: 'POST',
                data: {
                    tipo: tipo,
                    fecha: fecha,
                    motivo: motivo,
                    notas: notas,
                    _token: $('meta[name="csrf-token"]').attr('content')
                },
            }).done(function(res) {
                if (res.status === 1) {
                    $('#dm-motivo, #dm-notas').val('');
                    loadMaintenances();
                } else {
                    alert('Error al guardar.');
                }
            }).fail(function(xhr) {
                var msg = 'Error al guardar.';
                if (xhr.responseJSON && xhr.responseJSON.errors) {
                    msg = Object.values(xhr.responseJSON.errors).flat().join('\n');
                }
                alert(msg);
            }).always(function() {
                btn.removeAttr('disabled').html('<i class="fa fa-plus"></i> Guardar');
            });
        });

        // Eliminar
        $(document).on('click', '.dm-delete', function(e) {
            e.preventDefault();
            if (!confirm('¿Eliminar este registro?')) return;

            var id = $(this).data('id');
            $.ajax({
                url: '/devices/' + deviceId + '/maintenances/' + id,
                method: 'DELETE',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content')
                },
            }).done(function(res) {
                if (res.status === 1) loadMaintenances();
            });
        });

        // Cargar al abrir el tab (compatibilidad con Bootstrap tabs)
        $(document).on('shown.bs.tab', 'a[href="#device-form-maintenances"]', function() {
            loadMaintenances();
        });

        // También cargar si ya está visible al renderizar
        if ($('#device-form-maintenances').hasClass('active')) {
            loadMaintenances();
        }

    }());
</script>
