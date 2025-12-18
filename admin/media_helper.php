<?php

/**
 * Handles uploading multiple files for a Product.
 * * @param PDO $pdo              Database connection
 * @param int $product_id       The ID of the product
 * @param array $files          The $_FILES['input_name'] array
 * @param string $upload_path   Target folder relative to the script calling this (e.g., '../uploads/products/')
 * @param string $web_path      Path to store in DB (e.g., 'uploads/products/')
 * * @return array                Array of errors (empty if successful)
 */
function uploadProductMedia($pdo, $product_id, $files, $upload_path = '../uploads/products/', $web_path_prefix = '/uploads/products/')
{
    $errors = [];

    // Check if files exist
    if (!isset($files['name'][0]) || empty($files['name'][0])) {
        return $errors;
    }

    // Ensure upload directory exists
    if (!is_dir($upload_path)) {
        if (!mkdir($upload_path, 0777, true)) {
            $errors[] = "Failed to create upload directory: $upload_path";
            return $errors;
        }
    }

    // NEW: Check if product already has a primary image
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM product_media WHERE product_id = ? AND is_primary = 1");
    $stmt_check->execute([$product_id]);
    $has_primary = $stmt_check->fetchColumn() > 0;

    $total_files = count($files['name']);

    // SQL Preparation
    $sql = "INSERT INTO product_media (product_id, file_path, media_type, is_primary) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);

    // Allowed Extensions
    $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_videos = ['mp4', 'webm', 'ogg', 'mov'];
    $allowed = array_merge($allowed_images, $allowed_videos);

    for ($i = 0; $i < $total_files; $i++) {
        $file_name = $files['name'][$i];
        $file_tmp = $files['tmp_name'][$i];
        $file_error = $files['error'][$i];

        if ($file_error !== 0) {
            $errors[] = "Error uploading file $file_name (Code: $file_error)";
            continue;
        }

        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed)) {
            $errors[] = "File type not allowed: $file_name";
            continue;
        }

        // Determine Media Type
        $media_type = 'image';
        if (in_array($file_ext, $allowed_videos)) {
            $media_type = 'video';
        }

        // Determine Primary Status
        // If no primary exists yet, make the FIRST file in this batch the primary one.
        $is_primary = 0;
        if (!$has_primary) {
            $is_primary = 1;
            $has_primary = true; // Ensure subsequent files in this loop are NOT primary
        }

        // Unique Name
        $new_file_name = uniqid('prod_', true) . '.' . $file_ext;
        $destination = $upload_path . $new_file_name;

        // Move File
        if (move_uploaded_file($file_tmp, $destination)) {
            $db_file_path = $web_path_prefix . $new_file_name;

            try {
                $stmt->execute([$product_id, $db_file_path, $media_type, $is_primary]);
            } catch (PDOException $e) {
                $errors[] = "Database error for $file_name: " . $e->getMessage();
            }
        } else {
            $errors[] = "Failed to move uploaded file: $file_name";
        }
    }

    return $errors;
}
