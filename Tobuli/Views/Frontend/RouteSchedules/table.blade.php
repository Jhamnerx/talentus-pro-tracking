@php
    use App\Http\Controllers\Frontend\RouteSchedulesController;

    $dias = RouteSchedulesController::weekdays();
@endphp

<div class="table-responsive">
    <table class="table table-list" data-toggle="multiCheckbox">
        <thead>
            <tr>
                @if (Auth::User()->perm('routes', 'remove'))
                    {!! tableHeaderCheckall(['destroy' => trans('admin.delete_selected')]) !!}
                @endif
                {!! tableHeaderSort($items->sorting, 'name') !!}
                {!! tableHeader('front.route') !!}
                {!! tableHeader('front.route_weekdays') !!}
                {!! tableHeader('front.route_start_time') !!}
                {!! tableHeader('front.route_return_start_time') !!}
                {!! tableHeader('front.route_schedule_devices') !!}
                {!! tableHeader('validation.attributes.active') !!}
                <th></th>
            </tr>
        </thead>
        <tbody>
        @php /** @var \Tobuli\Entities\RouteSchedule $item */ @endphp
        @forelse ($items as $item)
            <tr>
                @if (Auth::User()->perm('routes', 'remove'))
                    <td>
                        <div class="checkbox">
                            <input type="checkbox" value="{{ $item->id }}">
                            <label></label>
                        </div>
                    </td>
                @endif
                <td>{{ $item->name }}</td>
                <td>{{ $item->route->name ?? '-' }}</td>
                <td>
                    {{-- Iniciales: la fila ya es ancha y "L M X J V" se lee igual --}}
                    @foreach ($item->weekdays_list as $iso)
                        {{ mb_substr($dias[$iso] ?? '', 0, 3) }}@if (! $loop->last), @endif
                    @endforeach
                </td>
                <td>{{ substr($item->start_time, 0, 5) }}</td>
                <td>{{ $item->return_start_time ? substr($item->return_start_time, 0, 5) : '-' }}</td>
                <td>{{ $item->devices->count() }}</td>
                <td>
                    <span class="label label-sm label-{{ $item->active ? 'success' : 'danger' }}">
                        {{ trans($item->active ? 'global.yes' : 'global.no') }}
                    </span>
                </td>
                <td class="actions">
                    <div class="btn-group dropdown droparrow" data-position="fixed">
                        <i class="btn icon edit" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true"></i>
                        <ul class="dropdown-menu">
                            @if (Auth::User()->perm('routes', 'edit'))
                                <li>
                                    <a href="javascript:"
                                       data-url="{{ route('route_schedules.edit', $item->id) }}"
                                       data-modal="route_schedules_edit">
                                        {{ trans('global.edit') }}
                                    </a>
                                </li>
                                <li>
                                    <a href="javascript:"
                                       data-url="{{ route('route_checkpoints.edit', $item->route_id) }}"
                                       data-modal="route_checkpoints_edit">
                                        {{ trans('front.route_checkpoints') }}
                                    </a>
                                </li>
                            @endif
                            @if (Auth::User()->perm('routes', 'remove'))
                                @include('front::Layouts.partials.confirmed_delete.menu_item', [
                                    'route' => route('route_schedules.destroy', $item->id),
                                ])
                            @endif
                        </ul>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td class="no-data" colspan="9">{{ trans('front.route_schedule_none') }}</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="nav-pagination">
    @if (count($items))
        {!! $items->setPath(route('route_schedules.table'))->render() !!}
    @endif
</div>
