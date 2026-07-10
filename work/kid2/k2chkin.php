<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 引入資料庫連線
require_once 'db.php';

// 接收 JSON 資料
$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';

// 驗證手機號碼格式
if (empty($phone) || !preg_match('/^09\d{8}$/', $phone)) {
    echo json_encode([
        'success' => false,
        'message' => '手機號碼格式錯誤'
    ]);
    exit;
}

try {
    // 查詢該手機號碼是否存在
    $stmt = $conn->prepare("SELECT id, parent_name, city_region, relationship, identity, identity_country FROM members WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // 舊會員 - 取得所有家長和幼兒資料
        $member = $result->fetch_assoc();
        $member_id = $member['id'];

        // 判斷是否為「僅透過報名連動建立」而從未填寫完整問卷的會員
        // （連動會員建立時 city_region 與 identity 皆為空字串）
        $profile_incomplete = (trim($member['city_region'] ?? '') === '');

        // 取得家長列表
        $parents_stmt = $conn->prepare("
            SELECT id, name, gender
            FROM parents 
            WHERE member_id = ?
            ORDER BY id
        ");
        $parents_stmt->bind_param("i", $member_id);
        $parents_stmt->execute();
        $parents_result = $parents_stmt->get_result();
        $parents = [];
        while ($row = $parents_result->fetch_assoc()) {
            $parents[] = $row;
        }

        // 取得幼兒列表
        $children_stmt = $conn->prepare("
            SELECT id, name, gender, birthday 
            FROM children 
            WHERE member_id = ?
            ORDER BY id
        ");
        $children_stmt->bind_param("i", $member_id);
        $children_stmt->execute();
        $children_result = $children_stmt->get_result();
        $children = [];
        while ($row = $children_result->fetch_assoc()) {
            $children[] = $row;
        }

        echo json_encode([
            'success' => true,
            'is_new' => false,
            'member_id' => $member_id,
            'parents' => $parents,
            'children' => $children,
            'profile_incomplete' => $profile_incomplete,
            'relationship' => $member['relationship'] ?? ''
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // 新會員
        echo json_encode([
            'success' => true,
            'is_new' => true
        ], JSON_UNESCAPED_UNICODE);
    }

    $stmt->close();

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '資料庫查詢錯誤: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>