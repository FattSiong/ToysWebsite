<?php
// uploadMedia.php
require_once '../lib/SimpleImage.php';

/**
 * Handles multiple file uploads for products (Images via SimpleImage, Videos via native).
 * * @param PDO $pdo
 * @param int $product_id
 * @param array $files The $_FILES['product_images'] array
 */
function uploadProductMedia($pdo, $product_id, $files)
{
    // 1. Directory Setup
    $targetDir = '../uploads/products/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // 2. Check existing Primary status
    // We need to know if we should assign 'is_primary = 1' to a new upload
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_media WHERE product_id = ? AND is_primary = 1");
    $stmt->execute([$product_id]);
    $hasPrimary = $stmt->fetchColumn() > 0;

    // 3. Normalize $_FILES array for iteration
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
        $mediaType = '';

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
                      ->autoOrient()        // Fix rotation
                      ->bestFit(1200, 1200) // Resize max dim to 1200px
                      ->toFile($dest, null, 85); // Save at 85% quality

                $uploadedPath = 'uploads/products/' . $newName; // Path for DB
                $mediaType = 'image';

            } catch (Exception $e) {
                // error_log("Image upload failed: " . $e->getMessage());
                continue; // Skip this file
            }
        } 
        // --- B. VIDEO HANDLER (Native) ---
        elseif (strpos($fileType, 'video/') === 0) {
            try {
                $allowed = ['mp4', 'webm', 'ogg', 'mov'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed)) {
                    continue; 
                }

                $newName = uniqid('vid_', true) . '.' . $ext;
                $dest = $targetDir . $newName;

                if (move_uploaded_file($tmpName, $dest)) {
                    $uploadedPath = 'uploads/products/' . $newName; // Path for DB
                    $mediaType = 'video';
                }
            } catch (Exception $e) {
                continue;
            }
        }

        // --- C. DATABASE INSERT ---
        if ($uploadedPath && $mediaType) {
            // Logic: If no primary exists AND this is an image, make it primary.
            // Videos are forced to 0.
            $isPrimary = 0;
            if (!$hasPrimary && $mediaType === 'image') {
                $isPrimary = 1;
                $hasPrimary = true; // Flag that we now have a primary
            }

            $sql = "INSERT INTO product_media (product_id, media_type, file_path, is_primary) VALUES (?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$product_id, $mediaType, $uploadedPath, $isPrimary]);
        }
    }
}
?>