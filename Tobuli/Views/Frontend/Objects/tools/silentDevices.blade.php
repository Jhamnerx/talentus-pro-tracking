{{-- Herramienta de mapa "Unidades sin transmitir".
     El modal solo es el marco: dibujar y pintar la tabla lo hace
     app.silentDevices (resources/assets/js/controller/silentDevices.js). --}}
<div class="modal fade" id="silentDevices" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">{{ trans('front.silent_devices') }}</h4>
            </div>

            <div class="modal-body">
                <p class="text-muted">{{ trans('front.silent_devices_hint') }}</p>

                <div class="form-inline" style="margin-bottom: 12px;">
                    {{-- Forma de la zona: el circulo va por centro y radio, no
                         aproximado a poligono, para que el borde sea exacto. --}}
                    <label for="silent_devices_shape">{{ trans('front.silent_devices_shape') }}</label>
                    <select id="silent_devices_shape" class="form-control" style="width: 130px; margin: 0 8px;">
                        <option value="polygon">{{ trans('front.polygon') }}</option>
                        <option value="circle">{{ trans('front.circle') }}</option>
                    </select>

                    <label for="silent_devices_minutes">{{ trans('front.silent_devices_threshold') }}</label>
                    <select id="silent_devices_minutes" class="form-control" style="width: 150px; margin: 0 8px;">
                        <option value="30">30 min</option>
                        <option value="60">1 h</option>
                        <option value="120" selected>2 h</option>
                        <option value="360">6 h</option>
                        <option value="720">12 h</option>
                        <option value="1440">24 h</option>
                        <option value="4320">3 d</option>
                        <option value="10080">7 d</option>
                    </select>

                    <button type="button" class="btn btn-primary" id="silent_devices_draw">
                        <span class="icon geofences"></span>
                        {{ trans('front.silent_devices_draw') }}
                    </button>

                    <button type="button" class="btn btn-default pull-right" id="silent_devices_export" style="display: none; margin-left: 6px;">
                        <i class="fa fa-download"></i> CSV
                    </button>
                    <button type="button" class="btn btn-default pull-right" id="silent_devices_export_xlsx" style="display: none;">
                        <i class="fa fa-file-excel-o"></i> XLSX
                    </button>
                </div>

                <div id="silent_devices_result"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    {{ trans('global.close') }}
                </button>
            </div>
        </div>
    </div>
</div>
