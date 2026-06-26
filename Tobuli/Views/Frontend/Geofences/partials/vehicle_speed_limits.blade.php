@php /** @var \Illuminate\Support\Collection $vehicleTypes */ $vehicleSpeedLimits = $vehicleSpeedLimits ?? []; @endphp

@if (settings('plugins.geofences_speed_limit.status'))
    @if ($vehicleTypes->isEmpty())
        <div class="form-group">
            {!! Form::label('speed_limit', trans('validation.attributes.speed_limit') . ':') !!}
            {!! Form::text('speed_limit', $item->speed_limit ?? null, ['class' => 'form-control']) !!}
        </div>
    @else
        <div class="form-group">
            {!! Form::label('speed_limit', trans('front.speed_limit_default') . ':') !!}
            {!! Form::text('speed_limit', $item->speed_limit ?? null, ['class' => 'form-control']) !!}
        </div>

        <div class="form-group">
            <label>{{ trans('front.speed_limit_by_vehicle_type') }}:</label>
        </div>

        @foreach ($vehicleTypes as $vehicleType)
            @if ($vehicleType->states->isEmpty())
                <div class="form-group">
                    {!! Form::label("vehicle_speed_limits[{$vehicleType->id}][0]", $vehicleType->name . ':') !!}
                    {!! Form::text(
                        "vehicle_speed_limits[{$vehicleType->id}][0]",
                        $vehicleSpeedLimits[$vehicleType->id][0] ?? null,
                        ['class' => 'form-control']
                    ) !!}
                </div>
            @else
                <div class="form-group">
                    <label>{{ $vehicleType->name }}</label>

                    @foreach ($vehicleType->states as $vehicleState)
                        <div class="col-sm-4">
                            {!! Form::label("vehicle_speed_limits[{$vehicleType->id}][{$vehicleState->id}]", $vehicleState->name . ':') !!}
                            {!! Form::text(
                                "vehicle_speed_limits[{$vehicleType->id}][{$vehicleState->id}]",
                                $vehicleSpeedLimits[$vehicleType->id][$vehicleState->id] ?? null,
                                ['class' => 'form-control']
                            ) !!}
                        </div>
                    @endforeach

                    <div class="clearfix"></div>
                </div>
            @endif
        @endforeach
    @endif
@endif
