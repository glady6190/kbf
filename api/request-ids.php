<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }

// ============================================
// ONE-TIME REQUEST IDS (no duplicate saves)
//
// Pages send a one-time id (header X-Request-Id, or a requestId field) with
// a request that creates something. Before creating, the endpoint calls
//     kbfRequestIdBegin($pdo, 'news_post');
// which reserves the id for the signed-in user in the RequestIds table
// (database/request-ids.sql). When the script ends:
//   - success (sendJSON with a status below 300 and success not false): the
//     answer is stored with the id, so a repeat of the same id gets that
//     answer back (with alreadySaved: true) and nothing is created again;
//   - anything else: the reservation is removed, so the page can try again
//     with the same id.
// A repeat that arrives while the first request is still running is told
// that it is already being saved (409), never saved twice.
// kbfRequestIdRecord($id) records the id of the created record (optional;
// otherwise it is read from the answer).
//
// No id sent (older page) or no RequestIds table yet: the check is skipped.
// Rows older than 7 days are removed now and then.
// ============================================

const KBF_REQUEST_ID_KEEP_DAYS = 7;
// A reservation with no stored answer after this long is treated as
// abandoned (the first request died) and may be taken over.
const KBF_REQUEST_ID_STALE_MINUTES = 10;

$GLOBALS['kbfRequestIdPending'] = null;
$GLOBALS['kbfRequestIdRecordId'] = null;

// The id sent by the page, or null when there is none or it is malformed
function kbfRequestIdFromClient($fallback = null) {
    $id = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    if (!is_string($id) || $id === '') {
        $id = is_string($fallback) ? $fallback : (is_string($_POST['requestId'] ?? null) ? $_POST['requestId'] : null);
    }
    if (!is_string($id)) {
        return null;
    }
    $id = trim($id);
    return preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) ? $id : null;
}

// True for "table does not exist"
function kbfRequestIdMissingTable(PDOException $e) {
    $info = $e->errorInfo;
    return (is_array($info) && (($info[1] ?? null) === 1146 || ($info[0] ?? '') === '42S02'))
        || strpos($e->getMessage(), '1146') !== false;
}

// True for a duplicate key
function kbfRequestIdDuplicate(PDOException $e) {
    $info = $e->errorInfo;
    return (is_array($info) && ($info[1] ?? null) === 1062) || strpos($e->getMessage(), '1062') !== false;
}

// Reserve the request id for this user and scope. Ends the request with the
// first answer when the id was already used. $bodyId: the id from a JSON
// body (requestId), used when no header was sent.
function kbfRequestIdBegin(PDO $pdo, $scope, $bodyId = null) {
    if ($GLOBALS['kbfRequestIdPending'] !== null) {
        return;
    }
    $requestId = kbfRequestIdFromClient($bodyId);
    if ($requestId === null) {
        return;
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $scope = substr((string) $scope, 0, 40);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $stmt = $pdo->prepare("INSERT INTO RequestIds (UserId, RequestId, Scope, CreatedDate) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$userId, $requestId, $scope]);
            $GLOBALS['kbfRequestIdPending'] = ['pdo' => $pdo, 'userId' => $userId, 'requestId' => $requestId];
            register_shutdown_function('kbfRequestIdFinish');
            kbfRequestIdTrim($pdo);
            return;
        } catch (PDOException $e) {
            if (kbfRequestIdMissingTable($e)) {
                kbfLogOnce('RequestIds table is missing: repeated saves are not checked. Run database/request-ids.sql.');
                return;
            }
            if (!kbfRequestIdDuplicate($e)) {
                // Never block a save because of this check
                error_log('Request id check error: ' . $e->getMessage());
                return;
            }
        }

        // Already used: send back the first answer
        try {
            $stmt = $pdo->prepare("
                SELECT ResponseJson, CreatedDate < (NOW() - INTERVAL " . (int) KBF_REQUEST_ID_STALE_MINUTES . " MINUTE) AS IsStale
                FROM RequestIds WHERE UserId = ? AND RequestId = ?
            ");
            $stmt->execute([$userId, $requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Request id lookup error: ' . $e->getMessage());
            return;
        }
        if (!$row) {
            continue; // removed in the meantime: try to reserve again
        }
        if ($row['ResponseJson'] !== null) {
            $answer = json_decode((string) $row['ResponseJson'], true);
            if (!is_array($answer)) {
                $answer = ['success' => true];
            }
            $answer['alreadySaved'] = true;
            sendJSON($answer);
        }
        if ((int) $row['IsStale'] === 1 && $attempt === 0) {
            // The first request never finished: take the id over
            try {
                $stmt = $pdo->prepare("DELETE FROM RequestIds WHERE UserId = ? AND RequestId = ? AND ResponseJson IS NULL");
                $stmt->execute([$userId, $requestId]);
            } catch (PDOException $e) {
                error_log('Request id cleanup error: ' . $e->getMessage());
                return;
            }
            continue;
        }
        sendJSON([
            'success' => false,
            'alreadySaving' => true,
            'message' => 'This is already being saved. Please wait a moment, then refresh the page to check it.'
        ], 409);
    }
}

// The id of the record created by this request (stored with the request id)
function kbfRequestIdRecord($recordId) {
    $GLOBALS['kbfRequestIdRecordId'] = $recordId === null ? null : substr((string) $recordId, 0, 64);
}

// Shutdown: store the answer to a successful request, otherwise release the id
function kbfRequestIdFinish() {
    $pending = $GLOBALS['kbfRequestIdPending'];
    if ($pending === null) {
        return;
    }
    $GLOBALS['kbfRequestIdPending'] = null;
    $pdo = $pending['pdo'];
    $last = $GLOBALS['kbfLastJsonResponse'] ?? null;
    $data = is_array($last) ? $last['data'] : null;
    $ok = is_array($last) && $last['status'] < 300 && is_array($data) && ($data['success'] ?? true) !== false;

    try {
        if ($pdo->inTransaction()) {
            // Never left open by a finished save; undo what was not committed
            $pdo->rollBack();
            $ok = false;
        }
        if ($ok) {
            $recordId = $GLOBALS['kbfRequestIdRecordId'];
            if ($recordId === null) {
                foreach (['announcementId', 'tournamentId', 'id', 'userId'] as $key) {
                    if (isset($data[$key]) && is_scalar($data[$key])) {
                        $recordId = substr((string) $data[$key], 0, 64);
                        break;
                    }
                }
            }
            $json = json_encode($data);
            $stmt = $pdo->prepare("UPDATE RequestIds SET ResponseJson = ?, RecordId = ? WHERE UserId = ? AND RequestId = ?");
            $stmt->execute([$json === false ? '{"success":true}' : $json, $recordId, $pending['userId'], $pending['requestId']]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM RequestIds WHERE UserId = ? AND RequestId = ? AND ResponseJson IS NULL");
            $stmt->execute([$pending['userId'], $pending['requestId']]);
        }
    } catch (Throwable $e) {
        error_log('Request id finish error: ' . $e->getMessage());
    }
}

// Now and then (about one call in 50) remove ids older than 7 days
function kbfRequestIdTrim(PDO $pdo) {
    if (mt_rand(1, 50) !== 1) {
        return;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM RequestIds WHERE CreatedDate < (NOW() - INTERVAL " . (int) KBF_REQUEST_ID_KEEP_DAYS . " DAY) LIMIT 1000");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log('Request id trim error: ' . $e->getMessage());
    }
}
