<style>
    .ev-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .ev-row {
        display: flex;
        align-items: center;
        padding: 7px 0;
        border-bottom: 1px solid #f2f4f5;
        gap: 8px;
    }

    .ev-row:last-child {
        border-bottom: none;
    }

    .ev-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex-shrink: 0;
        background: #cbd5e1;
    }

    .ev-dot-alert {
        background: #ef4444;
    }

    .ev-dot-speed {
        background: #f97316;
    }

    .ev-dot-geofence {
        background: #3b82f6;
    }

    .ev-dot-offline {
        background: #94a3b8;
    }

    .ev-name {
        flex: 1;
        font-size: 11.5px;
        color: #2c3e50;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ev-bar-wrap {
        width: 60px;
        height: 4px;
        background: #f0f3f5;
        border-radius: 2px;
        flex-shrink: 0;
    }

    .ev-bar-fill {
        height: 4px;
        border-radius: 2px;
        background: #3b82f6;
    }

    .ev-count {
        font-size: 11px;
        font-weight: 700;
        color: #1a2332;
        min-width: 24px;
        text-align: right;
        flex-shrink: 0;
    }

    .ev-empty {
        color: #aab3bc;
        font-size: 12px;
        text-align: center;
        padding: 24px 0;
    }
</style>

@if (count($events) > 0)
    @php $maxCount = max(array_column(array_values($events), 'count') ?: [1]); @endphp
    <ul class="ev-list">
        @foreach ($events as $event)
            @php
                $cnt = $event['count'] ?? 0;
                $pct = $maxCount > 0 ? round(($cnt / $maxCount) * 100) : 0;
                $title = strtolower($event['title'] ?? '');
                $dotClass = 'ev-dot';
                if (str_contains($title, 'velocidad') || str_contains($title, 'speed')) {
                    $dotClass = 'ev-dot ev-dot-speed';
                } elseif (str_contains($title, 'geofence') || str_contains($title, 'geocerca')) {
                    $dotClass = 'ev-dot ev-dot-geofence';
                } elseif (str_contains($title, 'offline') || str_contains($title, 'conexi')) {
                    $dotClass = 'ev-dot ev-dot-offline';
                } else {
                    $dotClass = 'ev-dot ev-dot-alert';
                }
            @endphp
            <li class="ev-row">
                <span class="{{ $dotClass }}"></span>
                <span class="ev-name" title="{{ $event['title'] }}">{{ $event['title'] }}</span>
                <div class="ev-bar-wrap">
                    <div class="ev-bar-fill" style="width:{{ $pct }}%"></div>
                </div>
                <span class="ev-count">{{ $cnt }}</span>
            </li>
        @endforeach
    </ul>
@else
    <p class="ev-empty">{{ trans('front.nothing_found') }}</p>
@endif
