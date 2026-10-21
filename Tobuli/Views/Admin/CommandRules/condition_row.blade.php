@php
    /** @var \Tobuli\Entities\CommandRuleCondition|null $c */
    $type = $c->match_type ?? \Tobuli\Entities\CommandRuleCondition::MATCH_CONTAINS;
@endphp
{{-- Una fila = un .form-group: el repetidor .empty-input-items clona/borra por .form-group --}}
<div class="form-group cr-row {{ $template ? 'empty-input-add-new' : '' }}">
    <div class="row">
        <div class="col-sm-3 col-xs-6">
            {!! Form::select('cond_match_type[]', $matchTypes, $type, ['class' => 'form-control']) !!}
        </div>

        <div class="col-sm-4 col-xs-6 cr-pattern">
            {!! Form::text('cond_pattern[]', $c->pattern ?? null, ['class' => 'form-control', 'placeholder' => trans('admin.command_rules_pattern_placeholder')]) !!}
        </div>

        <div class="col-sm-4 col-xs-12 cr-compare" style="display:none">
            <div class="row">
                <div class="col-xs-5" style="padding-right:2px">
                    {!! Form::text('cond_field[]', $c->field ?? null, ['class' => 'form-control', 'placeholder' => 'AIN1']) !!}
                </div>
                <div class="col-xs-3" style="padding:0 2px">
                    {!! Form::select('cond_operator[]', $operators, $c->operator ?? '>', ['class' => 'form-control']) !!}
                </div>
                <div class="col-xs-4" style="padding-left:2px">
                    {!! Form::text('cond_value[]', $c->value ?? null, ['class' => 'form-control', 'placeholder' => '90']) !!}
                </div>
            </div>
        </div>

        <div class="col-sm-3 col-xs-8">
            {!! Form::text('cond_action_command[]', $c->action_command ?? null, ['class' => 'form-control', 'placeholder' => trans('admin.command_rules_action_placeholder')]) !!}
        </div>

        <div class="col-sm-2 col-xs-4">
            <div class="input-group">
                {!! Form::select('cond_stop_on_match[]', $yesNo, isset($c) ? (int) $c->stop_on_match : 1, ['class' => 'form-control', 'title' => trans('admin.command_rules_stop_on_match')]) !!}
                <span class="input-group-addon"><a href="javascript:" class="delete-item"><span aria-hidden="true">×</span></a></span>
            </div>
        </div>
    </div>
</div>
