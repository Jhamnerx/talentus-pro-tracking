@php
    /** @var \Tobuli\Entities\CommandRule $item */
    $conditions = $item->exists ? $item->conditions : collect();
@endphp

<div class="row">
    <div class="col-sm-8">
        <div class="form-group">
            {!! Form::label('name', trans('validation.attributes.name') . ':') !!}
            {!! Form::text('name', $item->name, ['class' => 'form-control']) !!}
        </div>
    </div>
    <div class="col-sm-4">
        <div class="form-group">
            <label>&nbsp;</label>
            <div class="checkbox">
                {!! Form::hidden('active', 0) !!}
                {!! Form::checkbox('active', 1, $item->active) !!}
                {!! Form::label('active', trans('validation.attributes.active')) !!}
            </div>
        </div>
    </div>
</div>

<div class="form-group">
    {!! Form::label('description', trans('validation.attributes.description') . ':') !!}
    {!! Form::text('description', $item->description, ['class' => 'form-control']) !!}
</div>

<div class="form-group">
    {!! Form::label('devices', trans('validation.attributes.devices') . ':') !!}
    {!! Form::select('devices[]', [], null, [
        'class' => 'form-control multiexpand',
        'multiple' => 'multiple',
        'data-live-search' => 'true',
        'data-actions-box' => 'true',
        'data-ajax' => route('admin.command_rules.devices', $item->exists ? $item->id : null),
    ]) !!}
</div>

<hr>
<h5><strong>{{ trans('admin.command_rules_query_section') }}</strong></h5>

<div class="row">
    <div class="col-sm-6">
        <div class="form-group">
            {!! Form::label('query_command', trans('admin.command_rules_query') . ':') !!}
            {!! Form::text('query_command', $item->query_command, ['class' => 'form-control', 'placeholder' => 'getio']) !!}
            <span class="help-block small">{{ trans('admin.command_rules_query_help') }}</span>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="form-group">
            {!! Form::label('interval_minutes', trans('admin.command_rules_interval') . ':') !!}
            {!! Form::number('interval_minutes', $item->interval_minutes, ['class' => 'form-control', 'min' => 1, 'max' => 1440]) !!}
        </div>
    </div>
    <div class="col-sm-3">
        <div class="form-group">
            {!! Form::label('cooldown_minutes', trans('admin.command_rules_cooldown') . ':') !!}
            {!! Form::number('cooldown_minutes', $item->cooldown_minutes, ['class' => 'form-control', 'min' => 0, 'max' => 10080]) !!}
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-6">
        <div class="form-group">
            <div class="checkbox">
                {!! Form::hidden('only_connected', 0) !!}
                {!! Form::checkbox('only_connected', 1, $item->only_connected) !!}
                {!! Form::label('only_connected', trans('admin.command_rules_only_connected')) !!}
            </div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="form-group">
            <div class="checkbox">
                {!! Form::hidden('evaluate_any_response', 0) !!}
                {!! Form::checkbox('evaluate_any_response', 1, $item->evaluate_any_response) !!}
                {!! Form::label('evaluate_any_response', trans('admin.command_rules_evaluate_any')) !!}
            </div>
            <span class="help-block small">{{ trans('admin.command_rules_evaluate_any_help') }}</span>
        </div>
    </div>
</div>

<hr>
<h5><strong>{{ trans('admin.command_rules_conditions') }}</strong></h5>
<p class="help-block small">{{ trans('admin.command_rules_conditions_help') }}</p>

<div class="empty-input-items command-rule-conditions" data-max="20">
    @foreach ($conditions as $c)
        @include('Admin.CommandRules.condition_row', ['c' => $c, 'template' => false])
    @endforeach

    @include('Admin.CommandRules.condition_row', ['c' => null, 'template' => true])
</div>

<script>
    // Muestra los campos segun el tipo de coincidencia de cada fila
    (function () {
        function refreshRow($row) {
            var type = $row.find('select[name="cond_match_type[]"]').val();
            $row.find('.cr-pattern').toggle(type !== 'compare');
            $row.find('.cr-compare').toggle(type === 'compare');
        }
        $(document).on('change', '.command-rule-conditions select[name="cond_match_type[]"]', function () {
            refreshRow($(this).closest('.cr-row'));
        });
        $('.command-rule-conditions .cr-row').each(function () { refreshRow($(this)); });
    })();
</script>
