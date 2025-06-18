<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (!isset($_GET['path'])) {
    error_log('No file path provided');
    echo json_encode(['status' => 'danger', 'message' => 'لم يتم تحديد مسار الملف'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filePath = $_GET['path'];
if (!file_exists($filePath) || !is_file($filePath)) {
    error_log('File not found or not a file: ' . $filePath);
    echo json_encode(['status' => 'danger', 'message' => 'الملف غير موجود'], JSON_UNESCAPED_UNICODE);
    exit;
}

$content = file_get_contents($filePath);
echo json_encode([
    'status' => 'success',
    'message' => 'تم جلب الملف بنجاح',
    'content' => htmlspecialchars($content)
], JSON_UNESCAPED_UNICODE);
?>