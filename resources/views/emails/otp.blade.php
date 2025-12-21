<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Verification Code</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 32px;
        }
        .code-box {
            background-color: #ffffff;
            border: 2px dashed #6366f1;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            margin: 24px 0;
        }
        .code {
            font-size: 36px;
            font-weight: bold;
            letter-spacing: 8px;
            color: #4f46e5;
            font-family: monospace;
        }
        .expiry {
            color: #6b7280;
            font-size: 14px;
            margin-top: 16px;
        }
        .warning {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            margin-top: 24px;
            font-size: 14px;
            color: #92400e;
        }
        h1 {
            color: #111827;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Hello, {{ $userName }}!</h1>
        <p>You have requested a verification code to authenticate with our loyalty points platform.</p>

        <div class="code-box">
            <div class="code">{{ $code }}</div>
            <p class="expiry">This code expires at {{ $expiresAt->format('H:i') }} ({{ $expiresAt->diffForHumans() }})</p>
        </div>

        <p>Enter this code in the application to complete your authentication.</p>

        <div class="warning">
            <strong>Security Notice:</strong> If you did not request this code, please ignore this email. Do not share this code with anyone.
        </div>
    </div>
</body>
</html>
