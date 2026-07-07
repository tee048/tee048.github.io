<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);

// 取得資料
$phone = $input['phone'] ?? '';
$parent_name = $input['parent_name'] ?? '';
$parent_gender = $input['parent_gender'] ?? '';
$has_companions = $input['has_companions'] ?? false;
$companions = $input['companions'] ?? [];  // [{ name, gender }, ...]
$city_region = $input['city_region'] ?? '';
$sub_district = $input['sub_district'] ?? '';
$other_city = $input['other_city'] ?? '';
$purpose = $input['purpose'] ?? '';
$identity = is_array($input['identity']) ? implode(',', $input['identity']) : ($input['identity'] ?? '');
$identity_country = $input['identity_country'] ?? '';
$relationship = $input['relationship'] ?? '';
$children = $input['children'] ?? [];
$source = $input['source'] ?? '';

// 計算大人人數：填答者 + 有效陪同成人
$adult_male = 0;
$adult_female = 0;
$other_adults = 0;

// 填答者性別
if ($parent_gender === '男') $adult_male++;
elseif ($parent_gender === '女') $adult_female++;
else $other_adults++;

// 陪同成人
if ($has_companions && !empty($companions)) {
    foreach ($companions as $c) {
        if (empty($c['name'])) continue;
        if (($c['gender'] ?? '') === '男') $adult_male++;
        elseif (($c['gender'] ?? '') === '女') $adult_female++;
        else $other_adults++;
    }
}
// 其他性別計入男性欄位（維持後端相容性）
$adult_male += $other_adults;

// 驗證必填欄位
if ($source === 'registration') {
    if (empty($phone) || empty($parent_name)) {
        echo json_encode(['success' => false, 'message' => '缺少手機或姓名'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    if (empty($phone) || empty($parent_name) || empty($city_region)) {
        echo json_encode(['success' => false, 'message' => '請填寫所有必填欄位'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // 開始交易
    $conn->begin_transaction();

    // 決定最終的地區資訊
    $final_location = $city_region;
    if ($city_region === '桃園市中壢區' && !empty($sub_district)) {
        $final_location .= ' - ' . $sub_district;
    } elseif ($city_region === '桃園市其他行政區' && !empty($input['taoyuan_district'])) {
        $final_location = '桃園市 ' . $input['taoyuan_district'];
    } elseif ($city_region === '其他縣市' && !empty($other_city)) {
        $final_location = $other_city;
    }

    // 1. 新增會員基本資料
    $stmt = $conn->prepare("
    INSERT INTO members (
        phone, 
        parent_name, 
        city_region, 
        purpose, 
        identity,
        identity_country, 
        relationship, 
        adult_male, 
        adult_female,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

    $stmt->bind_param(
        "sssssssii",
        $phone,
        $parent_name,
        $final_location,
        $purpose,
        $identity,
        $identity_country,
        $relationship,
        $adult_male,
        $adult_female
    );

    $stmt->execute();
    $member_id = $conn->insert_id;

    // 2. 新增填答者為第一位家長
    $stmt = $conn->prepare("
        INSERT INTO parents (member_id, name, gender) 
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iss", $member_id, $parent_name, $parent_gender);
    $stmt->execute();
    $first_parent_id = $conn->insert_id;

    // 2b. 新增陪同成人到 parents
    $companion_ids = [];
    if ($has_companions && !empty($companions)) {
        $stmt = $conn->prepare("INSERT INTO parents (member_id, name, gender) VALUES (?, ?, ?)");
        foreach ($companions as $c) {
            if (empty($c['name'])) continue;
            $c_gender = $c['gender'] ?? '';
            $stmt->bind_param("iss", $member_id, $c['name'], $c_gender);
            $stmt->execute();
            $companion_ids[] = $conn->insert_id;
        }
    }

    // 3. 新增幼兒資料
    $child_ids = [];
    if (!empty($children)) {
        $stmt = $conn->prepare("
            INSERT INTO children (member_id, name, gender, birthday) 
            VALUES (?, ?, ?, ?)
        ");

        foreach ($children as $child) {
            if (!empty($child['name']) && !empty($child['gender']) && !empty($child['birthday'])) {
                $stmt->bind_param(
                    "isss",
                    $member_id,
                    $child['name'],
                    $child['gender'],
                    $child['birthday']
                );
                $stmt->execute();
                $child_ids[] = $conn->insert_id;
            }
        }
    }

    // 4. 建立首次報到記錄（報名連動來源不自動報到）
    $checkin_id = null;
    if ($source !== 'registration') {
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
            $attendance = 0;
        } else {
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
            }
        }

        $checkin_time = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
        INSERT INTO checkin_records (member_id, checkin_time, adult_male, adult_female, purpose, is_reservation, course_name, attendance) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isissiis", $member_id, $checkin_time, $adult_male, $adult_female, $purpose, $is_reservation, $res_course, $attendance);
        $stmt->execute();
        $checkin_id = $conn->insert_id;

        // 5. 記錄本次入館的家長（填答者）
        $stmt = $conn->prepare("
            INSERT INTO checkin_parents (checkin_id, parent_id) 
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $checkin_id, $first_parent_id);
        $stmt->execute();

        // 5b. 記錄陪同成人
        if (!empty($companion_ids)) {
            $stmt = $conn->prepare("INSERT INTO checkin_parents (checkin_id, parent_id) VALUES (?, ?)");
            foreach ($companion_ids as $cid) {
                $stmt->bind_param("ii", $checkin_id, $cid);
                $stmt->execute();
            }
        }

        // 6. 記錄本次入館的幼兒
        if (!empty($child_ids)) {
            $stmt = $conn->prepare("
                INSERT INTO checkin_children (checkin_id, child_id) 
                VALUES (?, ?)
            ");
            foreach ($child_ids as $child_id) {
                $stmt->bind_param("ii", $checkin_id, $child_id);
                $stmt->execute();
            }
        }
    } // end if source !== registration

    // 提交交易
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $source === 'registration' ? '會員建立完成' : '歡迎 ' . $parent_name . '！註冊與報到完成',
        'member_id' => $member_id,
        'checkin_id' => $checkin_id
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => '註冊失敗: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();