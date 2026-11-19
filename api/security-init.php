<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
/**
 * Security Initialization
 * Handles HTTPS enforcement and additional security policies
 * (Basic security headers are handled by config.php)
 */
// ============================================
// FORCE HTTPS - CRITICAL SECURITY
// Done in config.php (loaded next by every endpoint), which redirects to the
// Website address from Site settings (site_base_url). Redirects and links are
// never built from the request's Host header.
// ============================================

// Never show PHP errors to visitors; log them instead
ini_set('display_errors', '0');
ini_set('log_errors', '1');

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