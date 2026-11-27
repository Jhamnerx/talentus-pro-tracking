{{--
    Talentus IA CHAT · panel lateral del asistente (UI v2).
    Se incluye desde layout.blade.php solo con el permiso ai_assistant (view)
    y el asistente configurado en Admin. Comportamiento: tp-ai-chat.js.
    El boton que lo abre esta en el topbar ([data-tpai-open]) y en Ctrl+I.
--}}
@php($tpaiName = \App\Services\Ai\AiSettings::name())
<aside id="tpai" class="tpai" role="dialog" aria-modal="false" aria-label="{{ $tpaiName }}" hidden
    data-url-chat="{{ route('ai.chat') }}"
    data-url-suggestions="{{ route('ai.suggestions') }}"
    data-url-conversations="{{ route('ai.conversations') }}"
    data-url-confirm="{{ route('ai.actions.confirm', '__TOKEN__') }}"
    data-url-cancel="{{ route('ai.actions.cancel', '__TOKEN__') }}"
    data-url-live="{{ url('/ui/v2/live') }}"
    data-url-history="{{ url('/ui/v2/history') }}"
    data-screen="@yield('rail-active', 'live')"
    data-i18n="{{ json_encode([
        'thinking' => trans('front.ai_thinking'),
        'error' => trans('front.ai_error'),
        'quota_left' => trans('front.ai_quota_left'),
        'no_history' => trans('front.ai_no_history'),
        'consulted' => trans('front.ai_consulted'),
        'confirm' => trans('front.ai_confirm'),
        'cancel' => trans('front.ai_cancel'),
        'delete' => trans('front.ai_delete'),
        'periods' => [
            trans('front.ai_period_today'),
            trans('front.ai_period_yesterday'),
            trans('front.ai_period_this_week'),
            trans('front.ai_period_last_week'),
            trans('front.ai_period_this_month'),
            trans('front.ai_period_last_month'),
        ],
    ]) }}">

    <header class="tpai__head">
        <div class="tpai__title">
            <span class="tpai__avatar" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
            </span>
            <strong>{{ $tpaiName }}</strong>
        </div>
        <div class="tpai__head-actions">
            <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" data-tpai="history"
                data-tooltip="{{ trans('front.ai_history') }}" aria-label="{{ trans('front.ai_history') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
            </button>
            <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" data-tpai="new"
                data-tooltip="{{ trans('front.ai_new_chat') }}" aria-label="{{ trans('front.ai_new_chat') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-has-tooltip" data-tpai="close"
                data-tooltip="{{ trans('front.ai_minimize') }} (Esc)" aria-label="{{ trans('front.ai_minimize') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg>
            </button>
        </div>
    </header>

    <div class="tpai__body" id="tpai-body">
        <div class="tpai__welcome" id="tpai-welcome">
            <div class="tpai__welcome-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
            </div>
            <h3>{{ trans('front.ai_welcome') }}</h3>
            <p>{{ trans('front.ai_welcome_sub') }}</p>
            <div class="tpai__chips" id="tpai-suggestions"></div>
        </div>
        <div class="tpai__messages" id="tpai-messages" aria-live="polite"></div>
        <div class="tpai__history" id="tpai-history" hidden></div>
    </div>

    <form class="tpai__form" id="tpai-form" autocomplete="off">
        <textarea id="tpai-input" class="tpai__input" rows="1" maxlength="2000"
            placeholder="{{ trans('front.ai_placeholder') }}" aria-label="{{ trans('front.ai_placeholder') }}"></textarea>
        <button type="submit" class="tp-btn tp-btn--primary tp-btn--icon tpai__send" aria-label="{{ trans('front.ai_send') }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </button>
    </form>
    <div class="tpai__foot">
        <span id="tpai-quota"></span>
        <span>{{ trans('front.ai_disclaimer') }}</span>
    </div>
</aside>

{{-- Boton flotante: abre el chat y es donde queda al minimizarlo. --}}
<button type="button" class="tpai-fab" id="tpai-fab" data-tpai-open aria-controls="tpai" aria-expanded="false"
    aria-label="{{ $tpaiName }}" title="{{ $tpaiName }} (Ctrl+I)">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
    <span class="tpai-fab__dot" id="tpai-fab-dot" hidden></span>
</button>
