{!! Form::open(['route' => 'authentication.store', 'class' => 'form']) !!}

@php
    /** @var \Tobuli\Services\Auth\InternalInterface[] $internalAuths */
@endphp

<div class="form-group">
    {!! Form::text('identifier', null, [
        'class' => 'form-control',
        'placeholder' => implode(' / ', array_map(fn($auth) => $auth->getInputTitle(), $internalAuths)),
        'id' => 'sign-in-form-email',
    ]) !!}
</div>

<div class="form-group">
    <div class="input-wrapper" style="position:relative">
        {!! Form::password('password', [
            'class' => 'form-control',
            'placeholder' => trans('validation.attributes.password'),
            'id' => 'sign-in-form-password',
            'style' => 'padding-right:40px',
        ]) !!}
        <span class="input-icon-right" id="toggle-password" title="Mostrar/ocultar contraseña">
            <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
            <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20"
                height="20" style="display:none">
                <path
                    d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                <line x1="1" y1="1" x2="23" y2="23" />
            </svg>
        </span>
    </div>
</div>
<script>
    (function() {
        var btn = document.getElementById('toggle-password');
        var inp = document.getElementById('sign-in-form-password');
        var eyeOn = document.getElementById('icon-eye');
        var eyeOff = document.getElementById('icon-eye-off');
        if (btn && inp) btn.addEventListener('click', function() {
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            eyeOn.style.display = show ? 'none' : '';
            eyeOff.style.display = show ? '' : 'none';
        });
    })();
</script>

@include('Frontend.Captcha.form')

@if (config('session.remember_me'))
    <div class="form-group">
        <div class="checkbox">
            {!! Form::checkbox('remember_me', 1, ['id' => 'sign-in-form-remember']) !!}
            <label>{!! trans('validation.attributes.remember_me') !!}</label>
        </div>
    </div>
@endif

<button class="btn btn-lg btn-primary btn-block" name="Submit" value="Login"
    type="Submit">{!! trans('front.sign_in') !!}</button>

<hr>

<div class="form-group">
    <div class="row">
        <div class="col-sm-12">
            <a href="{!! route('password_reminder.create') !!}" class="btn btn-block btn-lg btn-default">{!! trans('front.cant_sign_in') !!}</a>
        </div>
        <div class="col-sm-12">
            @if (settings('main_settings.allow_users_registration'))
                <a href="{!! route('registration.create') !!}" class="btn btn-block btn-lg btn-default">{!! trans('front.not_a_member') !!}</a>
            @endif
        </div>
    </div>
</div>

{!! Form::close() !!}
