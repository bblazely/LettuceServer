<html>
<body>
<div style="padding: 10px; border: 1px solid #888; margin:20px; background-color: #eee; box-shadow: 2px 2px 2px black; border-radius: 5px">
    <h1>Password Reset Request</h1>
    <p>Someone requested a password reset for your account <?=$scope['email_address']?></p>

    <p>If you didn't request the reset, please ignore this email.</p>

    <p><a href="https://apps.localhost/lettuce/v1/user/verify_pw_reset/<?=$scope['email_address']?>/<?=$scope['verification_expiration']?>/<?=$scope['verification_code']?>"
          style="border-radius:4px;background-color:#08c;border:0;font-size:20px;padding:10px 16px;color:#fff;text-decoration:none; margin: 10px 0">
            Confirm Password Reset
        </a></p>
    <p>Thanks!</p>
</div>
</body>
</html>