<?php
require_once '../lib/SimpleImage.php';

/**
 * Handles multiple file uploads for products (Images via SimpleImage, Videos via native).
 * * @param PDO $pdo
 * @param int $product_id
 * @param array $files The $_FILES['product_images'] array
 */
function uploadProductMedia($pdo, $product_id, $files)
{
    // ==========================================
    // 1. DIRECTORY SETUP
    // ==========================================
    $targetDir = '../uploads/products/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // ==========================================
    // 2. CHECK PRIMARY STATUS
    // ==========================================
    // Check if the product already has a primary image
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_media WHERE product_id = ? AND is_primary = 1");
    $stmt->execute([$product_id]);
    $hasPrimary = $stmt->fetchColumn() > 0;

    // ==========================================
    // 3. PROCESS FILES
    // ==========================================
    $fileCount = count($files['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        // Skip errors or empty slots
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName  = $files['tmp_name'][$i];
        $fileName = $files['name'][$i];
        $fileType = $files['type'][$i];

        $uploadedPath = null;
        $mediaType    = '';

        // --- A. IMAGE HANDLER (SimpleImage) ---
        if (strpos($fileType, 'image/') === 0) {
            try {
                // Generate unique name
                $ext = pathinfo($fileName, PATHINFO_EXTENSION);
                $newName = uniqid('img_', true) . '.' . $ext;
                $dest = $targetDir . $newName;

                // Process with SimpleImage
                $image = new SimpleImage();
                $image->fromFile($tmpName)
                    ->autoOrient()         // Fix rotation from mobile uploads
                    ->bestFit(1200, 1200)  // Resize max dimensions
                    ->toFile($dest, null, 85); // Save at 85% quality

                $uploadedPath = 'uploads/products/' . $newName; // DB Path
                $mediaType    = 'image';
            } catch (Exception $e) {
                // error_log("Image upload failed: " . $e->getMessage());
                continue;
            }
        }
        // --- B. VIDEO HANDLER (Native) ---
        elseif (strpos($fileType, 'video/') === 0) {
            try {
                $allowed = ['mp4', 'webm', 'ogg', 'mov'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (in_array($ext, $allowed)) {
                    $newName = uniqid('vid_', true) . '.' . $ext;
                    $dest = $targetDir . $newName;

                    if (move_uploaded_file($tmpName, $dest)) {
                        $uploadedPath = 'uploads/products/' . $newName; // DB Path
                        $mediaType    = 'video';
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }

        // --- C. DATABASE INSERT ---
        if ($uploadedPath && $mediaType) {
            // Auto-assign primary if none exists and this is an image
            $isPrimary = 0;
            if (!$hasPrimary && $mediaType === 'image') {
                $isPrimary = 1;
                $hasPrimary = true; // Update flag
            }

            $sql = "INSERT INTO product_media (product_id, media_type, file_path, is_primary) VALUES (?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$product_id, $mediaType, $uploadedPath, $isPrimary]);
        }
    }
}
