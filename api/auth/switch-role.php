<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// ============================================
// ROLE SWITCHING - NO LONGER USED
// Access now comes from per-user permissions, so there is nothing to switch.
// This endpoint changes nothing and nothing on the site calls it. It is
// kept deliberately as a 410 stub so an old address gets a clear answer.
// ============================================

sendJSON([
    'success' => false,
    'message' => 'Role switching is no longer used.'
], 410);
?>
