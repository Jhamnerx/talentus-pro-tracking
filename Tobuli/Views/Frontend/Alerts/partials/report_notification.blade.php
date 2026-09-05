@php
    /**
     * Ajustes del canal "reporte por correo". Van pegados a su casilla porque
     * sin ella no sirven de nada, y porque el resto de la pestana de
     * notificaciones se pinta sola desde getInputMeta().
     */
    use Tobuli\Reports\ReportManager;

    $item = $item ?? null;

    $tipos = (new ReportManager())->getUserEnabledNameList(Auth::user());
    $formatos = ReportManager::getFormats();
@endphp

<div class="row alert-report-options" style="margin-top:6px;">
    <div class="col-xs-6">
        {!! Form::label('report_type', trans('front.report_type') . ':') !!}
        {!! Form::select('report_type', ['' => '—'] + $tipos, $item->report_type ?? null, [
            'class' => 'form-control',
            'data-live-search' => 'true',
        ]) !!}
    </div>
    <div class="col-xs-6">
        {!! Form::label('report_format', trans('front.report_format') . ':') !!}
        {!! Form::select('report_format', $formatos, $item->report_format ?? 'pdf', ['class' => 'form-control']) !!}
    </div>
    <div class="col-xs-6">
        {!! Form::label('report_minutes_before', trans('front.alert_report_minutes_before') . ':') !!}
        {!! Form::number('report_minutes_before', $item->report_minutes_before ?? 15, [
            'class' => 'form-control',
            'min' => 0,
            'max' => 1440,
        ]) !!}
    </div>
    <div class="col-xs-6">
        {!! Form::label('report_minutes_after', trans('front.alert_report_minutes_after') . ':') !!}
        {!! Form::number('report_minutes_after', $item->report_minutes_after ?? 15, [
            'class' => 'form-control',
            'min' => 0,
            'max' => 1440,
        ]) !!}
    </div>
    <div class="col-xs-12">
        <small>{{ trans('front.alert_report_hint') }}</small>
    </div>
</div>
