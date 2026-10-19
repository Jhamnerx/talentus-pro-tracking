<div class="table-responsive">
    <table class="table table-list">
        <thead>
        <tr>
            {!! tableHeader('validation.attributes.name') !!}
            {!! tableHeader('front.vehicle_states') !!}
            <th></th>
        </tr>
        </thead>

        <tbody>
        @if ($items->count())
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td>
                        @foreach ($item->states as $state)
                            @php $stateColor = preg_match('/^#[0-9a-fA-F]{6}/', (string) $state->color) ? $state->color : '#64748b'; @endphp
                            <span class="label" title="{{ $state->name }}"
                                style="display:inline-block;background:{{ $stateColor }};margin:1px 4px 1px 0;">{{ $state->label ?: $state->name }}</span>
                        @endforeach
                    </td>
                    <td class="actions">
                        <div class="btn-group dropdown droparrow" data-position="fixed">
                            <i class="btn icon edit" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true"></i>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="javascript:" data-modal="vehicle_types_edit" data-url="{{ route('vehicle_types.edit', $item->id) }}">
                                        {!! trans('global.edit') !!}
                                    </a>
                                </li>
                                <li>
                                    <a href="{{ route('vehicle_types.destroy', $item->id) }}"
                                       class="js-confirm-link"
                                       data-confirm="{!! trans('front.do_delete') !!}"
                                       data-method="DELETE">
                                        {{ trans('global.delete') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            @endforeach
        @else
            <tr>
                <td class="no-data" colspan="3">{!! trans('front.no_vehicle_types') !!}</td>
            </tr>
        @endif
        </tbody>
    </table>
</div>

<div class="nav-pagination">
    {!! $items->render() !!}
</div>
