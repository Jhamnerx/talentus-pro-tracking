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
        .header {
            text-align: center;
            padding: 10px 0;
        }
        .header img {
            max-width: 150px;
        }
        .content {
            padding: 20px;
            text-align: left;
        }
        .content h1 {
            font-size: 24px;
            color: #333333;
        }
        .content p {
            font-size: 16px;
            color: #666666;
            line-height: 1.5;
        }
        .alert-info {
            background-color: #ffeded;
            padding: 10px;
            border-radius: 5px;
            margin-top: 20px;
            border-left: 5px solid #ff4d4d;
        }
        .alert-info h2 {
            font-size: 20px;
            color: #ff4d4d;
            margin: 0;
            display: flex;
            align-items: center;
        }
        .alert-info h2 svg {
            margin-right: 10px;
        }
        .alert-info p {
            margin: 5px 0;
            font-size: 16px;
        }
        .footer {
            text-align: center;
            padding: 10px 0;
            font-size: 12px;
            color: #aaaaaa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
          [logo]
        </div>
        <div class="content">
            <h1>Hola,</h1>
            <div class="alert-info">
                <h2>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 0C18.6274 0 24 5.37258 24 12C24 18.6274 18.6274 24 12 24C5.37258 24 0 18.6274 0 12C0 5.37258 5.37258 0 12 0Z" fill="#FF4D4D"/>
                        <path d="M11 17H13V19H11V17ZM11 5H13V15H11V5Z" fill="white"/>
                    </svg>
                    Notificación de Alerta
                </h2>
                <p><strong>Evento:</strong> [event]</p>
                <p><strong>Geocerca:</strong> [geofence]</p>
                <p><strong>Dispositivo:</strong> [device.name] | [device.plate_number]</p>
                <p><strong>Dirección:</strong> [address]</p>
                <p><strong>Posición:</strong> [position]</p>
                <p><strong>Altitud:</strong> [altitude]</p>
                <p><strong>Velocidad:</strong> [speed]</p>
                <p><strong>Hora:</strong> [time]</p>
            </div>
            <p><a href="[preview]">Vista previa en Google Maps</a></p>
        </div>
        <div class="footer">
            <p>© [datetime] Talentus Technology EIRL. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>