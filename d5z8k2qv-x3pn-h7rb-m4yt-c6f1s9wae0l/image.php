<?php

/**
 * Image Server - Serves images from database BLOB storage
 * Usage: image.php?id=123
 */

// Include database configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/models/UploadedFile.php';

// Get file ID from URL parameter
$fileId = $_GET['id'] ?? '';

// Validate file ID
if (empty($fileId) || !is_numeric($fileId)) {
    http_response_code(400);
    exit('Invalid file ID');
}

try {
    // Get database connection
    $db = getDB();
    $uploadedFile = new UploadedFile($db);

    // Get file data from database
    $fileData = $uploadedFile->getFileById((int)$fileId);

    if (!$fileData) {
        http_response_code(404);
        exit('File not found');
    }

    // Validate that it's an image
    if (!preg_match('/^image\//', $fileData['mime_type'])) {
        http_response_code(400);
        exit('Not an image file')   ;
    }


    // Set appropriate headers
    header('Content-Type: ' . $fileData['mime_type']);
    header('Content-Length: ' . $fileData['file_size']);
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($fileData['created_at'])) . ' GMT');
    header('Content-Disposition: inline; filename="' . $fileData['original_filename'] . '"');

    // Output the image data
    echo $fileData['file_data'];
    exit();
} catch (Exception $e) {
    error_log('Image serving error: ' . $e->getMessage(), 3, __DIR__ . '/config/error.log');
    http_response_code(500);
    exit('Internal server error');
}
