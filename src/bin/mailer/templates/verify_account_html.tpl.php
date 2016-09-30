<html>
<body>
    <div style="padding: 10px; border: 1px solid #888; margin:20px; background-color: #eee; box-shadow: 2px 2px 2px black; border-radius: 5px">
        <h1>Registration Verification</h1>
        <p>Yay this is the registration template in html!</p>

        <p>You registered for an account with the address <?=$scope['email_address']?></p>

        <p><a href="https://apps.localhost/lettuce/v1/user/verification/native/verify/<?=$scope['email_address']?>/<?=$scope['verification_data']?>/<?=$scope['verification_code']?>"
           style="border-radius:4px;background-color:#08c;border:0;font-size:20px;padding:10px 16px;color:#fff;text-decoration:none; margin: 10px 0">
           Confirm Email Address
        </a></p>
        <p>Thanks!</p>
    </div>
</body>
</html>