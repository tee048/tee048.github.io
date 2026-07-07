<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$member_id = (int)($input['member_id'] ?? 0);
$name      = trim($input['name'] ?? '');
$gender    = $input['gender'] ?? '';
$birthday  = $input['birthday'] ?? '';

if (!$member_id || !$name) {
    echo json_encode(['success' => false, 'message' => '缺少必要參數'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 檢查是否已存在同名幼兒
$check = $conn->prepare("SELECT id FROM children WHERE member_id = ? AND name = ?");
$check->bind_param("is", $member_id, $name);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode(['success' => true, 'already_exists' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare("INSERT INTO children (member_id, name, gender, birthday) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $member_id, $name, $gender, $birthday);
$stmt->execute();

echo json_encode([
    'success' => true,
    'already_exists' => false,
    'child_id' => $conn->insert_id
], JSON_UNESCAPED_UNICODE);

$conn->close();
