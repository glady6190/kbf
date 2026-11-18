<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/grading.php';

// ============================================
// PUBLIC SETTINGS (no login)
// Only these public values, for the public pages:
//   points per placing, each active grade's promotion threshold (null for the
//   top grade), the season start months, the floor trigger and value, the
//   current season's name and next reset date, the contact email, the social
//   links, whether registration is open (and its message, youngest birth year
//   and photo limit), the home page ticker speed, sponsor seconds and
//   promotions strip switch, the maintenance message and whether maintenance
//   mode is on. Nothing private.
// ============================================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $grades = [];
    foreach (kbfActiveGrades($pdo) as $i => $g) {
        $threshold = (int) $g['PromotionThreshold'];
        $grades[] = [
            'shortName' => (string) $g['ShortName'],
            'name' => (string) $g['Name'],
            'rank' => $i + 1,
            'promotionThreshold' => $i === 0 ? null : ($threshold > 0 ? $threshold : KBF_DEFAULT_THRESHOLD),
        ];
    }

    $season = kbfSeasonSettings($pdo);
    $plan = kbfSeasonPlan($pdo, $season, null, false);

    // Only https links are passed on
    $httpsOrEmpty = function ($key) {
        $v = trim((string) kbfSetting($key, ''));
        return preg_match('#^https://[^\s"\'<>]+$#i', $v) ? $v : '';
    };
    $contactEmail = trim((string) kbfSetting('contact_email', 'admin@kbf-uk.org'));
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $contactEmail = 'admin@kbf-uk.org';
    }

    sendJSON([
        'success' => true,
        'contact' => ['email' => $contactEmail],
        'social' => [
            'facebook' => $httpsOrEmpty('social_facebook'),
            'instagram' => $httpsOrEmpty('social_instagram'),
        ],
        'registration' => [
            'open' => kbfSettingInt('registration_open', 1, 0, 1) === 1 && kbfSettingInt('maintenance_mode', 0, 0, 1) === 0,
            'closedMessage' => (string) kbfSetting('registration_closed_message', 'Registration is closed for now. Please check back soon.'),
            'latestBirthYear' => kbfLatestBirthYear(),
            'photoLimitMb' => kbfSettingInt('upload_mb_photo', 5, 1, 50),
        ],
        'home' => [
            'tickerSpeed' => kbfSettingInt('ticker_speed', 50, 20, 150),
            'sponsorRotateSeconds' => kbfSettingInt('sponsor_rotate_seconds', 4, 2, 30),
            'showPromotionsStrip' => kbfSettingInt('show_promotions_strip', 1, 0, 1) === 1,
        ],
        'maintenanceMessage' => (string) kbfSetting('maintenance_message', 'We are making some improvements. Please check back shortly.'),
        // Whether maintenance mode is on (the staff sign-in page says so)
        'maintenanceMode' => kbfSettingInt('maintenance_mode', 0, 0, 1) === 1,
        'pointsPerPlacing' => kbfPlacingPoints($pdo),
        'grades' => $grades,
        'season' => [
            'startMonths' => $season['months'],
            'startMonthNames' => array_map(function ($m) { return KBF_MONTH_NAMES[$m - 1]; }, $season['months']),
            'floorTrigger' => $season['trigger'],
            'floorValue' => $season['value'],
            'currentName' => $plan['season']['name'],
            'nextResetDate' => $plan['season']['nextResetDate'],
            'nextResetLabel' => kbfDateLabel($plan['season']['nextResetDate']),
        ],
    ]);
} catch (PDOException $e) {
    error_log('public-settings.php: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Service temporarily unavailable'], 500);
}
?>
