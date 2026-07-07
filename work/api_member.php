<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';

function sendError($message, $code = 'ERROR') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}
function sendSuccess($data) {
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['member_id'])) {
            sendError('缺少 member_id', 'MISSING_PARAMS');
        }

        $member_id        = (int) $input['member_id'];
        $relationship     = $input['relationship']     ?? '';
        $phone            = $input['phone']            ?? '';
        $city_region      = $input['city_region']      ?? '';
        $identity         = $input['identity']         ?? '';
        $identity_country = $input['identity_country'] ?? '';

        if (!empty($phone) && !preg_match('/^09\d{8}$/', $phone)) {
            sendError('手機號碼格式錯誤', 'INVALID_PHONE');
        }

        $stmt = $conn->prepare(
            "UPDATE members SET relationship=?, phone=?, city_region=?, identity=?, identity_country=? WHERE id=?"
        );
        if (!$stmt) sendError('DB prepare 失敗: ' . $conn->error, 'DB_PREPARE_ERROR');

        $stmt->bind_param("sssssi",
            $relationship, $phone, $city_region, $identity, $identity_country, $member_id
        );
        if (!$stmt->execute()) sendError('更新失敗: ' . $stmt->error, 'DB_EXECUTE_ERROR');

        sendSuccess(['member_id' => $member_id, 'message' => '會員資料已更新']);
    }

    sendError('不支持的請求方法', 'METHOD_NOT_ALLOWED');

} catch (Exception $e) {
    sendError('系統錯誤: ' . $e->getMessage(), 'SYSTEM_ERROR');
} finally {
    if (isset($conn)) $conn->close();
}
