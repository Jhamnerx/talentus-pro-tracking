{{-- Rail · primary navigation --}}
@php
    $active = $active ?? 'live';
    $items = [
        ['key' => 'live', 'label' => 'Live Ops', 'url' => url('/ui/v2/live'), 'icon' => 'live'],
        ['key' => 'dashboard', 'label' => 'Command Center', 'url' => url('/ui/v2/dashboard'), 'icon' => 'dashboard'],
        ['key' => 'fleet', 'label' => 'Fleet', 'url' => url('/ui/v2/fleet'), 'icon' => 'fleet'],
        ['key' => 'zones', 'label' => 'Geocercas', 'url' => url('/ui/v2/live?panel=zones'), 'icon' => 'zones'],
        ['key' => 'pois', 'label' => 'POIs', 'url' => url('/ui/v2/live?panel=pois'), 'icon' => 'poi'],
        ['key' => 'alerts', 'label' => 'Alerts', 'url' => url('/ui/v2/alerts'), 'icon' => 'alerts', 'badge' => 3],
        ['key' => 'events', 'label' => 'Eventos', 'url' => url('/ui/v2/events'), 'icon' => 'events'],
        ['key' => 'history', 'label' => 'History & Replay', 'url' => url('/ui/v2/history'), 'icon' => 'history'],
        ['key' => 'reports', 'label' => 'Reports', 'url' => url('/ui/v2/reports'), 'icon' => 'reports'],
    ];
    $footer = [
        ['key' => 'copilot', 'label' => 'Co-Pilot IA', 'url' => url('/ui/v2/copilot'), 'icon' => 'sparkle'],
        ['key' => 'admin', 'label' => 'Admin', 'url' => url('/ui/v2/admin'), 'icon' => 'settings'],
    ];
@endphp

<aside class="tp-rail" aria-label="Navegación principal">
    <a href="{{ url('/ui/v2/preview') }}" class="tp-rail__logo" aria-label="TalentusPro Home">T</a>

    @foreach ($items as $it)
        <a href="{{ $it['url'] }}"
            class="tp-rail__item tp-has-tooltip {{ in_array($it['key'], ['fleet', 'admin']) ? 'tp-rail__item--right' : '' }}"
            data-tooltip="{{ $it['label'] }}" aria-label="{{ $it['label'] }}"
            @if ($active === $it['key']) aria-current="page" @endif style="position:relative;">
            @include('ui.v2.partials.icons', ['name' => $it['icon']])
            @if (!empty($it['badge']))
                <span class="tp-badge" style="position:absolute; top:4px; right:4px;">{{ $it['badge'] }}</span>
            @endif
        </a>
    @endforeach

    <div class="tp-rail__spacer"></div>

    @foreach ($footer as $it)
        <a href="{{ $it['url'] }}" class="tp-rail__item tp-has-tooltip" data-tooltip="{{ $it['label'] }}"
            aria-label="{{ $it['label'] }}" @if ($active === $it['key']) aria-current="page" @endif>
            @include('ui.v2.partials.icons', ['name' => $it['icon']])
        </a>
    @endforeach
</aside>
