{{--
    Campos personalizados del objeto — paridad con Frontend/CustomFields/panel.blade.php
    -----------------------------------------------------------------------------------
    Mismos `name=` que el legacy (custom_fields[<slug>]) para que
    DeviceService::update() los guarde por CustomValuesService sin cambios.
    El tab solo se monta si el addon está activo, el usuario tiene el permiso
    device.custom_fields y la entidad tiene campos definidos (ver form.blade.php).
--}}
@php
    $customFields = $item->customFields()->get();
@endphp

<div class="tp-form__grid tp-form__grid--2">
    @foreach ($customFields as $field)
        @php
            $name = "custom_fields[{$field->slug}]";
            $value = $item->exists ? $item->getCustomValue($field->slug) : $field->defaultValue;
        @endphp

        @if ($field->data_type === 'boolean')
            <div class="tp-form__field">
                <input type="hidden" name="{{ $name }}" value="0">
                <label class="tp-choice">
                    <input type="checkbox" class="tp-choice-input" name="{{ $name }}" value="1"
                        {{ $value ? 'checked' : '' }}>
                    <span>{{ $field->title }}</span>
                </label>
                @if (!empty($field->description))
                    <small class="tp-form__hint">{{ $field->description }}</small>
                @endif
            </div>
        @else
            <label class="tp-form__field">
                <span class="tp-form__label">{{ $field->title }}</span>

                @if ($field->data_type === 'select')
                    <select class="tp-input" name="{{ $name }}">
                        @foreach ($field->options ?? [] as $optValue => $optLabel)
                            <option value="{{ $optValue }}" {{ (string) $value === (string) $optValue ? 'selected' : '' }}>
                                {{ $optLabel }}</option>
                        @endforeach
                    </select>
                @elseif ($field->data_type === 'multiselect')
                    @php $selected = is_array($value) ? array_map('strval', $value) : []; @endphp
                    <select class="tp-input" name="{{ $name }}[]" multiple size="4">
                        @foreach ($field->options ?? [] as $optValue => $optLabel)
                            <option value="{{ $optValue }}" {{ in_array((string) $optValue, $selected, true) ? 'selected' : '' }}>
                                {{ $optLabel }}</option>
                        @endforeach
                    </select>
                @elseif ($field->data_type === 'date')
                    @php
                        $dateValue = !$item->exists || $value === '0000-00-00' ? $field->defaultValue : $value;
                    @endphp
                    {{-- type=date: tp-datepicker.js lo enhancea automáticamente --}}
                    <input type="date" class="tp-input" name="{{ $name }}" value="{{ $dateValue }}"
                        autocomplete="off">
                @elseif ($field->data_type === 'datetime')
                    @php
                        $dtRaw = !$item->exists || is_null($value) ? $field->defaultValue : $value;
                        $dtValue = $dtRaw ? date('Y-m-d\TH:i', strtotime($dtRaw)) : '';
                    @endphp
                    <input type="datetime-local" class="tp-input" name="{{ $name }}" value="{{ $dtValue }}"
                        autocomplete="off">
                @else
                    <input type="text" class="tp-input" name="{{ $name }}" value="{{ $value }}"
                        autocomplete="off">
                @endif

                @if (!empty($field->description))
                    <small class="tp-form__hint">{{ $field->description }}</small>
                @endif
            </label>
        @endif
    @endforeach
</div>
