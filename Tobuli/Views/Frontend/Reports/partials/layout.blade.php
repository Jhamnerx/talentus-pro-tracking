@php
    $formato = $report->getFormat();
    $esExcel = in_array($formato, ['xls', 'xlsx']);

    // En HTML el logo va por URL; en PDF wkhtmltopdf necesita la ruta de disco.
    $tieneLogo = Appearance::assetFileExists('logo');
    $logoSrc = $tieneLogo
        ? ($formato === 'html' ? Appearance::getAssetFileUrl('logo') : Appearance::getAssetFilePath('logo'))
        : null;

    $empresa = Appearance::getSetting('server_name');

    // Periodo: el reporte ya los guarda en la zona horaria del usuario
    $desde = method_exists($report, 'getDateFrom') ? $report->getDateFrom() : null;
    $hasta = method_exists($report, 'getDateTo') ? $report->getDateTo() : null;

    $titulo = method_exists($report, 'title') ? $report->title() : trans('front.report');
@endphp
<!DOCTYPE html>
<html lang="{{ Language::iso() }}" dir="{{ Language::dir() == 'rtl' ? 'RTL' : 'LTR' }}">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    @if ($esExcel)
        <title>Report</title>
    @else
        <title>{{ $empresa }}</title>
        <style><?php require base_path('public/assets/css/report.css'); ?></style>
        @yield('scripts')
        @yield('styles')
    @endif
</head>

<body class="reports">
    <div class="container">

        {{-- Cabecera común a todos los formatos salvo Excel. Antes el PDF
             llevaba el logo suelto, sin decir de quién era el reporte, de qué
             iba ni de qué fechas. --}}
        @unless ($esExcel)
            <header class="rep-head">
                @if ($logoSrc)
                    <div class="rep-head__logo">
                        <img src="{{ $logoSrc }}" alt="{{ $empresa }}">
                    </div>
                @endif

                <div class="rep-head__meta">
                    @if ($empresa)
                        <div class="rep-head__brand">{{ $empresa }}</div>
                    @endif
                    <div class="rep-head__title">{{ $titulo }}</div>
                    @if ($desde || $hasta)
                        {{-- Con Formatter, como el resto de las vistas del
                             reporte (ver partials/item_title): las fechas se
                             guardan en UTC y sin convertir la cabecera diria
                             una hora y las filas otra. --}}
                        <div class="rep-head__period">
                            {{ trans('global.from') }} {{ \Formatter::time()->human($desde) }}
                            · {{ trans('global.to') }} {{ \Formatter::time()->human($hasta) }}
                            ({{ \Formatter::time()->unit() }})
                        </div>
                    @endif
                </div>
            </header>
        @endunless

        @yield('content')

        {{-- Pie: solo en HTML. En PDF lo pone wkhtmltopdf con sus propias
             opciones (ver Report::toPDF), que es lo unico que sabe numerar
             paginas. --}}
        @if ($formato === 'html')
            <footer class="rep-foot">
                {{ trans('global.date') }}: {{ \Formatter::time()->human(now()) }}
            </footer>
        @endif

    </div>
</body>

</html>
