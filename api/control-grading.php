<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

// ============================================
// CONTROL CENTRE: GRADING AND SEASONS (control.grading)
//
//   GET  ?action=summary                       grades, points per placing, seasons
//   GET  ?action=preview&months=&trigger=&value=&offset=
//                                              next reset under these settings
//                                              (saved or not), 50 players a time
//   POST {action:'check', grades, points, season}   what would change, and its effect
//   POST {action:'save',  grades, points, season}   save (same validation)
//   POST {action:'addGrade', shortName, name, threshold, position}
//   POST {action:'retireGrade' | 'restoreGrade' | 'deleteGrade', id}
//   POST {action:'moveCheck', fromId, toId}     how many players, and the effect
//   POST {action:'movePlayers', fromId, toId, confirmCount}
//
//   grades: [{id, shortName, name, threshold}] for every active grade, in the
//           new rank order (best first)
//   points: {winners, runnersUp, semiFinalists, quarterFinalists}
//   season: {months: [1..12], trigger, value}
//   close:  points under a grade's threshold shown under Close to promotion
//           on the Grading page (close_to_promotion_points, 10 to 1,000)
//
// Order of checks: logged in (401), CSRF on writes (403), control.grading
// (403). Changes are blocked while impersonating (check and moveCheck only
// read). Every save writes the old and new values to AuditLog.
// Bulk moves send no emails and create no news posts.
// ============================================

const KBF_PREVIEW_PAGE = 50;

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
requirePermission('control.grading');

// ============================================
// HELPERS
// ============================================

// Every grade (active first, in rank order), with player counts
function loadAllGrades($pdo) {
    $hasFlag = kbfCategoriesHaveActiveFlag($pdo);
    $stmt = $pdo->query("
        SELECT c.CategoryId, c.Name, c.ShortName, c.DisplayOrder, c.PromotionThreshold,
               " . ($hasFlag ? "c.IsActive" : "1") . " AS IsActive,
               (SELECT COUNT(*) FROM Users u WHERE u.CategoryId = c.CategoryId AND u.IsActive = 1) AS ActivePlayers,
               (SELECT COUNT(*) FROM Users u WHERE u.CategoryId = c.CategoryId) AS AllPlayers
        FROM Categories c
        ORDER BY " . ($hasFlag ? "c.IsActive DESC, " : "") . "c.DisplayOrder ASC, c.CategoryId ASC
    ");
    $grades = [];
    foreach ($stmt->fetchAll() as $r) {
        $grades[(int) $r['CategoryId']] = [
            'id' => (int) $r['CategoryId'],
            'shortName' => (string) $r['ShortName'],
            'name' => (string) $r['Name'],
            'displayOrder' => (int) $r['DisplayOrder'],
            'threshold' => (int) $r['PromotionThreshold'] > 0 ? (int) $r['PromotionThreshold'] : KBF_DEFAULT_THRESHOLD,
            'active' => (int) $r['IsActive'] === 1,
            'players' => (int) $r['ActivePlayers'],
            'allPlayers' => (int) $r['AllPlayers'],
        ];
    }
    return $grades;
}

function activeGradeIds(array $grades) {
    return array_values(array_map(function ($g) { return $g['id']; },
        array_filter($grades, function ($g) { return $g['active']; })));
}

// How many rows of a table use a grade; a missing table counts as used (safe)
function gradeUseCount($pdo, $sql, array $params) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 1;
    }
}

// Has a grade ever been used by a player, history row, registration,
// broadcast, tracker row or match? Then it can only be retired.
function gradeEverUsed($pdo, $id) {
    $checks = [
        "SELECT COUNT(*) FROM Users WHERE CategoryId = ?",
        "SELECT COUNT(*) FROM PlayerRankingHistory WHERE OldCategoryId = ? OR NewCategoryId = ?",
        "SELECT COUNT(*) FROM PlayerRegistrations WHERE CategoryId = ?",
        "SELECT COUNT(*) FROM EmailBroadcasts WHERE TargetCategoryId = ?",
        "SELECT COUNT(*) FROM PlayerPerformanceTracker WHERE CategoryId = ?",
        "SELECT COUNT(*) FROM Matches WHERE CategoryId = ?",
    ];
    foreach ($checks as $sql) {
        $params = substr_count($sql, '?') === 2 ? [$id, $id] : [$id];
        if (gradeUseCount($pdo, $sql, $params) > 0) {
            return true;
        }
    }
    return false;
}

