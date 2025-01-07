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

        .content .verification-info {
            background-color: #f9f9f9;
            padding: 10px;
            border-radius: 5px;
            margin-top: 20px;
        }

        .content .verification-info p {
            margin: 0;
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
            <p>Por favor verifique su cuenta haciendo clic en el enlace a continuación:</p>
            <div class="verification-info">
                <p><strong>Verificacion link:</strong></p>
                [link]
            </div>
        </div>
        <div class="footer">
            <p>© [datetime] Talentus Technology EIRL.</p>
        </div>
    </div>
</body>

</html>
