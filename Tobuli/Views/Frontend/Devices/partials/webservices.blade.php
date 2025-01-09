@php
    /** @var \Tobuli\Entities\Device $item */
@endphp

@if (Auth::User()->perm('webservice.sutran', 'edit'))
    <div class="checkbox">

        {!! Form::checkbox('mtc', 1, $item->mtc) !!}
        {!! Form::label('mtc', 'Sutran') !!}
    </div>
@endif
@if (Auth::User()->perm('webservice.osinergmin', 'edit'))
    <div class="checkbox">

        {!! Form::checkbox('osinergmin', 1, $item->osinergmin) !!}
        {!! Form::label('osinergmin', 'Osinergmin') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.mininter', 'edit'))
    <div class="checkbox">

        {!! Form::checkbox('mininter', 1, $item->mininter) !!}
        {!! Form::label('mininter', 'Mininter') !!}
    </div>
@endif

@if (Auth::User()->perm('webservice.consatel', 'edit'))
    <div class="checkbox">


        {!! Form::checkbox('consatel', 1, $item->consatel) !!}
        {!! Form::label('consatel', 'Consatel') !!}
    </div>
@endif
