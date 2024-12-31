{!! Form::open(array('route' => 'authentication.store', 'class' => 'form')) !!}

<div class="form-group">
    {!! Form::email('email', null, ['class' => 'form-control', 'placeholder' => trans('validation.attributes.email'), 'id' => 'sign-in-form-email']) !!}
</div>

<div class="form-group">
    {!! Form::password('password', ['class' => 'form-control', 'placeholder' => trans('validation.attributes.password'), 'id' => 'sign-in-form-password']) !!}
</div>

@include('Frontend.Captcha.form')

@if (config('session.remember_me'))
    <div class="form-group">
        <div class="checkbox">
            {!! Form::checkbox('remember_me', 1, ['id' => 'sign-in-form-remember']) !!}
            <label>{!! trans('validation.attributes.remember_me') !!}</label>
        </div>
    </div>
@endif

<button class="btn btn-lg btn-primary btn-block" name="Submit" value="Login" type="Submit">{!! trans('front.sign_in') !!}</button>

<hr>

<div class="form-group">
    <div class="row">
        <div class="col-sm-12">
            <a href="{!! route('password_reminder.create') !!}"
               class="btn btn-block btn-lg btn-default">{!! trans('front.cant_sign_in') !!}</a>
        </div>
        <div class="col-sm-12">
            @if (settings('main_settings.allow_users_registration'))
                <a href="{!! route('registration.create') !!}"
                   class="btn btn-block btn-lg btn-default">{!! trans('front.not_a_member') !!}</a>
            @endif
        </div>
    </div>
</div>

{!! Form::close() !!}
