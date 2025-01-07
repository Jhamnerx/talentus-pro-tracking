<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            background-color: #f4f4f4;
            padding: 20px;
        }

        .container {
            background-color: #ffffff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            display: inline-block;
            max-width: 600px;
            margin: 20px auto;
        }

        img {
            width: 100px;
            height: auto;
            margin-bottom: 20px;
        }

        .message {
            font-size: 18px;
            color: #333333;
            margin-bottom: 20px;
        }

        .details {
            font-size: 16px;
            color: #555555;
            text-align: left;
            margin-bottom: 20px;
        }

        .details span {
            font-weight: bold;
        }

        .footer {
            font-size: 14px;
            color: #888888;
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        [logo]
        <div class="message">
            ¡Bienvenido a TALENTUS TECHNOLOGY TRACKER!
        </div>
        <div class="details">
            <p>Su cuenta ha sido creada exitosamente. A continuación, encontrará sus datos de inicio de sesión:</p>
            <p><span>Usuario:</span> [email]</p>
            <p><span>Contraseña:</span> [password]</p>
            <p><span>Plataforma:</span> <a href="{{ config('app.url') }}" target="_blank">{{ config('app.url') }}</a></p>
        </div>
        <div class="message">
            ¡Esperamos que disfrute de nuestros servicios!
        </div>
        <div class="footer">
            Si tiene alguna pregunta, no dude en ponerse en contacto con nuestro equipo de soporte.
        </div>
    </div>
</body>

</html>
