@extends('Frontend.Layouts.modal')

@section('title', trans('front.device_routes_report') . ' - ' . $item->name)

@section('body')
    @if ($enabled)
        <?php
            // defaults in the user timezone: today 00:00 -> tomorrow 00:00 (same convention as the reports form)
            $today = Formatter::time()->convert(date('Y-m-d H:i:s'), 'Y-m-d');
            $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        ?>
        {{-- GET + target=_blank: reports.update with generate=1&view=1&format=html renders the report inline in a new tab --}}
        <form id="device_route_report_form" action="{{ route('reports.update') }}" method="GET" target="_blank">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="format" value="html">
            <input type="hidden" name="devices[]" value="{{ $item->id }}">
            {{-- web report generation builds the device query from selected_devices[] (field;status;value), not from devices[] --}}
            <input type="hidden" name="selected_devices[]" value="id;true;{{ $item->id }}">
            <input type="hidden" name="generate" value="1">
            <input type="hidden" name="view" value="1">

            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        <label>{{ trans('validation.attributes.date_from') }}</label>
                        <div class="row">
                            <div class="col-xs-7">
                                <input name="date_from" type="text" class="datepicker form-control" value="{{ $today }}">
                            </div>
                            <div class="col-xs-5">
                                {!! Form::select('from_time', getSelectTimeRange(), '00:00', ['class' => 'form-control timeselect']) !!}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        <label>{{ trans('validation.attributes.date_to') }}</label>
                        <div class="row">
                            <div class="col-xs-7">
                                <input name="date_to" type="text" class="datepicker form-control" value="{{ $tomorrow }}">
                            </div>
                            <div class="col-xs-5">
                                {!! Form::select('to_time', getSelectTimeRange(), '00:00', ['class' => 'form-control timeselect']) !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @else
        <div class="alert alert-warning">{{ trans('front.report_type_not_enabled') }}</div>
    @endif
@stop

@section('buttons')
    @if ($enabled)
        <button type="button" class="btn btn-action" onclick="$('#device_route_report_form').submit(); $(this).closest('.modal').modal('hide');">{{ trans('front.generate') }}</button>
    @endif
    <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('global.cancel') }}</button>
@stop
