<?php
if ($scope) {
    $code = $scope->getCode();
    $payload = json_encode($scope->getPayload());
} else {
    $code = 0;
    $payload = null;
}

if ($error) {
    $error_code = $error->getCode();
    $error_msg = $error->getMessage();
} else {
    $error_code = null;
    $error_msg = null;
}
?>
<html>
    <head>
        <script>
            var code =          <?=$code?>,
                payload =       <?=$payload?>,
                error_code =    '<?=$error_code?>',
                error_message = '<?=$error_msg?>';

            // Mobile Callable Function
            function loginResponse() {
                return {
                    code:       code,
                    payload:    payload,
                    error_code: error_code,
                    error_msg:  error_message
                };
            }

            // Browser Callback
            if (window.opener && window.opener.__lsrc) {
                window.opener.__lsrc(code, payload, error_code, error_message);
            }
        </script>
    </head>
    <body>
        Authentication Request Processed. Please close this window.
    </body>
</html>

