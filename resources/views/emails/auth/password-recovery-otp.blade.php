<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mã xác thực Mizuki</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
    <div style="max-width: 560px; margin: 0 auto; padding: 24px;">
        <h1 style="color: #d96b8b;">Mizuki</h1>
        <p>Bạn vừa yêu cầu khôi phục mật khẩu tài khoản Mizuki.</p>
        <p>Mã xác thực của bạn là:</p>
        <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #d96b8b;">{{ $code }}</p>
        <p>Mã có hiệu lực trong {{ $expiresInMinutes }} phút.</p>
        <p><strong>Không chia sẻ mã này với bất kỳ ai.</strong></p>
        <p>Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email.</p>
    </div>
</body>
</html>