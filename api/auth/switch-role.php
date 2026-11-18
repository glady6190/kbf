<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// ============================================
// ROLE SWITCHING - NO LONGER USED
// Access now comes from per-user permissions, so there is nothing to switch.
// This endpoint changes nothing. The script that called it is removed in
// Stage 3b.
// ============================================

sendJSON([
    'success' => false,
    'message' => 'Role switching is no longer used.'
], 410);
?>
