@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.chat'))
@section('rail-active', 'chat')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/chat.css') }}?v=1">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-chat" id="tp-chat-page"
        data-conversations-url="{{ route('ui.v2.api.chat.conversations') }}"
        data-open-url="{{ url('/ui/v2/api/chat/open') . '/{id}' }}"
        data-messages-url="{{ url('/ui/v2/api/chat') . '/{id}/messages' }}"
        data-send-url="{{ url('/ui/v2/api/chat') . '/{id}/send' }}">

        {{-- Columna izquierda: lista de dispositivos con no-leídos --}}
        <aside class="tp-chat__list">
            <header class="tp-chat__list-head">
                <h2 class="tp-chat__title">{{ trans('front.chat') }}</h2>
                <span class="tp-search">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4.35-4.35" /></svg>
                    <input type="search" id="tp-chat-search" placeholder="Buscar vehículo…" autocomplete="off">
                </span>
            </header>
            <ul class="tp-chat__convs" id="tp-chat-convs">
                <li class="tp-chat__empty">Cargando…</li>
            </ul>
        </aside>

        {{-- Columna derecha: conversación --}}
        <section class="tp-chat__thread" id="tp-chat-thread">
            <div class="tp-chat__placeholder" id="tp-chat-placeholder">
                <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
                <p>Selecciona un vehículo para ver la conversación.</p>
            </div>

            <div class="tp-chat__conv" id="tp-chat-conv" hidden>
                <header class="tp-chat__conv-head">
                    <span class="tp-chat__conv-name" id="tp-chat-conv-name">—</span>
                </header>
                <div class="tp-chat__messages" id="tp-chat-messages"></div>
                <form class="tp-chat__composer" id="tp-chat-composer">
                    <input type="text" id="tp-chat-input" placeholder="Escribe un mensaje…" autocomplete="off">
                    <button type="submit" class="tp-btn tp-btn--primary tp-btn--icon" aria-label="Enviar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z" /><path d="M22 2 11 13" /></svg>
                    </button>
                </form>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/ui/v2/js/tp-chat.js') }}?v=1" defer></script>
@endpush
