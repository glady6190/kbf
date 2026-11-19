<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

// ============================================
// CONTROL CENTRE: SITE SETTINGS
//
//   GET                         every value below, the active grades, the
//                               hosting server's own upload limit
//   POST {action:'save', settings: {key: value, ...}}
//
// Contact and links, Registration, Home page and Upload limits need
// control.site_settings. Maintenance mode (maintenance_mode,
// maintenance_message) needs a Super Admin. Changes are blocked while
// impersonating. Order of checks: logged in (401), CSRF on writes (403),
// permission (403). Every change is written to AuditLog with old and new.
//
// Maintenance mode is enforced by .htaccess through the flag file
// storage/maintenance.flag, written or removed here before the setting is
// saved; if that is not possible, nothing is saved.
// ============================================

$method = $_SERVER['REQUEST_METHOD'];

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
if ($method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}
requirePermission('control.site_settings');

const SITE_MAINTENANCE_FLAG = '/storage/maintenance.flag';

// key => [type, today's value, label, min, max]
const SITE_SETTINGS = [
    'contact_email' => ['email', 'admin@kbf-uk.org', 'General email'],
    'security_email' => ['email', 'security@kbf-uk.org', 'Security email'],
    'whatsapp_link' => ['whatsapp', 'https://chat.whatsapp.com/ItJTbUxRi9R56LFmkT99dp?s=sh&p=a&ilr=1', 'WhatsApp group link'],
    'site_base_url' => ['website', 'https://kbf-uk.org', 'Website address'],
    'social_facebook' => ['optionalLink', '', 'Facebook page'],
    'social_instagram' => ['optionalLink', '', 'Instagram page'],
    'registration_open' => ['switch', '1', 'Registration is open'],
    'registration_closed_message' => ['message', 'Registration is closed for now. Please check back soon.', 'Message when closed'],
    'default_grade_id' => ['grade', '', 'Default grade'],
    'min_player_age' => ['number', '10', 'Youngest age allowed', 5, 21],
    'registration_limit_per_ip_hour' => ['number', '5', 'Registrations per internet address per hour', 1, 100],
    'ticker_speed' => ['number', '50', 'Ticker speed', 20, 150],
    'sponsor_rotate_seconds' => ['number', '4', 'Seconds per sponsor', 2, 30],
    'show_promotions_strip' => ['switch', '1', 'Show the promotions strip'],
    'upload_mb_photo' => ['number', '5', 'Player photos', 1, 50],
    'upload_mb_news' => ['number', '5', 'News images', 1, 50],
    'upload_mb_gallery' => ['number', '15', 'Gallery photos', 1, 50],
    'upload_mb_poster' => ['number', '4', 'Tournament posters', 1, 50],
    'upload_mb_sponsor' => ['number', '2', 'Sponsor logos', 1, 50],
    'upload_mb_email_attachments' => ['number', '5', 'Email attachments in total', 1, 50],
    'maintenance_mode' => ['switch', '0', 'Show visitors a back-soon page'],
    'maintenance_message' => ['message', 'We are making some improvements. Please check back shortly.', 'Maintenance message'],
];
// Super Admin only: maintenance mode, and the website address (every email
// link, including password reset links, is built from it)
const SITE_SUPER_ADMIN_KEYS = ['maintenance_mode', 'maintenance_message', 'site_base_url'];

// ---------- helpers ----------

// "8M" -> bytes (php.ini shorthand)
function iniBytes($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $n = (float) $value;
    switch ($unit) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return (int) $n;
}

// The hosting server's own limit: the smaller of upload_max_filesize and post_max_size
function serverUploadLimitMb() {
    $limits = array_filter([iniBytes(ini_get('upload_max_filesize')), iniBytes(ini_get('post_max_size'))], function ($v) { return $v > 0; });
    if (!$limits) {
        return null;
    }
    return round(min($limits) / 1048576, 1);
}

function currentValue($key) {
    $def = SITE_SETTINGS[$key];
    if ($key === 'default_grade_id') {
        global $pdo;
        return (string) (kbfDefaultGradeId($pdo) ?? '');
    }
    return (string) kbfSetting($key, $def[1]);
}

function maintenanceFlagPath() {
    return dirname(__DIR__) . SITE_MAINTENANCE_FLAG;
}

