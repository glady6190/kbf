<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

// ============================================
// CLUBS API (RETIRED)
// Clubs are no longer used: tournaments record a free-text organiser name.
// Every request (read, create, edit, delete) returns HTTP 410 Gone.
// The Clubs table, its rows and the ClubId columns are kept as they are.
// ============================================

sendJSON([
    'success' => false,
    'message' => 'Clubs are no longer used. Tournaments now record an organiser name.'
], 410);
