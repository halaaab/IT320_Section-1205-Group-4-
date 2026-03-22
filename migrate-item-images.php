<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/back-end/config/database.php';

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

Configuration::instance([
    'cloud' => [
             'cloud_name' => 'dwsafdzwr',
        'api_key'    => '553757457562639',
        'api_secret' => 'yejCp7rI1mCq-4cpw-lBmbyD-iA'
    ],
    'url' => [
        'secure' => true
    ]
]);

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB
const MAX_WIDTH = 1800;
const JPEG_QUALITY = 82;
const PNG_COMPRESSION = 8;

/**
 * Creates a smaller temporary copy of an image if needed.
 * Returns path to file that should be uploaded.
 */
function prepareImageForUpload(string $sourcePath): string
{
    $fileSize = filesize($sourcePath);
    if ($fileSize !== false && $fileSize <= MAX_UPLOAD_BYTES) {
        return $sourcePath;
    }

    $info = getimagesize($sourcePath);
    if ($info === false) {
        throw new Exception("Unsupported or unreadable image.");
    }

    $mime = $info['mime'] ?? '';
    $origWidth = $info[0] ?? 0;
    $origHeight = $info[1] ?? 0;

    switch ($mime) {
        case 'image/jpeg':
            $src = imagecreatefromjpeg($sourcePath);
            $extension = '.jpg';
            break;

        case 'image/png':
            $src = imagecreatefrompng($sourcePath);
            $extension = '.png';
            break;

        default:
            throw new Exception("Only JPEG and PNG are supported for auto-compression.");
    }

    if (!$src) {
        throw new Exception("Failed to load image for compression.");
    }

    $targetWidth = $origWidth;
    $targetHeight = $origHeight;

    if ($origWidth > MAX_WIDTH) {
        $ratio = MAX_WIDTH / $origWidth;
        $targetWidth = (int) round($origWidth * $ratio);
        $targetHeight = (int) round($origHeight * $ratio);
    }

    $dst = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$dst) {
        imagedestroy($src);
        throw new Exception("Failed to create resized image.");
    }

    // Preserve PNG transparency
    if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $origWidth,
        $origHeight
    );

    $tempPath = tempnam(sys_get_temp_dir(), 'replate_img_');
    if ($tempPath === false) {
        imagedestroy($src);
        imagedestroy($dst);
        throw new Exception("Failed to create temporary file.");
    }

    $finalTempPath = $tempPath . $extension;

    if (!rename($tempPath, $finalTempPath)) {
        @unlink($tempPath);
        imagedestroy($src);
        imagedestroy($dst);
        throw new Exception("Failed to prepare temporary image file.");
    }

    $saved = false;

    if ($mime === 'image/jpeg') {
        $saved = imagejpeg($dst, $finalTempPath, JPEG_QUALITY);
    } elseif ($mime === 'image/png') {
        $saved = imagepng($dst, $finalTempPath, PNG_COMPRESSION);
    }

    imagedestroy($src);
    imagedestroy($dst);

    if (!$saved || !file_exists($finalTempPath)) {
        @unlink($finalTempPath);
        throw new Exception("Failed to save compressed image.");
    }

    $newSize = filesize($finalTempPath);
    if ($newSize === false || $newSize > MAX_UPLOAD_BYTES) {
        @unlink($finalTempPath);
        throw new Exception("Image is still larger than 10MB after compression.");
    }

    return $finalTempPath;
}

$collection = Database::getInstance()->getCollection('items');
$items = $collection->find()->toArray();

foreach ($items as $item) {
    $photoUrl = $item['photoUrl'] ?? '';

    if (!$photoUrl) {
        echo "Skipping item {$item['_id']} - no photoUrl\n";
        continue;
    }

    // Already migrated
    if (str_starts_with($photoUrl, 'http://') || str_starts_with($photoUrl, 'https://')) {
        echo "Skipping item {$item['_id']} - already hosted\n";
        continue;
    }

    $filename = basename($photoUrl);
    $fullPath = __DIR__ . '/uploads/items/' . $filename;

    if (!file_exists($fullPath)) {
        echo "Skipping item {$item['_id']} - file not found: {$fullPath}\n";
        continue;
    }

    $uploadPath = $fullPath;
    $usedTempFile = false;

    try {
        $uploadPath = prepareImageForUpload($fullPath);
        $usedTempFile = ($uploadPath !== $fullPath);

        $uploadResult = (new UploadApi())->upload($uploadPath, [
            'folder' => 'replate/items'
        ]);

        $newUrl = $uploadResult['secure_url'] ?? null;

        if (!$newUrl) {
            throw new Exception("No secure_url returned from Cloudinary.");
        }

        $collection->updateOne(
            ['_id' => new ObjectId((string)$item['_id'])],
            [
                '$set' => [
                    'photoUrl'  => $newUrl,
                    'updatedAt' => new UTCDateTime()
                ]
            ]
        );

        echo "Updated item {$item['_id']} -> {$newUrl}\n";
    } catch (Exception $e) {
        echo "Error for item {$item['_id']}: " . $e->getMessage() . "\n";
    } finally {
        if ($usedTempFile && file_exists($uploadPath)) {
            @unlink($uploadPath);
        }
    }
}