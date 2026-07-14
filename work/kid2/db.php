<?php
// 時區設定（台灣 UTC+8）
date_default_timezone_set('Asia/Taipei');

// 資料庫連線設定
$host = 'localhost';        // 資料庫主機
$username = 'root';         // 資料庫使用者名稱
$password = '';             // 資料庫密碼
$database = 'litosan_demo'; // 資料庫名稱

// 建立連線
$conn = new mysqli($host, $username, $password, $database);

// 檢查連線
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => '資料庫連線失敗: ' . $conn->connect_error
    ]));
}

// 設定字元編碼與 MySQL 時區
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");