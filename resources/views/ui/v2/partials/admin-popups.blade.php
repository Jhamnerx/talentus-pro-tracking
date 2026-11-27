{{--
    Avisos del administrador — paridad Frontend/Popups/*
    -------------------------------------------------------------------
    · index_banners_top.blade → franjas superiores (topBars + notificaciones
      con data.position = 'top').
    · index.blade             → popups modales (el resto de posiciones), que
      el legacy abre 5 s después de cargar.

    Igual que el legacy, leer las notificaciones las marca como leídas
    (filteredUnreadNotifications), así que este partial debe incluirse UNA
    sola vez por página.
--}}
@if (config('addon.notification_popops') && Auth::check())
    @php
        $tpTopBars = Auth::user()->topBars();
        $tpTopNotifications = Auth::user()->filteredUnreadNotifications(['data.position' => 'top']);
        $tpPopups = Auth::user()->filteredUnreadNotifications(['!data.position' => 'top']);
        // La carga útil se arma aquí: @json() no digiere closures con arrays
        // anidados (el parser de Blade corta en el primer corchete).
        $tpPopupsPayload = [];
        foreach ($tpPopups as $n) {
            $tpPopupsPayload[] = [
                'id' => (string) $n->id,
                'title' => (string) ($n->data['title'] ?? ''),
                'content' => (string) ($n->data['content'] ?? ''),
            ];
        }
    @endphp

    @if (count($tpTopBars) || count($tpTopNotifications))
        <div class="tp-adminbanners">
            @foreach ($tpTopBars as $topBar)
                <div class="tp-adminbanner" id="tp-banner-{{ $topBar['id'] ?? '' }}">
                    <div class="tp-adminbanner__body">{!! $topBar['content'] ?? '' !!}</div>
                    <button type="button" class="tp-adminbanner__close" aria-label="{{ trans('front.close') }}">×</button>
                </div>
            @endforeach
            @foreach ($tpTopNotifications as $notification)
                <div class="tp-adminbanner" id="tp-banner-{{ $notification->data['id'] ?? $notification->id }}">
                    <div class="tp-adminbanner__body">{!! $notification->data['title'] ?? '' !!}</div>
                    <button type="button" class="tp-adminbanner__close" aria-label="{{ trans('front.close') }}">×</button>
                </div>
            @endforeach
        </div>
    @endif

    @if (count($tpPopups))
        <script type="application/json" id="tp-admin-popups">@json($tpPopupsPayload)</script>
    @endif
@endif
