<style>
    .fleet-cards {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 14px;
    }

    .fleet-card {
        border-radius: 4px;
        padding: 12px 12px 10px;
        background: #fff;
        border: 1px solid #e4e8ec;
        border-left-width: 3px;
        text-decoration: none !important;
        display: block;
        transition: box-shadow .15s;
        position: relative;
    }

    .fleet-card:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
    }

    .fleet-card .fc-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
    }

    .fleet-card .fc-count {
        font-size: 26px;
        font-weight: 700;
        line-height: 1;
        color: #1a2332;
        display: block;
    }

    .fleet-card .fc-icon {
        font-size: 15px;
        padding: 4px;
        border-radius: 3px;
        opacity: .7;
    }

    .fleet-card .fc-label {
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        margin-top: 6px;
        display: block;
    }

    .card-moving {
        border-left-color: #22c55e;
    }

    .card-moving .fc-icon,
    .card-moving .fc-label {
        color: #22c55e;
    }

    .card-stopped {
        border-left-color: #f97316;
    }

    .card-stopped .fc-icon,
    .card-stopped .fc-label {
        color: #f97316;
    }

    .card-offline {
        border-left-color: #ef4444;
    }

    .card-offline .fc-icon,
    .card-offline .fc-label {
        color: #ef4444;
    }

    .card-alerts {
        border-left-color: #8b5cf6;
    }

    .card-alerts .fc-icon,
    .card-alerts .fc-label {
        color: #8b5cf6;
    }

    .fleet-detail {
        margin-bottom: 0;
    }

    .fleet-detail td {
        padding: 4px 0 !important;
        font-size: 11px;
        border-top: none !important;
        color: #5a6878;
    }

    .fleet-detail td b {
        color: #1a2332;
    }

    .fleet-detail tr:not(:last-child) td {
        border-bottom: 1px solid #f2f4f5 !important;
    }
</style>

<div class="fleet-cards">
    @foreach ($cards as $card)
        @if (!empty($card['url']))
            <a href="{{ $card['url'] }}" target="_blank" class="fleet-card {{ $card['color'] }}">
            @else
                <div class="fleet-card {{ $card['color'] }}">
        @endif
        <div class="fc-top">
            <span class="fc-count">{{ $card['count'] }}</span>
            <i class="fc-icon {{ $card['icon'] }}"></i>
        </div>
        <span class="fc-label">{{ $card['label'] }}</span>
        @if (!empty($card['url']))
            </a>
        @else
</div>
@endif
@endforeach
</div>

<table class="table fleet-detail">
    <tbody>
        @foreach ($statuses as $status)
            <tr>
                <td class="text-left">{{ $status['label'] }}</td>
                <td class="text-right">
                    @if (empty($status['url']))
                        <b>{{ $status['data'] }}</b>
                    @else
                        <a href="{{ $status['url'] }}" target="_blank"><b>{{ $status['data'] }}</b></a>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