function textLength($text) {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

// Validate one value; returns [cleanValue, error]
function validateSetting($key, $value) {
    global $pdo;
    $def = SITE_SETTINGS[$key];
    $label = $def[2];
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    if (!is_scalar($value)) {
        return [null, $label . ' is not valid.'];
    }
    $value = trim((string) $value);

    switch ($def[0]) {
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) && strlen($value) <= 255
                ? [$value, null] : [null, $label . ' must be a valid email address.'];
        case 'whatsapp':
            return preg_match('#^https://chat\.whatsapp\.com/[^\s"\'<>]+$#', $value) && strlen($value) <= 500
                ? [$value, null] : [null, $label . ' must start with https://chat.whatsapp.com/'];
        case 'website':
            if (substr($value, -1) === '/') {
                return [null, $label . ' must not end with a slash.'];
            }
            return kbfIsValidSiteBaseUrl($value) && filter_var($value, FILTER_VALIDATE_URL)
                ? [$value, null] : [null, $label . ' must be an https address with no path or query, for example https://kbf-uk.org'];
        case 'optionalLink':
            if ($value === '') {
                return ['', null];
            }
            return preg_match('#^https://[^\s"\'<>]+$#i', $value) && filter_var($value, FILTER_VALIDATE_URL) && strlen($value) <= 500
                ? [$value, null] : [null, $label . ' must be an https address, or left empty.'];
        case 'switch':
            return in_array($value, ['0', '1'], true) ? [$value, null] : [null, $label . ' must be on or off.'];
        case 'message':
            $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $value);
            if ($value === '') {
                return [null, $label . ' cannot be empty.'];
            }
            return textLength($value) <= 300 ? [$value, null] : [null, $label . ' can be up to 300 characters.'];
        case 'number':
            if (!preg_match('/^\d{1,5}$/', $value)) {
                return [null, $label . ' must be a whole number from ' . $def[3] . ' to ' . $def[4] . '.'];
            }
            $n = (int) $value;
            return ($n >= $def[3] && $n <= $def[4]) ? [(string) $n, null]
                : [null, $label . ' must be from ' . $def[3] . ' to ' . $def[4] . '.'];
        case 'grade':
            foreach (kbfActiveGrades($pdo) as $g) {
                if ((string) $g['CategoryId'] === $value) {
                    return [$value, null];
                }
            }
            return [null, 'Choose an active grade as the default grade.'];
    }
    return [null, $label . ' is not valid.'];
}

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    try {
        $values = [];
        foreach (array_keys(SITE_SETTINGS) as $key) {
            $values[$key] = currentValue($key);
        }
        $grades = array_map(function ($g) {
            return ['id' => (int) $g['CategoryId'], 'shortName' => (string) $g['ShortName'], 'name' => (string) $g['Name']];
        }, kbfActiveGrades($pdo));

        sendJSON([
            'success' => true,
            'settings' => $values,
            'grades' => $grades,
            'serverUploadLimitMb' => serverUploadLimitMb(),
            'isSuperAdmin' => isSuperAdmin(),
            'maintenanceFlagOn' => is_file(maintenanceFlagPath()),
            'canEdit' => !isImpersonating(),
        ]);
    } catch (PDOException $e) {
        error_log('control-site.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST
// ============================================
if ($method === 'POST') {
    requireNotImpersonating();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || ($data['action'] ?? '') !== 'save' || !is_array($data['settings'] ?? null)) {
        sendJSON(['success' => false, 'message' => 'Invalid request body'], 400);
    }

    // Validate everything first
    $new = [];
    foreach ($data['settings'] as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, SITE_SETTINGS)) {
            continue;
        }
        list($clean, $error) = validateSetting($key, $value);
        if ($error !== null) {
            sendJSON(['success' => false, 'message' => $error, 'field' => $key], 400);
        }
        $new[$key] = $clean;
    }

    // What actually changes
    $changes = [];
    foreach ($new as $key => $value) {
        $old = currentValue($key);
        if ($old !== $value) {
            $changes[$key] = [$old, $value];
        }
    }
    if (!$changes) {
        sendJSON(['success' => true, 'message' => 'Nothing to change']);
    }

    // Maintenance mode and the website address: Super Admins only
    if (isset($changes['site_base_url']) && !isSuperAdmin()) {
        sendJSON(['success' => false, 'message' => 'Only a Super Admin can change the website address.', 'field' => 'site_base_url'], 403);
    }
    if (array_intersect(array_keys($changes), SITE_SUPER_ADMIN_KEYS) && !isSuperAdmin()) {
        sendJSON(['success' => false, 'message' => 'Only a Super Admin can change maintenance mode.'], 403);
    }

    // Switch the flag file first; if that is impossible, save nothing
    if (isset($changes['maintenance_mode'])) {
        $flag = maintenanceFlagPath();
        if ($changes['maintenance_mode'][1] === '1') {
            $ok = @file_put_contents($flag, 'Maintenance mode switched on ' . date('Y-m-d H:i:s') . "\n") !== false;
            $problem = 'The back-soon page could not be switched on: the server would not let the site write storage/maintenance.flag. Nothing was saved.';
        } else {
            $ok = !is_file($flag) || @unlink($flag);
            $problem = 'The back-soon page could not be switched off: the server would not let the site remove storage/maintenance.flag. Nothing was saved.';
        }
        if (!$ok) {
            error_log('control-site.php: maintenance flag change failed');
            sendJSON(['success' => false, 'message' => $problem, 'field' => 'maintenance_mode'], 500);
        }
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO Settings (SettingKey, SettingValue) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
        ");
        foreach ($changes as $key => $pair) {
            $stmt->execute([$key, $pair[1]]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Put the flag file back the way it was
        if (isset($changes['maintenance_mode'])) {
            $flag = maintenanceFlagPath();
            if ($changes['maintenance_mode'][1] === '1') {
                @unlink($flag);
            } else {
                @file_put_contents($flag, 'Maintenance mode restored ' . date('Y-m-d H:i:s') . "\n");
            }
        }
        error_log('control-site.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred. Nothing was saved.'], 500);
    }

    foreach ($changes as $key => $pair) {
        $oldText = $pair[0] === '' ? '(empty)' : $pair[0];
        $newText = $pair[1] === '' ? '(empty)' : $pair[1];
        auditLog($key === 'maintenance_mode' ? 'maintenance_mode_changed' : 'setting_changed', 'setting', null,
            $key . ': ' . $oldText . ' -> ' . $newText);
    }
    kbfSettingsForget();

    sendJSON([
        'success' => true,
        'message' => 'Changes saved. They apply straight away.',
        'changed' => array_keys($changes),
    ]);
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
?>
