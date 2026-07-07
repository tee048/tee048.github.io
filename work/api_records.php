<?php
header('Content-Type: application/json; charset=utf-8');

// ===== CORS 處理 =====
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 處理 CORS
$allowed_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$allowed_origins = ['http://localhost', 'http://localhost:8000', 'http://127.0.0.1'];

if (in_array($allowed_origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

// ===== 錯誤回應函數 =====
function sendError($message, $code = 'ERROR')
{
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'code' => $code
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== 成功回應函數 =====
function sendSuccess($data)
{
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // ===== [POST] 更新備註 =====
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['checkin_id']) || empty($input['remark'])) {
            sendError('缺少必要參數', 'MISSING_PARAMS');
        }

        $stmt = $conn->prepare("UPDATE checkin_records SET remark = ? WHERE id = ?");

        if (!$stmt) {
            sendError('資料庫查詢準備失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
        }

        $stmt->bind_param("si", $input['remark'], $input['checkin_id']);

        if (!$stmt->execute()) {
            sendError('更新失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
        }

        sendSuccess([
            'checkin_id' => $input['checkin_id'],
            'message' => '備註已更新'
        ]);
    }

    // ===== [PUT] 編輯報到紀錄 =====
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['checkin_id'])) {
            sendError('缺少 checkin_id', 'MISSING_PARAMS');
        }

        $checkin_id = (int) $input['checkin_id'];
        $action     = $input['action'] ?? 'update';

        // ── 離館 ──
        if ($action === 'checkout') {
            $checkout_time = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE checkin_records SET checkout_time=? WHERE id=?");
            if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
            $stmt->bind_param("si", $checkout_time, $checkin_id);
            if (!$stmt->execute()) sendError('離館更新失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
            sendSuccess(['checkin_id' => $checkin_id, 'checkout_time' => $checkout_time, 'message' => '已記錄離館時間']);
        }

        // ── 到課狀態 ──
        if ($action === 'attendance') {
            $attendance = isset($input['attendance']) ? (int)$input['attendance'] : 0;
            $stmt = $conn->prepare("UPDATE checkin_records SET attendance=? WHERE id=?");
            if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
            $stmt->bind_param("ii", $attendance, $checkin_id);
            if (!$stmt->execute()) sendError('到課更新失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
            sendSuccess(['checkin_id' => $checkin_id, 'attendance' => $attendance, 'message' => '到課狀態已更新']);
        }

        // ── 取消離館 ──
        if ($action === 'undo_checkout') {
            $stmt = $conn->prepare("UPDATE checkin_records SET checkout_time=NULL WHERE id=?");
            if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
            $stmt->bind_param("i", $checkin_id);
            if (!$stmt->execute()) sendError('取消離館失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
            sendSuccess(['checkin_id' => $checkin_id, 'checkout_time' => null, 'message' => '已改回在館中']);
        }

        // ── 樓層更新 ──
        if ($action === 'floor') {
            $floor = $input['floor'] ?? '';
            $stmt = $conn->prepare("UPDATE checkin_records SET floor=? WHERE id=?");
            if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
            $stmt->bind_param("si", $floor, $checkin_id);
            if (!$stmt->execute()) sendError('樓層更新失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
            sendSuccess(['checkin_id' => $checkin_id, 'floor' => $floor, 'message' => '樓層已更新']);
        }

        // ── 一般欄位編輯（不動 floor 和 checkout_time）──
        $purpose = $input['purpose'] ?? '';
        $remark  = $input['remark']  ?? '';

        $stmt = $conn->prepare(
            "UPDATE checkin_records SET purpose=?, remark=? WHERE id=?"
        );
        if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');

        $stmt->bind_param("ssi", $purpose, $remark, $checkin_id);
        if (!$stmt->execute()) sendError('更新失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');

        sendSuccess(['checkin_id' => $checkin_id, 'message' => '紀錄已更新']);
    }

    // ===== [DELETE] 刪除報到紀錄 =====
    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);

        // ── 區間批次刪除 ──
        if (!empty($input['batch_delete']) && !empty($input['start_date']) && !empty($input['end_date'])) {
            $start = $input['start_date'];
            $end   = $input['end_date'];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                sendError('日期格式錯誤', 'INVALID_DATE_FORMAT');
            }
            if ($start > $end) sendError('開始日期不能晚於結束日期', 'INVALID_DATE_RANGE');

            // 先取得範圍內的 checkin id 清單（子表有 CASCADE，刪主表即可）
            $stmt = $conn->prepare("DELETE FROM checkin_records WHERE DATE(checkin_time) BETWEEN ? AND ?");
            if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
            $stmt->bind_param("ss", $start, $end);
            if (!$stmt->execute()) sendError('批次刪除失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
            $affected = $stmt->affected_rows;

            sendSuccess(['message' => "已刪除 {$affected} 筆報到紀錄（{$start} 至 {$end}）", 'deleted_count' => $affected]);
        }

        // ── 單筆刪除 ──
        if (empty($input['checkin_id'])) {
            sendError('缺少 checkin_id', 'MISSING_PARAMS');
        }

        $checkin_id = (int) $input['checkin_id'];

        // checkin_parents / checkin_children 已設 ON DELETE CASCADE，直接刪主表即可
        $stmt = $conn->prepare("DELETE FROM checkin_records WHERE id = ?");
        if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');

        $stmt->bind_param("i", $checkin_id);
        if (!$stmt->execute()) sendError('刪除失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');

        sendSuccess(['checkin_id' => $checkin_id, 'message' => '紀錄已刪除']);
    }

    // ===== [GET] 查詢報到記錄 =====
    if ($method === 'GET') {
        // 取得查詢參數
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        if ($start === '') $start = date('Y-m-d');
        if ($end === '') $end = date('Y-m-d');
        $keyword = "%" . trim($_GET['keyword'] ?? '') . "%";

        // 驗證日期格式
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
        ) {
            sendError('日期格式錯誤，應為 YYYY-MM-DD', 'INVALID_DATE_FORMAT');
        }

        if (strtotime($start) > strtotime($end)) {
            sendError('開始日期不能晚於結束日期', 'INVALID_DATE_RANGE');
        }

        // ===== 改進的 SQL 查詢 (相容 MySQL 5.7 及以上) =====
        $sql = "
            SELECT 
                cr.id,
                cr.member_id,
                cr.checkin_time,
                cr.remark,
                cr.checkout_time,
                cr.floor,
                m.parent_name,
                m.phone,
                cr.adult_male,
                cr.adult_female,
                cr.purpose,
                cr.is_reservation,
                cr.course_name,
                cr.attendance,
                m.relationship,
                m.city_region,
                m.identity,
                m.identity_country,
                (SELECT COUNT(*) FROM checkin_parents WHERE checkin_id = cr.id) as parent_count,
                (SELECT COUNT(*) FROM checkin_children WHERE checkin_id = cr.id) as child_count
            FROM checkin_records cr
            JOIN members m ON cr.member_id = m.id
            WHERE DATE(cr.checkin_time) BETWEEN ? AND ?
            AND (m.parent_name LIKE ? OR m.phone LIKE ?)
            ORDER BY cr.checkin_time DESC
            LIMIT 1000
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            sendError('資料庫查詢準備失敗: ' . $conn->error, 'DB_PREPARE_ERROR');
        }

        $stmt->bind_param("ssss", $start, $end, $keyword, $keyword);

        if (!$stmt->execute()) {
            sendError('查詢失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');
        }

        $result = $stmt->get_result();
        $records = [];

        while ($row = $result->fetch_assoc()) {
            // 取得每筆報到記錄的詳細資訊
            $record = $row;

            // 取得入館家長列表
            $parents_sql = "
                SELECT p.id, p.name, p.gender
                FROM checkin_parents cp
                JOIN parents p ON cp.parent_id = p.id
                WHERE cp.checkin_id = ?
                ORDER BY cp.id ASC
            ";
            $parents_stmt = $conn->prepare($parents_sql);
            $parents_stmt->bind_param("i", $row['id']);
            $parents_stmt->execute();
            $parents_result = $parents_stmt->get_result();
            $record['parents'] = [];
            while ($parent = $parents_result->fetch_assoc()) {
                $record['parents'][] = $parent;
            }
            $parents_stmt->close();

            // 取得入館幼兒列表
            $children_sql = "
                SELECT c.id, c.name, c.gender, c.birthday
                FROM checkin_children cc
                JOIN children c ON cc.child_id = c.id
                WHERE cc.checkin_id = ?
            ";
            $children_stmt = $conn->prepare($children_sql);
            $children_stmt->bind_param("i", $row['id']);
            $children_stmt->execute();
            $children_result = $children_stmt->get_result();
            $record['children'] = [];
            while ($child = $children_result->fetch_assoc()) {
                $record['children'][] = $child;
            }
            $children_stmt->close();

            $records[] = $record;
        }

        sendSuccess([
            'total' => count($records),
            'records' => $records,
            'query' => [
                'start_date' => $start,
                'end_date' => $end,
                'keyword' => trim($_GET['keyword'] ?? '')
            ]
        ]);
    }

    // 不支持的請求方法
    sendError('不支持的請求方法: ' . $method, 'METHOD_NOT_ALLOWED');
} catch (Exception $e) {
    sendError('系統錯誤: ' . $e->getMessage(), 'SYSTEM_ERROR');
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}