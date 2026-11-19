<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
/**
 * Google reCAPTCHA Configuration
 * IMPORTANT: Keep these keys secret! Add this file to .gitignore
 */

return [
    'site_key'   => getenv('RECAPTCHA_SITE_KEY'),
    'secret_key' => getenv('RECAPTCHA_SECRET_KEY'),
];
?>