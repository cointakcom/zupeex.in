<?php
/**
 * ======================================================
 * KYC_IMAGE.PHP - Secure KYC Document Image Server (FIXED)
 * Ludo Tournament Platform
 * Version: 1.2.0 - IMPROVED SECURITY
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

$docId = intval($_GET['doc_id'] ?? 0);
$side = $_GET['side'] ?? 'front';

if ($docId <= 0 || !in_array($side, ['front', 'back'], true)) {
    http_response_code(400);
    exit('Invalid request');
}

// Check authentication early
if (!isLoggedIn() && !isAdminLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT user_id, document_image_front, document_image_back FROM kyc_documents WHERE id = :id");
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        exit('Not found');
    }

    // Authorization check
    $authorized = false;
    $currentUserId = isLoggedIn() ? getCurrentUserId() : null;
    
    if ($currentUserId && $currentUserId === intval($doc['user_id'])) {
        // User can view their own documents
        $authorized = true;
    } elseif (isAdminLoggedIn()) {
        // Admin can view all documents
        $authorized = true;
    }

    if (!$authorized) {
        http_response_code(403);
        exit('Forbidden');
    }

    $relativePath = $side === 'front' ? $doc['document_image_front'] : $doc['document_image_back'];
    
    if (!$relativePath || $relativePath === 'n/a' || $relativePath === '') {
        http_response_code(404);
        exit('No image on this side');
    }

    // Secure path resolution
    $baseDir = realpath(__DIR__ . '/../uploads/kyc');
    if (!$baseDir) {
        http_response_code(500);
        exit('Upload directory not found');
    }

    $fullPath = realpath($baseDir . '/' . $relativePath);
    
    // Path traversal protection
    if (!$fullPath || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
        http_response_code(404);
        exit('File not found');
    }

    // Check file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    $fileExtension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        http_response_code(403);
        exit('Invalid file type');
    }

    // Get MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fullPath);
    finfo_close($finfo);

    // Verify MIME type matches extension
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimeTypes)) {
        http_response_code(403);
        exit('Invalid MIME type');
    }

    // Set security headers
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    
    // Stream file
    readfile($fullPath);
    exit;
} catch (Exception $e) {
    error_log('[KYC Image Error] ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
?>