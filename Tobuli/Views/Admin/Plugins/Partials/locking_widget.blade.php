<div class="form-group">
    {!! Form::label('parameter', 'Parameter') !!}
    {!! Form::text('plugins['.$plugin->key.'][options][parameter]', $plugin->options['parameter'], ['class' => 'form-control']) !!}
</div>
<div class="form-group">
    {!! Form::label('value_on', 'Value ON') !!}
    {!! Form::text('plugins['.$plugin->key.'][options][value_on]', $plugin->options['value_on'], ['class' => 'form-control']) !!}
</div>
<div class="form-group">
    {!! Form::label('value_off', 'Value OFF') !!}
    {!! Form::text('plugins['.$plugin->key.'][options][value_off]', $plugin->options['value_off'], ['class' => 'form-control']) !!}
</div>
<p class="text-muted" style="margin-top:8px;">Los comandos de Cortar / Restablecer del widget v2 se toman de las <b>Plantillas de GPRS</b> del usuario, filtradas por protocolo/tipo del equipo. El comando también puede escribirse a mano en el modal.</p>

