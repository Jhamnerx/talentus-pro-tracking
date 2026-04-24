@php
    /** @var \Tobuli\Entities\Device $item */
@endphp

@if (Auth::User()->perm('webservice.sutran', 'edit'))
    <div class="checkbox">
        {!! Form::hidden('mtc', 0) !!}
        {!! Form::checkbox('mtc', 1, $item->mtc) !!}
        {!! Form::label('mtc', 'Sutran') !!}
    </div>
@endif
@if (Auth::User()->perm('webservice.osinergmin', 'edit'))
    <div class="checkbox">
        {!! Form::hidden('osinergmin', 0) !!}
        {!! Form::checkbox('osinergmin', 1, $item->osinergmin) !!}
        {!! Form::label('osinergmin', 'Osinergmin') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.mininter', 'edit'))
    <div class="checkbox">
        {!! Form::hidden('mininter', 0) !!}
        {!! Form::checkbox('mininter', 1, $item->mininter) !!}
        {!! Form::label('mininter', 'Mininter') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.consatel', 'edit'))
    <div class="checkbox">

        {!! Form::hidden('consatel', 0) !!}
        {!! Form::checkbox('consatel', 1, $item->consatel) !!}
        {!! Form::label('consatel', 'Consatel') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.wisetrack', 'edit'))
    <div class="checkbox">

        {!! Form::hidden('wisetrack', 0) !!}
        {!! Form::checkbox('wisetrack', 1, $item->wisetrack) !!}
        {!! Form::label('wisetrack', 'Wisetrack') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.sidersales', 'edit'))
    <div class="checkbox">
        {!! Form::hidden('sidersales', 0) !!}
        {!! Form::checkbox('sidersales', 1, $item->sidersales) !!}
        {!! Form::label('sidersales', 'Sidersales (Gerdau)') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.epscenter', 'edit'))
    <div class="checkbox">
        {!! Form::hidden('epscenter', 0) !!}
        {!! Form::checkbox('epscenter', 1, $item->epscenter) !!}
        {!! Form::label('epscenter', 'EPS Center') !!}
    </div>
@endif
