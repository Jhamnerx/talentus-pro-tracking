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

        .attachment-info {
            background-color: #e5f5ff;
            padding: 10px;
            border-radius: 5px;
            margin-top: 20px;
            border-left: 5px solid #4daaff;
        }

        .attachment-info h2 {
            font-size: 20px;
            color: #4daaff;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .attachment-info h2 svg {
            margin-right: 10px;
        }

        .attachment-info p {
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
            <p>Aquí está el reporte solicitado:</p>
            <div class="attachment-info">
                <h2>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M12 0C18.6274 0 24 5.37258 24 12C24 18.6274 18.6274 24 12 24C5.37258 24 0 18.6274 0 12C0 5.37258 5.37258 0 12 0Z"
                            fill="#4DAAFF" />
                        <path d="M11 17H13V19H11V17ZM11 5H13V15H11V5Z" fill="white" />
                    </svg>
                    Información del Archivo Adjunto
                </h2>
                <p><strong>Nombre:</strong> [name]</p>
                <p><strong>Periodo:</strong> [period]</p>
            </div>
        </div>
        <div class="footer">
            <p>&copy; [datetime] Talentus Technology EIRL. Todos los derechos reservados.</p>
        </div>
    </div>
</body>

</html>
