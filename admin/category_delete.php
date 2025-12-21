<?php
// category_delete.php

session_start();
require_once '../includes/db.php'; // Adjust path if necessary

// Check if ID is set in the URL
if (isset($_GET['id'])) {
    $categoryId = $_GET['id'];

    try {
        // Start a transaction to ensure both deletions happen, or neither happens
        $pdo->beginTransaction();

        // 1. First, delete the associations in the pivot table (product_categories)
        // This prevents foreign key constraint errors if ON DELETE CASCADE isn't set in DB
        $sqlPivot = "DELETE FROM product_categories WHERE category_id = :id";
        $stmtPivot = $pdo->prepare($sqlPivot);
        $stmtPivot->execute([':id' => $categoryId]);

        // 2. Second, delete the actual category
        $sqlCategory = "DELETE FROM categories WHERE category_id = :id";
        $stmtCategory = $pdo->prepare($sqlCategory);
        $stmtCategory->execute([':id' => $categoryId]);

        // Commit the changes
        $pdo->commit();
    } catch (PDOException $e) {
        // If something goes wrong, roll back changes
        $pdo->rollBack();
        echo "Error deleting category: " . $e->getMessage();
        exit();
    }
}

// 3. Redirect back to the category list page
// Replace 'category_list.php' with the actual name of your main categories file
header('Location: category_list.php');
exit();