function pendingRegistrationsFor($pdo, $id) {
    return gradeUseCount($pdo, "SELECT COUNT(*) FROM PlayerRegistrations WHERE CategoryId = ? AND Status = 'Pending'", [$id]);
}

// Active grades get DisplayOrder 10, 20, 30...; retired ones follow
function renumberGrades($pdo, array $activeIdsInOrder) {
    $grades = loadAllGrades($pdo);
    $update = $pdo->prepare("UPDATE Categories SET DisplayOrder = ? WHERE CategoryId = ?");
    $order = 10;
    foreach ($activeIdsInOrder as $id) {
        $update->execute([$order, (int) $id]);
        $order += 10;
    }
    foreach ($grades as $g) {
        if (!in_array($g['id'], $activeIdsInOrder, true)) {
            $update->execute([$order, $g['id']]);
            $order += 10;
        }
    }
}

function cleanText($value, $max) {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    return $len > $max ? null : $text;
}

function wholeNumber($value) {
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value)) {
        return (int) trim($value);
    }
    return null;
}

function monthList(array $months) {
    $names = array_map(function ($m) { return KBF_MONTH_NAMES[$m - 1]; }, $months);
    if (count($names) <= 1) {
        return implode('', $names);
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

function fail($message, $field = null, $status = 400) {
    sendJSON(array_filter(['success' => false, 'message' => $message, 'field' => $field], function ($v) { return $v !== null; }), $status);
}

// Saved "Close to promotion" range (Grading page), 10 to 1,000
function closeRangeNow($pdo) {
    $v = (int) getSetting($pdo, 'close_to_promotion_points', 150);
    return ($v >= 10 && $v <= 1000) ? $v : 150;
}

// Validate the full payload of check/save. Returns
// ['grades' => [id => [...]] in order | null, 'points' => [...] | null, 'season' => [...] | null,
//  'close' => n | null]
function readPayload($pdo, array $data, array $current) {
    $out = ['grades' => null, 'points' => null, 'season' => null, 'close' => null];

    // ---------- grades ----------
    if (isset($data['grades'])) {
        if (!is_array($data['grades'])) {
            fail('Invalid grades');
        }
        $activeIds = activeGradeIds($current);
        $seen = [];
        $shortNames = [];
        $ordered = [];
        foreach (array_values($data['grades']) as $i => $g) {
            $id = filter_var($g['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !in_array($id, $activeIds, true) || isset($seen[$id])) {
                fail('The list of grades is out of date. Please reload the page.');
            }
            $seen[$id] = true;
            $short = cleanText($g['shortName'] ?? '', 3);
            if ($short === null || $short === '') {
                fail('Short names must be 1 to 3 characters.', 'short-' . $id);
            }
            $key = strtoupper($short);
            if (isset($shortNames[$key])) {
                fail('Short name "' . $short . '" is used twice.', 'short-' . $id);
            }
            $shortNames[$key] = true;
            $name = cleanText($g['name'] ?? '', 60);
            if ($name === null || $name === '') {
                fail('Full names must be 1 to 60 characters.', 'name-' . $id);
            }
            $threshold = $current[$id]['threshold'];
            if ($i > 0) { // ignored for the top grade
                $threshold = wholeNumber($g['threshold'] ?? null);
                if ($threshold === null || $threshold < 10 || $threshold > 10000) {
                    fail('Promotion thresholds must be from 10 to 10,000.', 'threshold-' . $id);
                }
            }
            $ordered[$id] = ['id' => $id, 'shortName' => $short, 'name' => $name, 'threshold' => $threshold, 'rank' => $i];
        }
        if (count($seen) !== count($activeIds)) {
            fail('The list of grades is out of date. Please reload the page.');
        }
        // Short names must not clash with retired grades either
        foreach ($current as $g) {
            if (!$g['active'] && isset($shortNames[strtoupper($g['shortName'])])) {
                fail('Short name "' . $g['shortName'] . '" belongs to a retired grade.');
            }
        }
        $out['grades'] = $ordered;
    }

    // ---------- points per placing ----------
    if (isset($data['points'])) {
        if (!is_array($data['points'])) {
            fail('Invalid points');
        }
        $points = [];
        foreach (array_keys(KBF_PLACING_DEFAULTS) as $placing) {
            $v = wholeNumber($data['points'][$placing] ?? null);
            if ($v === null || $v < 0 || $v > 1000) {
                fail('Points per placing must be from 0 to 1,000.', 'points-' . $placing);
            }
            $points[$placing] = $v;
        }
        $out['points'] = $points;
    }

    // ---------- seasons ----------
    if (isset($data['season'])) {
        $s = $data['season'];
        if (!is_array($s) || !is_array($s['months'] ?? null)) {
            fail('Invalid season settings');
        }
        $months = [];
        foreach ($s['months'] as $m) {
            $m = filter_var($m, FILTER_VALIDATE_INT);
            if ($m === false || $m < 1 || $m > 12) {
                fail('Start months must be from January to December.', 'months');
            }
            $months[$m] = true;
        }
        $months = array_keys($months);
        sort($months);
        if (!$months) {
            fail('Choose at least one month for a new season to start.', 'months');
        }
        $trigger = wholeNumber($s['trigger'] ?? null);
        $value = wholeNumber($s['value'] ?? null);
        if ($trigger === null || $trigger < 1 || $trigger > 100000) {
            fail('The points needed to keep some points must be from 1 to 100,000.', 'trigger');
        }
        if ($value === null || $value < 0) {
            fail('The restart value cannot be negative.', 'value');
        }
        if ($value >= $trigger) {
            fail('The restart value must be below ' . $trigger . '.', 'value');
        }
        $out['season'] = ['months' => $months, 'trigger' => $trigger, 'value' => $value];
    }

    // ---------- close to promotion ----------
    if (isset($data['close'])) {
        $close = wholeNumber($data['close']);
        if ($close === null || $close < 10 || $close > 1000) {
            fail('Close to promotion must be from 10 to 1,000 points.', 'closeInput');
        }
        $out['close'] = $close;
    }

    return $out;
}

// Describe what a payload changes and its effect
function describeChanges($pdo, array $payload, array $current) {
    $changes = [];
    $effects = [];

    if ($payload['grades'] !== null) {
        $activeNow = array_filter($current, function ($g) { return $g['active']; });
        $oldOrder = array_values(array_map(function ($g) { return $g['id']; }, $activeNow));
        $newOrder = array_keys($payload['grades']);

        foreach ($payload['grades'] as $id => $g) {
            $old = $current[$id];
            if ($old['shortName'] !== $g['shortName']) {
                $changes[] = 'Short name ' . $old['shortName'] . ' becomes ' . $g['shortName'] . '.';
            }
            if ($old['name'] !== $g['name']) {
                $changes[] = 'Grade ' . $g['shortName'] . ' full name: "' . $old['name'] . '" becomes "' . $g['name'] . '".';
            }
            if ($g['rank'] > 0 && $old['threshold'] !== $g['threshold']) {
                $changes[] = 'Grade ' . $g['shortName'] . ' promotion at ' . $old['threshold'] . ' becomes ' . $g['threshold'] . ' points.';
            }
        }
        if ($oldOrder !== $newOrder) {
            $changes[] = 'Order becomes: ' . implode(', ', array_map(function ($g) { return $g['shortName']; }, $payload['grades'])) . '.';
            if ($oldOrder[0] !== $newOrder[0]) {
                $effects[] = 'The top grade changes from ' . $current[$oldOrder[0]]['shortName'] . ' to '
                    . $payload['grades'][$newOrder[0]]['shortName'] . '. Players in the top grade cannot be promoted.';
            }
        }

        // Eligibility now and after
        $before = [];
        $i = 0;
        foreach ($activeNow as $id => $g) {
            $before[$id] = ['order' => $i++, 'threshold' => $g['threshold']];
        }
        $after = [];
        foreach ($payload['grades'] as $id => $g) {
            $after[$id] = ['order' => $g['rank'], 'threshold' => $g['threshold']];
        }
        $countBefore = kbfEligibilityCounts($pdo, $before);
        $countAfter = kbfEligibilityCounts($pdo, $after);
        if ($countBefore['total'] !== $countAfter['total'] || $countBefore['byGrade'] != $countAfter['byGrade']) {
            $effects[] = 'Players eligible for promotion on season points: ' . $countBefore['total'] . ' now, '
                . $countAfter['total'] . ' after this change. Their eligibility is updated at the next nightly recalculation.';
            foreach ($payload['grades'] as $id => $g) {
                $b = $countBefore['byGrade'][$id] ?? 0;
                $a = $countAfter['byGrade'][$id] ?? 0;
                if ($a !== $b) {
                    $effects[] = 'Grade ' . $g['shortName'] . ': ' . $b . ' eligible now, ' . $a . ' after.';
                }
            }
        }
    }

    if ($payload['points'] !== null) {
        $old = kbfPlacingPoints($pdo);
        $labels = ['winners' => 'Winners', 'runnersUp' => 'Runners-up', 'semiFinalists' => 'Semi-finalists', 'quarterFinalists' => 'Quarter-finalists'];
        $any = false;
        foreach ($labels as $k => $label) {
            if ((int) $old[$k] !== $payload['points'][$k]) {
                $changes[] = $label . ': ' . $old[$k] . ' becomes ' . $payload['points'][$k] . ' points.';
                $any = true;
            }
        }
        if ($any) {
            $effects[] = 'New points apply to results approved from now on. Points already awarded never change.';
        }
    }

    if ($payload['season'] !== null) {
        $old = kbfSeasonSettings($pdo);
        $new = $payload['season'];
        if ($old['months'] !== $new['months']) {
            $season = kbfSeasonAt($new['months']);
            $changes[] = 'New seasons start in ' . monthList($new['months']) . ' (was ' . monthList($old['months']) . ').';
            $effects[] = 'The current season becomes "' . $season['name'] . '". The next reset is on '
                . kbfDateLabel($season['nextResetDate']) . '. Saving never resets anyone.';
        }
        if ($old['trigger'] !== $new['trigger'] || $old['value'] !== $new['value']) {
            $changes[] = 'At each new season, players on ' . $new['trigger'] . ' points or more restart on ' . $new['value']
                . ' (was ' . $old['trigger'] . ' and ' . $old['value'] . ').';
        }
    }

    if ($payload['close'] !== null) {
        $old = closeRangeNow($pdo);
        if ($old !== $payload['close']) {
            $changes[] = 'Close to promotion shows players within ' . $payload['close'] . ' points (was ' . $old . ').';
        }
    }

    return [$changes, $effects];
}

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'summary';

    try {
        if ($action === 'summary') {
            $grades = loadAllGrades($pdo);
            $active = [];
            $retired = [];
            foreach ($grades as $g) {
                $g['canDelete'] = !gradeEverUsed($pdo, $g['id']);
                if ($g['active']) {
                    $active[] = $g;
                } else {
                    $retired[] = $g;
                }
            }
            $season = kbfSeasonSettings($pdo);
            $plan = kbfSeasonPlan($pdo, $season, null, true);
            sendJSON([
                'success' => true,
                'grades' => $active,
                'retired' => $retired,
                'retireReady' => kbfCategoriesHaveActiveFlag($pdo),
                'points' => kbfPlacingPoints($pdo),
                'season' => [
                    'months' => $season['months'],
                    'trigger' => $season['trigger'],
                    'value' => $season['value'],
                ],
                'preview' => [
                    'status' => $plan['status'],
                    'seasonName' => $plan['season']['name'],
                    'nextResetLabel' => $plan['nextResetLabel'],
                    'counts' => $plan['counts'],
                    'total' => count($plan['players']),
                ],
                'close' => closeRangeNow($pdo),
                'canEdit' => !isImpersonating(),
            ]);
        }

        if ($action === 'preview') {
            $saved = kbfSeasonSettings($pdo);
            $months = kbfParseStartMonths($_GET['months'] ?? implode(',', $saved['months']));
            $trigger = wholeNumber($_GET['trigger'] ?? '') ?? $saved['trigger'];
            $value = wholeNumber($_GET['value'] ?? '') ?? $saved['value'];
            $settings = ['months' => $months, 'trigger' => $trigger, 'value' => $value, 'lastTermReset' => $saved['lastTermReset']];
            if ($months !== $saved['months']) {
                // Saving new months records the current season, so nobody is reset
                $settings['lastTermReset'] = kbfSeasonAt($months)['key'];
            }
            $plan = kbfSeasonPlan($pdo, $settings, null, true);

            $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($offset === false) {
                $offset = 0;
            }
            $page = array_slice($plan['players'], $offset, KBF_PREVIEW_PAGE);
            sendJSON([
                'success' => true,
                'status' => $plan['status'],
                'seasonName' => $plan['season']['name'],
                'nextResetLabel' => $plan['nextResetLabel'],
                'counts' => $plan['counts'],
                'total' => count($plan['players']),
                'players' => $page,
                'hasMore' => $offset + count($page) < count($plan['players']),
                'nextOffset' => $offset + count($page),
            ]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);
    } catch (PDOException $e) {
        error_log('control-grading.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST
// ============================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request body'], 400);
    }
    $action = is_string($data['action'] ?? null) ? $data['action'] : '';

    if (!in_array($action, ['check', 'moveCheck'], true)) {
        requireNotImpersonating();
    }

    try {
        // ---------- CHECK / SAVE ----------
        if ($action === 'check' || $action === 'save') {
            $current = loadAllGrades($pdo);
            $payload = readPayload($pdo, $data, $current);
            list($changes, $effects) = describeChanges($pdo, $payload, $current);

            if ($action === 'check') {
                sendJSON(['success' => true, 'changes' => $changes, 'effects' => $effects]);
            }
            if (!$changes) {
                sendJSON(['success' => true, 'message' => 'Nothing to change', 'changes' => []]);
            }

            $audit = [];
            $pdo->beginTransaction();

            if ($payload['grades'] !== null) {
                $update = $pdo->prepare("UPDATE Categories SET ShortName = ?, Name = ?, PromotionThreshold = ? WHERE CategoryId = ?");
                foreach ($payload['grades'] as $id => $g) {
                    $old = $current[$id];
                    if ($old['shortName'] !== $g['shortName'] || $old['name'] !== $g['name'] || $old['threshold'] !== $g['threshold']) {
                        $update->execute([$g['shortName'], $g['name'], $g['threshold'], $id]);
                        $audit[] = ['grade_changed_settings', 'grade', $id,
                            'Grade ' . $id . ': short ' . $old['shortName'] . ' -> ' . $g['shortName']
                            . '; name ' . $old['name'] . ' -> ' . $g['name']
                            . '; threshold ' . $old['threshold'] . ' -> ' . $g['threshold']];
                    }
                }
                $oldOrder = activeGradeIds($current);
                $newOrder = array_keys($payload['grades']);
                if ($oldOrder !== $newOrder) {
                    renumberGrades($pdo, $newOrder);
                    $audit[] = ['grades_reordered', 'grade', null,
                        'Order: ' . implode(', ', array_map(function ($id) use ($current) { return $current[$id]['shortName']; }, $oldOrder))
                        . ' -> ' . implode(', ', array_map(function ($g) { return $g['shortName']; }, $payload['grades']))];
                }
            }

            if ($payload['points'] !== null) {
                $old = kbfPlacingPoints($pdo);
                if ($old != $payload['points']) {
                    kbfSavePlacingPoints($pdo, $payload['points']);
                    $parts = [];
                    foreach ($payload['points'] as $k => $v) {
                        $parts[] = $k . ' ' . $old[$k] . ' -> ' . $v;
                    }
                    $audit[] = ['placing_points_changed', 'setting', null, implode('; ', $parts)];
                }
            }

            if ($payload['season'] !== null) {
                $old = kbfSeasonSettings($pdo);
                $new = $payload['season'];
                $set = $pdo->prepare("
                    INSERT INTO Settings (SettingKey, SettingValue) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
                ");
                if ($old['months'] !== $new['months']) {
                    $set->execute(['term_start_months', implode(',', $new['months'])]);
                    // The season current under the new months counts as done,
                    // so the change starts at the next start month
                    $key = kbfSeasonAt($new['months'])['key'];
                    $set->execute(['last_term_reset', $key]);
                    $audit[] = ['setting_changed', 'setting', null, 'term_start_months: ' . implode(',', $old['months']) . ' -> ' . implode(',', $new['months'])];
                    $audit[] = ['setting_changed', 'setting', null, 'last_term_reset: ' . ($old['lastTermReset'] === '' ? '(not set)' : $old['lastTermReset']) . ' -> ' . $key];
                }
                if ($old['trigger'] !== $new['trigger']) {
                    $set->execute(['term_reset_floor_trigger', (string) $new['trigger']]);
                    $audit[] = ['setting_changed', 'setting', null, 'term_reset_floor_trigger: ' . $old['trigger'] . ' -> ' . $new['trigger']];
                }
                if ($old['value'] !== $new['value']) {
                    $set->execute(['term_reset_floor_value', (string) $new['value']]);
                    $audit[] = ['setting_changed', 'setting', null, 'term_reset_floor_value: ' . $old['value'] . ' -> ' . $new['value']];
                }
            }

            if ($payload['close'] !== null) {
                $old = closeRangeNow($pdo);
                if ($old !== $payload['close']) {
                    $set = $pdo->prepare("
                        INSERT INTO Settings (SettingKey, SettingValue) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
                    ");
                    $set->execute(['close_to_promotion_points', (string) $payload['close']]);
                    $audit[] = ['setting_changed', 'setting', null, 'close_to_promotion_points: ' . $old . ' -> ' . $payload['close']];
                }
            }

            $pdo->commit();
            foreach ($audit as $a) {
                auditLog($a[0], $a[1], $a[2], $a[3]);
            }
            sendJSON(['success' => true, 'message' => 'Changes saved', 'changes' => $changes]);
        }

        // ---------- ADD A GRADE ----------
        if ($action === 'addGrade') {
            $current = loadAllGrades($pdo);
            $short = cleanText($data['shortName'] ?? '', 3);
            $name = cleanText($data['name'] ?? '', 60);
            $threshold = wholeNumber($data['threshold'] ?? 300);
            if ($short === null || $short === '') {
                fail('The short name must be 1 to 3 characters.', 'shortName');
            }
            foreach ($current as $g) {
                if (strtoupper($g['shortName']) === strtoupper($short)) {
                    fail('Short name "' . $short . '" is already used' . ($g['active'] ? '' : ' by a retired grade') . '.', 'shortName');
                }
            }
            if ($name === null || $name === '') {
                fail('The full name must be 1 to 60 characters.', 'name');
            }
            if ($threshold === null || $threshold < 10 || $threshold > 10000) {
                fail('The promotion threshold must be from 10 to 10,000.', 'threshold');
            }
            $order = activeGradeIds($current);
            $position = filter_var($data['position'] ?? count($order), FILTER_VALIDATE_INT);
            if ($position === false || $position < 0 || $position > count($order)) {
                $position = count($order);
            }

            $pdo->beginTransaction();
            $nextId = (int) $pdo->query("SELECT COALESCE(MAX(CategoryId), 0) + 1 FROM Categories FOR UPDATE")->fetchColumn();
            if (kbfCategoriesHaveActiveFlag($pdo)) {
                $stmt = $pdo->prepare("INSERT INTO Categories (CategoryId, Name, ShortName, DisplayOrder, PromotionThreshold, IsActive) VALUES (?, ?, ?, 0, ?, 1)");
            } else {
                $stmt = $pdo->prepare("INSERT INTO Categories (CategoryId, Name, ShortName, DisplayOrder, PromotionThreshold) VALUES (?, ?, ?, 0, ?)");
            }
            $stmt->execute([$nextId, $name, $short, $threshold]);
            array_splice($order, $position, 0, [$nextId]);
            renumberGrades($pdo, $order);
            $pdo->commit();

            auditLog('grade_added', 'grade', $nextId, 'Added grade ' . $short . ' (' . $name . '), threshold ' . $threshold . ', rank ' . ($position + 1));
            sendJSON(['success' => true, 'message' => 'Grade ' . $short . ' added']);
        }

        // ---------- RETIRE, RESTORE, DELETE ----------
        if (in_array($action, ['retireGrade', 'restoreGrade', 'deleteGrade'], true)) {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            $current = loadAllGrades($pdo);
            if (!$id || !isset($current[$id])) {
                fail('Grade not found', null, 404);
            }
            $g = $current[$id];
            $order = activeGradeIds($current);

            if ($action === 'retireGrade' || $action === 'restoreGrade') {
                if (!kbfCategoriesHaveActiveFlag($pdo)) {
                    fail('Retiring grades needs database/grading-settings.sql to be run first.', null, 409);
                }
            }

            if ($action === 'retireGrade') {
                if (!$g['active']) {
                    fail('This grade is already retired.');
                }
                if ($g['allPlayers'] > 0) {
                    fail('A grade can only be removed once nobody is in it. ' . $g['allPlayers'] . ' player'
                        . ($g['allPlayers'] === 1 ? ' is' : 's are') . ' in ' . $g['shortName'] . ' (including deactivated players). Move them to another grade first.');
                }
                $pending = pendingRegistrationsFor($pdo, $id);
                if ($pending > 0) {
                    fail($pending . ' registration' . ($pending === 1 ? '' : 's') . ' waiting for approval chose ' . $g['shortName'] . '. Approve or reject them first.');
                }
                if (count($order) <= 1) {
                    fail('The last active grade cannot be retired.');
                }
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE Categories SET IsActive = 0 WHERE CategoryId = ?")->execute([$id]);
                renumberGrades($pdo, array_values(array_diff($order, [$id])));
                $pdo->commit();
                auditLog('grade_retired', 'grade', $id, 'Retired grade ' . $g['shortName'] . ' (' . $g['name'] . ')');
                sendJSON(['success' => true, 'message' => 'Grade ' . $g['shortName'] . ' retired']);
            }

            if ($action === 'restoreGrade') {
                if ($g['active']) {
                    fail('This grade is already active.');
                }
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE Categories SET IsActive = 1 WHERE CategoryId = ?")->execute([$id]);
                $order[] = $id; // restored at the bottom of the order
                renumberGrades($pdo, $order);
                $pdo->commit();
                auditLog('grade_restored', 'grade', $id, 'Restored grade ' . $g['shortName'] . ' at the bottom of the order');
                sendJSON(['success' => true, 'message' => 'Grade ' . $g['shortName'] . ' restored at the bottom of the order']);
            }

            // deleteGrade: only when never used anywhere
            if (gradeEverUsed($pdo, $id)) {
                fail('This grade has been used, so it can only be retired.');
            }
            if ($g['active'] && count($order) <= 1) {
                fail('The last active grade cannot be deleted.');
            }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM Categories WHERE CategoryId = ?")->execute([$id]);
            renumberGrades($pdo, array_values(array_diff($order, [$id])));
            $pdo->commit();
            auditLog('grade_deleted', 'grade', $id, 'Deleted unused grade ' . $g['shortName'] . ' (' . $g['name'] . ')');
            sendJSON(['success' => true, 'message' => 'Grade ' . $g['shortName'] . ' deleted']);
        }

        // ---------- MOVE PLAYERS ----------
        if ($action === 'moveCheck' || $action === 'movePlayers') {
            $current = loadAllGrades($pdo);
            $fromId = filter_var($data['fromId'] ?? null, FILTER_VALIDATE_INT);
            $toId = filter_var($data['toId'] ?? null, FILTER_VALIDATE_INT);
            if (!$fromId || !isset($current[$fromId])) {
                fail('Choose the grade to move players from.');
            }
            if (!$toId || !isset($current[$toId]) || !$current[$toId]['active'] || $toId === $fromId) {
                fail('Choose another active grade to move the players to.', 'toId');
            }
            $from = $current[$fromId];
            $to = $current[$toId];
            $count = $from['allPlayers'];
            if ($count === 0) {
                fail('Nobody is in grade ' . $from['shortName'] . '.');
            }

            if ($action === 'moveCheck') {
                $order = activeGradeIds($current);
                $direction = array_search($toId, $order, true) < array_search($fromId, $order, true) ? 'up' : 'down';
                sendJSON([
                    'success' => true,
                    'count' => $count,
                    'effects' => [
                        $count . ' player' . ($count === 1 ? '' : 's') . ' move from ' . $from['shortName'] . ' to ' . $to['shortName']
                            . ($from['active'] ? ' (' . $direction . ' the order)' : '') . '.',
                        'Their season points restart on 0, as for any grade change. Career points do not change.',
                        'Each player gets a grade history entry. No emails are sent and no news posts are created.',
                    ],
                ]);
            }

            $confirm = filter_var($data['confirmCount'] ?? null, FILTER_VALIDATE_INT);
            if ($confirm !== $count) {
                fail('Type the number of players (' . $count . ') to confirm the move.', 'confirmCount');
            }

            $reason = 'Moved by an admin from ' . $from['name'] . ' to ' . $to['name'];
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT UserId FROM Users WHERE CategoryId = ? FOR UPDATE");
            $stmt->execute([$fromId]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($userIds) !== $count) {
                $pdo->rollBack();
                fail('The number of players has just changed. Please check and try again.');
            }
            $moveUser = $pdo->prepare("UPDATE Users SET CategoryId = ?, TotalPoints = 0 WHERE UserId = ?");
            $history = $pdo->prepare("
                INSERT INTO PlayerRankingHistory
                (PlayerId, OldCategoryId, NewCategoryId, Reason, IsAutomatic, ChangedByAdminId, Notes)
                VALUES (?, ?, ?, ?, 0, ?, NULL)
            ");
            $tracker = $pdo->prepare("
                UPDATE PlayerPerformanceTracker
                SET CategoryId = ?, EligibleForPromotion = 0, FlaggedForDemotion = 0
                WHERE PlayerId = ?
            ");
            foreach ($userIds as $uid) {
                $moveUser->execute([$toId, (int) $uid]);
                $history->execute([(int) $uid, $fromId, $toId, $reason, (int) $_SESSION['user_id']]);
                $tracker->execute([$toId, (int) $uid]);
            }
            $pdo->commit();

            auditLog('grade_players_moved', 'grade', $fromId, $count . ' players moved from ' . $from['shortName'] . ' to ' . $to['shortName']);
            sendJSON(['success' => true, 'message' => $count . ' player' . ($count === 1 ? '' : 's') . ' moved to ' . $to['shortName']]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('control-grading.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
?>
