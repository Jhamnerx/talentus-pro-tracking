<style>
    .ov-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 16px;
    }

    .ov-card {
        flex: 1 1 130px;
        min-width: 110px;
        border-radius: 4px;
        padding: 12px 14px 10px;
        background: #fff;
        border: 1px solid #e4e8ec;
        border-top-width: 3px;
        text-decoration: none !important;
        display: flex;
        flex-direction: column;
        transition: box-shadow .15s;
    }

    .ov-card:hover {
        box-shadow: 0 3px 10px rgba(0, 0, 0, .09);
        text-decoration: none !important;
    }

    .ov-card .oc-num {
        font-size: 28px;
        font-weight: 700;
        line-height: 1;
        color: #111827;
        display: block;
        margin-bottom: 4px;
    }

    .ov-card .oc-label {
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #6b7280;
        display: block;
        white-space: nowrap;
    }

    .ov-card .oc-link {
        font-size: 10px;
        color: #9ca3af;
        margin-top: 6px;
        display: block;
    }
</style>

<div class="ov-cards">
    @foreach ($statuses as $status)
        <a class="ov-card" style="border-top-color: {{ $status['color'] }}" href="{{ $status['url'] }}" target="_blank">
            <span class="oc-num">{{ $status['data'] }}</span>
            <span class="oc-label" style="color: {{ $status['color'] }}">{{ $status['label'] }}</span>
            <span class="oc-link">{{ trans('global.view_details') }} &rsaquo;</span>
        </a>
    @endforeach
</div>

<div class="row">
    @if (Auth::user()->perm('events', 'view'))
        <div class="col-sm-6">
            @include('Frontend.Dashboard.Blocks.device_overview.events')
        </div>
    @endif

    <div class="col-sm-6">
        @include('Frontend.Dashboard.Blocks.device_overview.graph')
    </div>
</div>

<script type='text/javascript'>
    if (typeof _static_device_overview === "undefined") {
        var _static_device_overview = true;
    }

    if (_static_device_overview && $('#dashboard').is(':visible')) {
        _static_device_overview = false;
        setTimeout(function() {
            _static_device_overview = true;
            app.dashboard.loadBlockContent('device_overview', true);
        }, 10000);
    }
</script>
