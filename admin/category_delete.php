<?php
session_start();
require_once '../includes/db.php';

// ==========================================
// 1. VALIDATE INPUT
// ==========================================
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: category_list.php?error=missing_id');
    exit;
}

$category_id = (int)$_GET['id'];

// ==========================================
// 2. PROCESS DELETION
// ==========================================
try {
    // Start Transaction
    $pdo->beginTransaction();

    // Step A: Delete associations in the pivot table (product_categories)
    // This prevents foreign key constraint errors
    $stmtPivot = $pdo->prepare("DELETE FROM product_categories WHERE category_id = ?");
    $stmtPivot->execute([$category_id]);

    // Step B: Delete the actual category
    $stmtCategory = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
    $stmtCategory->execute([$category_id]);

    // Commit changes
    $pdo->commit();

    // Redirect with success
    header('Location: category_list.php?success=deleted');
    exit;
} catch (PDOException $e) {
    // Rollback on error
    $pdo->rollBack();
    error_log("Error deleting category: " . $e->getMessage());

    // Redirect with error message
    header('Location: category_list.php?error=db_error');
    exit;
}
