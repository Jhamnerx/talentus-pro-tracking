<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
            text-align: center;
        }

        .card {
            background-color: #ffffff;
            padding: 0;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            margin: 20px auto;
            overflow: hidden;
        }

        .card-header {
            background-color: #007bff;
            color: white;
            padding: 20px;
            font-size: 20px;
            font-weight: bold;
            text-align: center;
        }

        .card-body {
            padding: 20px;
            text-align: left;
        }

        .details {
            font-size: 16px;
            color: #555555;
            margin-bottom: 20px;
        }

        .details span {
            font-weight: bold;
        }

        .footer {
            font-size: 14px;
            color: #888888;
            margin-top: 20px;
            text-align: center;
            padding: 10px;
            background-color: #f4f4f4;
            border-top: 1px solid #eeeeee;
        }

        a {
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="card-header">
            ¡Aviso de Expiración de Servicio!
        </div>
        <div class="card-body">
            <p><span>Dispositivo:</span> [device.name]</p>
            <p><span>Servicio:</span> [service.name]</p>
            <p><span>Descripción:</span> [service.description]</p>
            <p><span>Tiempo restante:</span> [service.left] - [service.expires]</p>
        </div>
        <div class="footer">
            Si tienes alguna pregunta, no dudes en <a href="mailto:soporte@tuempresa.com">contactar al soporte</a>.
        </div>
    </div>
</body>

</html>