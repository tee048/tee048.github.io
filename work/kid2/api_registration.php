<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

function sendError($msg) { http_response_code(400); echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function sendSuccess($data) { echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE); exit; }

$method = $_SERVER['REQUEST_METHOD'];

try {
    // ===== [GET] 查詢報名資料 =====
    if ($method === 'GET') {
        $session_date = $_GET['session_date'] ?? '';
        $course       = $_GET['course'] ?? '';
        $status       = $_GET['status'] ?? '';
        $keyword      = $_GET['keyword'] ?? '';

        $sql = "SELECT * FROM registrations WHERE 1=1";
        $params = [];
        $types = '';

        if ($session_date) { $sql .= " AND session_date = ?"; $params[] = $session_date; $types .= 's'; }
        if ($course)       { $sql .= " AND course_name = ?"; $params[] = $course;       $types .= 's'; }
        if ($status)       { $sql .= " AND status = ?";      $params[] = $status;       $types .= 's'; }
        if ($keyword) {
            $kw = "%$keyword%";
            $sql .= " AND (parent_name LIKE ? OR phone LIKE ?)";
            $params[] = $kw; $params[] = $kw;
            $types .= 'ss';
        }
        $sql .= " ORDER BY session_date ASC, reg_time ASC";

        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // 取得所有課程選項
        $courses_result = $conn->query("SELECT DISTINCT course_name FROM registrations ORDER BY course_name");
        $courses = [];
        while ($r = $courses_result->fetch_assoc()) $courses[] = $r['course_name'];

        // 取得所有場次日期
        $dates_result = $conn->query("SELECT DISTINCT session_date FROM registrations ORDER BY session_date");
        $dates = [];
        while ($r = $dates_result->fetch_assoc()) $dates[] = $r['session_date'];

        sendSuccess(['records' => $rows, 'courses' => $courses, 'dates' => $dates]);
    }

    // ===== [POST] 批次匯入報名資料 =====
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $rows  = $input['rows'] ?? [];

        if (empty($rows)) sendError('沒有資料可匯入');

        $inserted = 0;
        $skipped  = 0;

        $stmt = $conn->prepare("
            INSERT INTO registrations
                (course_name, session_date, reg_time, parent_name, phone, email, child_name, child_gender, child_birthday, child_age, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status    = VALUES(status),
                child_gender = VALUES(child_gender),
                child_birthday = VALUES(child_birthday),
                child_age = VALUES(child_age)
        ");

        foreach ($rows as $r) {
            $course       = $r['course_name']    ?? '';
            $session_date = $r['session_date']   ?? null;
            $reg_time     = $r['reg_time']       ?? null;
            $parent_name  = $r['parent_name']    ?? '';
            $phone        = $r['phone']          ?? '';
            $email        = $r['email']          ?? '';
            $child_name   = $r['child_name']     ?? '';
            $child_gender = $r['child_gender']   ?? '';
            $child_birthday = $r['child_birthday'] ?: null;
            $child_age    = $r['child_age']      ?? '';
            $status       = $r['status']         ?? '';

            if (!$phone || !$parent_name || !$session_date) { $skipped++; continue; }

            $stmt->bind_param("sssssssssss",
                $course, $session_date, $reg_time, $parent_name, $phone,
                $email, $child_name, $child_gender, $child_birthday, $child_age, $status
            );
            $stmt->execute();
            if ($conn->affected_rows > 0) $inserted++;
            else $skipped++;
        }

        sendSuccess(['inserted' => $inserted, 'skipped' => $skipped, 'total' => count($rows)]);
    }

    // ===== [PUT] 更新連動狀態 =====
    if ($method === 'PUT') {
        $input  = json_decode(file_get_contents('php://input'), true);
        $id     = (int)($input['id']     ?? 0);
        $linked = (int)($input['linked'] ?? 0);

        if (!$id) sendError('缺少 id');

        $stmt = $conn->prepare("UPDATE registrations SET linked = ? WHERE id = ?");
        $stmt->bind_param("ii", $linked, $id);
        $stmt->execute();

        sendSuccess(['id' => $id, 'linked' => $linked]);
    }

    // ===== [DELETE] 刪除單筆報名資料 =====
    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if (!$id) sendError('缺少 id');
        $stmt = $conn->prepare("DELETE FROM registrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) sendError('刪除失敗: ' . $stmt->error);
        sendSuccess(['id' => $id, 'message' => '已刪除']);
    }

    sendError('不支援的請求方法');

} catch (Exception $e) {
    sendError('系統錯誤：' . $e->getMessage());
} finally {
    if (isset($conn)) $conn->close();
}