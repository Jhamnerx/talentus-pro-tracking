{{-- Rail � primary navigation --}}
@php
    $active = $active ?? 'live';
    $items = [
        ['key' => 'live', 'label' => 'Live Ops', 'url' => url('/talentus/live'), 'icon' => 'live'],
        ['key' => 'dashboard', 'label' => 'Command Center', 'url' => url('/talentus/dashboard'), 'icon' => 'dashboard'],
        ['key' => 'fleet', 'label' => 'Fleet', 'url' => url('/talentus/fleet'), 'icon' => 'fleet'],
        ['key' => 'zones', 'label' => 'Zones & Routes', 'url' => url('/talentus/zones'), 'icon' => 'zones'],
        ['key' => 'alerts', 'label' => 'Alerts', 'url' => url('/talentus/alerts'), 'icon' => 'alerts', 'badge' => 3],
        ['key' => 'history', 'label' => 'History & Replay', 'url' => url('/talentus/history'), 'icon' => 'history'],
        ['key' => 'reports', 'label' => 'Reports', 'url' => url('/talentus/reports'), 'icon' => 'reports'],
    ];
    $footer = [
        ['key' => 'copilot', 'label' => 'Co-Pilot IA', 'url' => url('/talentus/copilot'), 'icon' => 'sparkle'],
        ['key' => 'admin', 'label' => 'Admin', 'url' => url('/talentus/admin'), 'icon' => 'settings'],
    ];
@endphp

<aside class="tp-rail" aria-label="Navegaci�n principal">
    <a href="{{ url('/talentus/preview') }}" class="tp-rail__logo" aria-label="TalentusPro Home">T</a>

    @foreach ($items as $it)
        <a href="{{ $it['url'] }}"
            class="tp-rail__item tp-has-tooltip {{ in_array($it['key'], ['fleet', 'admin']) ? 'tp-rail__item--right' : '' }}"
            data-tooltip="{{ $it['label'] }}" aria-label="{{ $it['label'] }}"
            @if ($active === $it['key']) aria-current="page" @endif style="position:relative;">
            @include('talentus.partials.icons', ['name' => $it['icon']])
            @if (!empty($it['badge']))
                <span class="tp-badge" style="position:absolute; top:4px; right:4px;">{{ $it['badge'] }}</span>
            @endif
        </a>
    @endforeach

    <div class="tp-rail__spacer"></div>

    @foreach ($footer as $it)
        <a href="{{ $it['url'] }}" class="tp-rail__item tp-has-tooltip" data-tooltip="{{ $it['label'] }}"
            aria-label="{{ $it['label'] }}" @if ($active === $it['key']) aria-current="page" @endif>
            @include('talentus.partials.icons', ['name' => $it['icon']])
        </a>
    @endforeach
</aside>
