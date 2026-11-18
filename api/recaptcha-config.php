<?php
/**
 * Google reCAPTCHA Configuration
 * IMPORTANT: Keep these keys secret! Add this file to .gitignore
 */

return [
    'site_key'   => getenv('RECAPTCHA_SITE_KEY'),
    'secret_key' => getenv('RECAPTCHA_SECRET_KEY'),
];
?>