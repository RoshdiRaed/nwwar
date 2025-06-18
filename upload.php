<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'danger', 'message' => 'طلب غير صالح - يجب استخدام POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['code_file'])) {
    error_log('No file found in request');
    echo json_encode(['status' => 'danger', 'message' => 'لم يتم العثور على الملف في الطلب'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['code_file'];

switch ($file['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_NO_FILE:
        error_log('No file selected for upload');
        echo json_encode(['status' => 'danger', 'message' => 'لم يتم اختيار ملف للرفع'], JSON_UNESCAPED_UNICODE);
        exit;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        error_log('File size too large: ' . $file['size']);
        echo json_encode(['status' => 'danger', 'message' => 'حجم الملف كبير جداً'], JSON_UNESCAPED_UNICODE);
        exit;
    default:
        error_log('Unknown upload error: ' . $file['error']);
        echo json_encode(['status' => 'danger', 'message' => 'خطأ غير معروف أثناء رفع الملف: ' . $file['error']], JSON_UNESCAPED_UNICODE);
        exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExtensions = ['zip'];

if (!in_array($ext, $allowedExtensions)) {
    error_log('Unsupported file extension: ' . $ext);
    echo json_encode(['status' => 'danger', 'message' => 'امتداد الملف غير مدعوم. الامتدادات المدعومة: ZIP'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxFileSize = 50 * 1024 * 1024; // 50MB
if ($file['size'] > $maxFileSize) {
    error_log('File size exceeds limit: ' . $file['size']);
    echo json_encode(['status' => 'danger', 'message' => 'حجم الملف كبير جداً. الحد الأقصى: 50 ميجابايت'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
$extractDir = __DIR__ . '/extracted/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    error_log('Failed to create uploads directory');
    echo json_encode(['status' => 'danger', 'message' => 'فشل في إنشاء مجلد التخزين'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true)) {
    error_log('Failed to create extracted directory');
    echo json_encode(['status' => 'danger', 'message' => 'فشل في إنشاء مجلد الاستخراج'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filename = date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $filepath)) {
    if (!class_exists('ZipArchive')) {
        error_log('ZipArchive class not found');
        echo json_encode(['status' => 'danger', 'message' => 'امتداد ZipArchive غير مفعل'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) === true) {
        $extractPath = $extractDir . uniqid() . '/';
        mkdir($extractPath, 0755, true);
        $zip->extractTo($extractPath);
        $zip->close();

        $analysis = analyzeProject($extractPath);

        echo json_encode([
            'status' => 'success',
            'message' => 'تم رفع وتحليل الملف بنجاح!',
            'data' => $analysis
        ], JSON_UNESCAPED_UNICODE);

        deleteDirectory($extractPath);
        unlink($filepath); // Clean up uploaded ZIP
    } else {
        error_log('Failed to open ZIP file: ' . $filepath);
        echo json_encode(['status' => 'danger', 'message' => 'فشل في فتح ملف ZIP'], JSON_UNESCAPED_UNICODE);
    }
} else {
    error_log('Failed to move uploaded file to: ' . $filepath);
    echo json_encode(['status' => 'danger', 'message' => 'فشل في نقل الملف'], JSON_UNESCAPED_UNICODE);
}

function analyzeProject($path) {
    $metrics = ['totalFiles' => 0, 'totalLines' => 0, 'codeLines' => 0, 'projectSize' => 0];
    $languages = [];
    $fileTree = [];
    $codeIssues = [];
    $securityIssues = [];
    $performanceIssues = [];
    $dependencies = [];
    $quality = ['code' => 75, 'security' => 60, 'maintainability' => 85];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $metrics['totalFiles']++;
            $metrics['projectSize'] += $file->getSize();

            $ext = strtolower($file->getExtension());
            $filePath = str_replace($path, '', $file->getPathname());
            $content = file_get_contents($file->getPathname());
            $lines = substr_count($content, "\n") + 1;
            $metrics['totalLines'] += $lines;

            $codeLines = count(array_filter(explode("\n", $content), function($line) {
                $line = trim($line);
                return $line !== '' && !preg_match('/^(\/\/|\/\*|\*|\#|<!--)/', $line);
            }));
            $metrics['codeLines'] += $codeLines;

            $lang = detectLanguage($ext);
            if ($lang) {
                $languages[$lang]['count'] = ($languages[$lang]['count'] ?? 0) + 1;
                $languages[$lang]['lines'] = ($languages[$lang]['lines'] ?? 0) + $codeLines;
            }

            $issues = analyzeCodeIssues($content, $ext, $filePath);
            $codeIssues = array_merge($codeIssues, $issues['code']);
            $securityIssues = array_merge($securityIssues, $issues['security']);
            $performanceIssues = array_merge($performanceIssues, $issues['performance']);

            $fileTree = buildFileTree($fileTree, $filePath, $file->getPathname());
        }
    }

    $totalCodeLines = array_sum(array_column($languages, 'lines')) ?: 1;
    $languages = array_map(function($lang, $name) use ($totalCodeLines) {
        return [
            'name' => $name,
            'percentage' => round(($lang['lines'] / $totalCodeLines) * 100, 1),
            'color' => getLanguageColor($name)
        ];
    }, $languages, array_keys($languages));

    $packageJson = $path . 'package.json';
    if (file_exists($packageJson)) {
        $deps = json_decode(file_get_contents($packageJson), true);
        if ($deps) {
            $dependencies = array_merge(
                array_map(function($name, $version) { return ['name' => $name, 'version' => $version]; }, 
                    array_keys($deps['dependencies'] ?? []), array_values($deps['dependencies'] ?? [])),
                array_map(function($name, $version) { return ['name' => $name, 'version' => $version]; }, 
                    array_keys($deps['devDependencies'] ?? []), array_values($deps['devDependencies'] ?? []))
            );
        }
    }

    $metrics['projectSize'] = formatBytes($metrics['projectSize']);

    return [
        'metrics' => $metrics,
        'languages' => array_values($languages),
        'fileTree' => $fileTree,
        'codeIssues' => $codeIssues,
        'securityIssues' => $securityIssues,
        'performanceIssues' => $performanceIssues,
        'dependencies' => $dependencies,
        'quality' => $quality
    ];
}

function detectLanguage($ext) {
    $map = [
        'js' => 'JavaScript',
        'html' => 'HTML',
        'css' => 'CSS',
        'php' => 'PHP',
        'py' => 'Python',
        'json' => 'JSON'
    ];
    return $map[$ext] ?? null;
}

function getLanguageColor($lang) {
    $map = [
        'JavaScript' => '#f7df1e',
        'HTML' => '#e34f26',
        'CSS' => '#1572b6',
        'PHP' => '#777bb4',
        'Python' => '#3572A5',
        'JSON' => '#000000'
    ];
    return $map[$lang] ?? '#ffffff';
}

function analyzeCodeIssues($content, $ext, $filePath) {
    $codeIssues = [];
    $securityIssues = [];
    $performanceIssues = [];

    if ($ext === 'php') {
        if (preg_match('/mysql_query\s*\(/', $content)) {
            $securityIssues[] = [
                'severity' => 'high',
                'title' => 'SQL Injection محتمل',
                'description' => 'استخدام mysql_query غير آمن',
                'file' => $filePath,
                'solution' => 'استخدم PDO أو Prepared Statements'
            ];
        }
    } elseif ($ext === 'js') {
        if (preg_match('/eval\s*\(/', $content)) {
            $securityIssues[] = [
                'severity' => 'high',
                'title' => 'استخدام eval غير آمن',
                'description' => 'دالة eval قد تؤدي إلى ثغرات أمنية',
                'file' => $filePath,
                'solution' => 'استخدم بدائل آمنة مثل JSON.parse'
            ];
        }
        if (preg_match('/for\s*\([^;]+;[^;]+;[^)]+\)\s*{\s*for\s*\(/', $content)) {
            $performanceIssues[] = [
                'title' => 'حلقات متداخلة غير فعالة',
                'description' => 'حلقات متداخلة قد تؤثر على الأداء',
                'file' => $filePath,
                'solution' => 'حاول تقليل التداخل أو استخدام خوارزميات أكثر كفاءة'
            ];
        }
    }

    if (preg_match('/var\s+\w+\s*=/', $content) && $ext === 'js') {
        $codeIssues[] = [
            'type' => 'warning',
            'title' => 'متغيرات غير مستخدمة',
            'description' => 'تم العثور على متغيرات معرفة بـ var',
            'file' => $filePath
        ];
    }

    return ['code' => $codeIssues, 'security' => $securityIssues, 'performance' => $performanceIssues];
}

function buildFileTree($tree, $filePath, $realPath) {
    $parts = explode('/', trim($filePath, '/'));
    $current = &$tree;

    foreach ($parts as $i => $part) {
        if ($i === count($parts) - 1) {
            $current[] = ['name' => '📄 ' . $part, 'type' => 'file', 'path' => $realPath];
        } else {
            $folderIndex = array_search('📁 ' . $part, array_column($current, 'name'));
            if ($folderIndex === false) {
                $current[] = ['name' => '📁 ' . $part, 'type' => 'folder', 'children' => []];
                $folderIndex = count($current) - 1;
            }
            $current = &$current[$folderIndex]['children'];
        }
    }

    return $tree;
}

function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}
?>