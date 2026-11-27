{{--
    Icono de sensor — SVG de trazo, monocromo, coherente con el resto de la UI v2.
    ------------------------------------------------------------------------------
    Los SVG del tema legacy (assets/icons/sensors_*.svg) traen colores fijos
    embebidos (fill="#6AD09D"…), así que en el widget se veían como manchas de
    colores sueltos. Estos heredan currentColor y encajan con la tipografía.

    Uso:  @include('ui.v2.partials.sensor-icon', ['type' => $sensor['type']])
--}}
@php
    $t = $type ?? '';

    // Tipos que comparten dibujo
    $alias = [
        'battery_external' => 'battery',
        'fuel_tank' => 'fuel',
        'fuel_tank_calibration' => 'fuel',
        'fuel_consumption' => 'fuel',
        'temperature_calibration' => 'temperature',
        'load_calibration' => 'load',
        'route_color_2' => 'route_color',
        'route_color_3' => 'route_color',
        'drive_business' => 'drive',
        'drive_private' => 'drive',
    ];
    $t = $alias[$t] ?? $t;

    $icons = [
        'battery' => '<rect x="2" y="7" width="16" height="10" rx="2"/><path d="M20 10v4"/>',
        'blocked' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'engine' => '<path d="M6 12h2l2-3h4l2 3h2"/><rect x="3" y="9" width="18" height="9" rx="2"/><path d="M8 9V6h8v3"/>',
        'ignition' => '<path d="M12 3v9"/><path d="M7.5 6.5a7 7 0 1 0 9 0"/>',
        'logical' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'satellites' => '<path d="M12 2a10 10 0 0 1 10 10"/><path d="M12 8a4 4 0 0 1 4 4"/><circle cx="7" cy="17" r="3"/>',
        'odometer' => '<circle cx="12" cy="12" r="9"/><path d="M12 12l4-3"/><path d="M12 21v-2M3 12h2M19 12h2"/>',
        'textual' => '<path d="M4 7h16M4 12h10M4 17h7"/>',
        'numerical' => '<path d="M6 4v16M14 4v16M3 9h18M3 15h18"/>',
        'counter' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M8 6v12M16 6v12"/>',
        'engine_hours' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'fuel' => '<path d="M4 20V5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v15"/><path d="M3 20h11"/><path d="M16 8h2a2 2 0 0 1 2 2v6a2 2 0 0 0 2 2"/>',
        'temperature' => '<path d="M14 14V5a2 2 0 1 0-4 0v9a4 4 0 1 0 4 0z"/>',
        'gsm' => '<path d="M5 18v-4M10 18v-8M15 18v-11M20 18V6"/>',
        'speed_ecm' => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M12 4v2M4.9 7.9l1.4 1.4M2 15h2M20 15h2M17.7 9.3l1.4-1.4"/>',
        'tachometer' => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M12 4v2M4.9 7.9l1.4 1.4M2 15h2M20 15h2M17.7 9.3l1.4-1.4"/>',
        'load' => '<path d="M3 17V9l9-5 9 5v8l-9 5-9-5z"/><path d="M3 9l9 5 9-5"/>',
        'door' => '<rect x="5" y="3" width="14" height="18" rx="1"/><circle cx="15" cy="12" r="1"/>',
        'seatbelt' => '<path d="M5 4l14 16"/><path d="M19 4L9 20"/>',
        'acc' => '<circle cx="12" cy="12" r="9"/><path d="M9 12h6M12 9v6"/>',
        'rfid' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M8 12h.01M12 10v4M16 9v6"/>',
        'anonymizer' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><path d="M4 4l16 16"/>',
        'plugged' => '<path d="M9 3v6M15 3v6"/><path d="M6 9h12v3a6 6 0 0 1-12 0V9z"/><path d="M12 18v3"/>',
        'route_color' => '<circle cx="6" cy="18" r="2"/><circle cx="18" cy="6" r="2"/><path d="M8 17c6-1 8-4 8-9"/>',
        'drive' => '<circle cx="12" cy="12" r="9"/><path d="m9 15 6-6M15 9v4"/>',
        'datetime' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'harsh_acceleration' => '<path d="m13 2-9 12h7l-1 8 9-12h-7l1-8z"/>',
        'harsh_breaking' => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
        'harsh_turning' => '<path d="M4 20V10a4 4 0 0 1 4-4h8"/><path d="m13 3 3 3-3 3"/>',
        'vin' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h2l1 4 1-4h2M15 10v4"/>',
    ];

    // Sin dibujo propio: punto neutro, nunca un icono de otro sensor
    $path = $icons[$t] ?? '<circle cx="12" cy="12" r="3"/>';
@endphp
<svg class="tp-sensor-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
    stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $path !!}</svg>
