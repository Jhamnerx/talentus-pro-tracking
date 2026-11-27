<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .content {
            padding: 20px;
            text-align: left;
        }

        .content h1 {
            font-size: 22px;
            color: #0e2157;
        }

        .content p {
            font-size: 15px;
            color: #666666;
            line-height: 1.5;
        }

        .code-box {
            text-align: center;
            background-color: #f2f5fd;
            border: 1px dashed #8faef1;
            border-radius: 8px;
            padding: 18px;
            margin: 24px 0;
        }

        .code-box .code {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 8px;
            color: #0e2157;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #999999;
            padding-top: 16px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="content">
            <h1>Confirma tu correo electrónico</h1>
            <p>Usa el siguiente código único para confirmar tu dirección de correo en la plataforma:</p>
            <div class="code-box">
                {{-- El placeholder [code] lo reemplaza el TemplateBuilder tras renderizar --}}
                <span class="code">[code]</span>
            </div>
            <p>Si no solicitaste este código, puedes ignorar este mensaje.</p>
        </div>
        <div class="footer">
            Este es un mensaje automático — no respondas a este correo.
        </div>
    </div>
</body>

</html>
