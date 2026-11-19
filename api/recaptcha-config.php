<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
/**
 * Google reCAPTCHA Configuration
 * The secret key comes from .env (RECAPTCHA_SECRET_KEY) and is never sent
 * to the browser. The public site key is written in register.html
 * (data-sitekey); when the keys are changed in Google's console, update
 * both .env and register.html.
 */

return [
    'secret_key' => getenv('RECAPTCHA_SECRET_KEY'),
];
?>