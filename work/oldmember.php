<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';
$selected_parents = $input['selected_parents'] ?? [];  // 新格式：[{ id, gender }, ...]
$selected_children = $input['selected_children'] ?? [];
$additional_parent = $input['additional_parent'] ?? '';
$companions = $input['companions'] ?? [];
$additional_child = $input['additional_child'] ?? null;
$purpose = $input['purpose'] ?? '';

$adult_male   = isset($input['adult_male'])   ? (int)$input['adult_male']   : 0;
$adult_female = isset($input['adult_female']) ? (int)$input['adult_female'] : 0;

try {
    // 開始交易
    $conn->begin_transaction();

    // 查詢會員 ID
    $stmt = $conn->prepare("SELECT id, parent_name FROM members WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('找不到該會員資料');
    }

    $member = $result->fetch_assoc();
    $member_id = $member['id'];

    // 查詢是否有網路預約（當天、通過、同手機）
    $today = date('Y-m-d');
    $is_reservation = 0;
    $res_course = '';
    $attendance = null;
    $reg_stmt = $conn->prepare("
        SELECT course_name FROM registrations 
        WHERE phone = ? AND session_date = ? AND status = '通過'
        AND course_name NOT LIKE '%自由活動%'
        LIMIT 1
    ");
    $reg_stmt->bind_param("ss", $phone, $today);
    $reg_stmt->execute();
    $reg_result = $reg_stmt->get_result();
    if ($reg_row = $reg_result->fetch_assoc()) {
        $is_reservation = 1;
        $res_course = $reg_row['course_name'];
        $attendance = 0; // 預設未到課
    } else {
        // 再查是否有自由活動預約（有預約但不需到課按鈕）
        $reg_stmt2 = $conn->prepare("
            SELECT course_name FROM registrations 
            WHERE phone = ? AND session_date = ? AND status = '通過'
            LIMIT 1
        ");
        $reg_stmt2->bind_param("ss", $phone, $today);
        $reg_stmt2->execute();
        $reg_result2 = $reg_stmt2->get_result();
        if ($reg_row2 = $reg_result2->fetch_assoc()) {
            $is_reservation = 1;
            $res_course = $reg_row2['course_name'];
            // 自由活動不設 attendance
        }
    }

    // 新增報到記錄
    $checkin_time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
    INSERT INTO checkin_records (member_id, checkin_time, adult_male, adult_female, purpose, is_reservation, course_name, attendance) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isissiis", $member_id, $checkin_time, $adult_male, $adult_female, $purpose, $is_reservation, $res_course, $attendance);
    $stmt->execute();
    $checkin_id = $conn->insert_id;

    // 記錄入館的家長，並補寫 gender（若 DB 尚為空）
    if (!empty($selected_parents)) {
        $ins_cp = $conn->prepare("INSERT INTO checkin_parents (checkin_id, parent_id) VALUES (?, ?)");
        $upd_g  = $conn->prepare("UPDATE parents SET gender = ? WHERE id = ? AND (gender = '' OR gender IS NULL)");
        foreach ($selected_parents as $p) {
            // 相容舊格式（純 ID）與新格式（{ id, gender }）
            $pid    = is_array($p) ? (int)($p['id']     ?? 0) : (int)$p;
            $gender = is_array($p) ? ($p['gender'] ?? '') : '';
            if ($pid <= 0) continue;
            $ins_cp->bind_param("ii", $checkin_id, $pid);
            $ins_cp->execute();
            if ($gender !== '') {
                $upd_g->bind_param("si", $gender, $pid);
                $upd_g->execute();
            }
        }
    }

    // 記錄入館的幼兒
    if (!empty($selected_children)) {
        $stmt = $conn->prepare("
            INSERT INTO checkin_children (checkin_id, child_id) 
            VALUES (?, ?)
        ");
        foreach ($selected_children as $child_id) {
            $stmt->bind_param("ii", $checkin_id, $child_id);
            $stmt->execute();
        }
    }

    // 新增額外家長（舊格式相容）
    if (!empty($additional_parent)) {
        $empty_gender = '';
        $stmt = $conn->prepare("
            INSERT INTO parents (member_id, name, gender) 
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iss", $member_id, $additional_parent, $empty_gender);
        $stmt->execute();
        $new_parent_id = $conn->insert_id;

        // 同時加入本次報到記錄
        $stmt = $conn->prepare("
            INSERT INTO checkin_parents (checkin_id, parent_id) 
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $checkin_id, $new_parent_id);
        $stmt->execute();
    }

    // 新增陪同成人（新格式：companions 陣列）
    if (!empty($companions)) {
        $ins_parent = $conn->prepare("INSERT INTO parents (member_id, name, gender) VALUES (?, ?, ?)");
        $ins_chkin  = $conn->prepare("INSERT INTO checkin_parents (checkin_id, parent_id) VALUES (?, ?)");
        foreach ($companions as $c) {
            if (empty($c['name'])) continue;
            $c_gender = $c['gender'] ?? '';
            $ins_parent->bind_param("iss", $member_id, $c['name'], $c_gender);
            $ins_parent->execute();
            $new_cid = $conn->insert_id;
            $ins_chkin->bind_param("ii", $checkin_id, $new_cid);
            $ins_chkin->execute();
        }
    }

    // 新增額外幼兒
    if ($additional_child && !empty($additional_child['name'])) {
        $stmt = $conn->prepare("
            INSERT INTO children (member_id, name, gender, birthday) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isss",
            $member_id,
            $additional_child['name'],
            $additional_child['gender'],
            $additional_child['birthday']
        );
        $stmt->execute();
        $new_child_id = $conn->insert_id;

        // 同時加入本次報到記錄
        $stmt = $conn->prepare("
            INSERT INTO checkin_children (checkin_id, child_id) 
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $checkin_id, $new_child_id);
        $stmt->execute();
    }

    // 提交交易
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => '歡迎回來！報到完成',
        'checkin_id' => $checkin_id
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => '報到失敗: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();