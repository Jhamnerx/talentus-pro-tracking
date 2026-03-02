<style>
    .ov-ev-hd {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 8px;
    }

    .ov-ev-hd .ov-ev-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #4b5563;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .ov-ev-hd .ov-ev-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ov-ev-hd .ov-ev-actions a.ov-link {
        font-size: 11px;
        color: #3b82f6;
        text-decoration: none;
    }

    .ov-ev-hd .ov-ev-actions a.ov-link:hover {
        text-decoration: underline;
    }

    .ov-ev-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .ov-ev-row {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.6fr) minmax(0, 1fr);
        align-items: center;
        gap: 6px;
        padding: 7px 0;
        border-bottom: 1px solid #f3f4f6;
        font-size: 11.5px;
    }

    .ov-ev-row:last-child {
        border-bottom: none;
    }

    .ov-ev-cell {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: #374151;
    }

    .ov-ev-cell.device {
        font-weight: 600;
        color: #111827;
    }

    .ov-ev-cell.time {
        color: #9ca3af;
        font-size: 10.5px;
        text-align: right;
    }

    .ov-ev-cell.type {
        color: #6b7280;
    }

    .ov-ev-head {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.6fr) minmax(0, 1fr);
        gap: 6px;
        padding: 0 0 5px;
        border-bottom: 2px solid #e5e7eb;
        margin-bottom: 2px;
    }

    .ov-ev-head span {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #9ca3af;
    }

    .ov-ev-head span.time {
        text-align: right;
    }

    .ov-ev-empty {
        color: #9ca3af;
        font-size: 12px;
        text-align: center;
        padding: 20px 0;
    }
</style>

<div class="ov-ev-hd">
    <div class="ov-ev-title">
        <i class="icon events" style="font-size:14px;"></i>
        {{ trans('front.recent_events') }}
    </div>
    <div class="ov-ev-actions">
        <a class="ov-link" href="{{ \Tobuli\Lookups\Tables\EventsLookupTable::route('index') }}" target="_blank">
            {{ trans('global.view_details') }}
        </a>
        <div class="btn-group droparrow" data-position="fixed">
            <i class="btn bnt-default icon filter" data-toggle="dropdown" aria-haspopup="true"
                aria-expanded="false"></i>
            <div class="dropdown-menu dropdown-menu-right">
                <div class="options-dropdown">
                    {!! Form::open(['url' => route('dashboard.config_update'), 'method' => 'POST', 'class' => 'dashboard-config']) !!}
                    {!! Form::hidden('block', 'device_overview') !!}
                    <div class="radio">
                        {!! Form::radio('dashboard[blocks][device_overview][options][event_type]', 0, empty($event_type)) !!}
                        {!! Form::label(null, trans('front.none')) !!}
                    </div>
                    @foreach (\Tobuli\Entities\Event::getTypeTitles() as $type)
                        <div class="radio">
                            {!! Form::radio(
                                'dashboard[blocks][device_overview][options][event_type]',
                                $type['type'],
                                $event_type == $type['type'],
                            ) !!}
                            {!! Form::label(null, ucfirst($type['title'])) !!}
                        </div>
                    @endforeach
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</div>

@if (count($events))
    <div class="ov-ev-head">
        <span>{{ trans('validation.attributes.device_id') }}</span>
        <span>{{ trans('validation.attributes.type') }}</span>
        <span class="time">{{ trans('validation.attributes.time') }}</span>
    </div>
    <ul class="ov-ev-list">
        @foreach ($events as $event)
            <li class="ov-ev-row">
                <span class="ov-ev-cell device" title="{{ $event->device->name }}">{{ $event->device->name }}</span>
                <span class="ov-ev-cell type" title="{{ $event->name }}">{{ $event->name }}</span>
                <span class="ov-ev-cell time">{{ Formatter::time()->human($event->time) }}</span>
            </li>
        @endforeach
    </ul>
@else
    <p class="ov-ev-empty">{{ trans('front.no_events') }}</p>
@endif
