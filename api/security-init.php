<?php
/**
 * Security Initialization
 * Handles HTTPS enforcement and additional security policies
 * (Basic security headers are handled by config.php)
 */
// ============================================
// FORCE HTTPS - CRITICAL SECURITY
// ============================================
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI'])) {
        $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirect, true, 301);
        exit;
    }
}

// ============================================
// CONTENT SECURITY POLICY
// This is NOT in config.php, so safe to add here
// ============================================
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://www.google.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-src https://www.google.com;");

// ============================================
// PREVENT CACHING OF SENSITIVE API RESPONSES
// ============================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// ============================================
// ADDITIONAL SECURITY HEADERS
// (Permissions Policy for modern browsers)
// ============================================
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// ============================================
// NOTE: The following headers are set by config.php:
// - X-Frame-Options
// - X-Content-Type-Options
// - X-XSS-Protection
// - Referrer-Policy
// - CORS headers
// - Content-Type (set by sendJSON() function)
// ============================================

// ============================================
// Access checks: requireAuth() is in config.php; hasPermission(),
// requirePermission() and requireSuperAdmin() are in permissions.php.
// Roles no longer decide access. No need to redeclare them here
// ============================================
?>