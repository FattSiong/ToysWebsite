<?php
// includes/validation.php

/**
 * Validates a name/string field.
 * - Required
 * - Max 50 characters
 */
function validateName($name, $label = "Name") {
    $name = trim($name);
    if (empty($name)) {
        return "$label is required.";
    }
    if (strlen($name) > 50) {
        return "$label cannot exceed 50 characters.";
    }
    return null; // No error
}

/**
 * Validates quantity.
 * - Required (Cannot be empty)
 * - Must be a number
 * - Cannot be negative
 */
function validateQuantity($quantity, $label = "Stock Quantity") {
    // Check if empty (Strict check for empty string or null)
    if ($quantity === '' || $quantity === null) {
        return "$label is required.";
    }

    if (!is_numeric($quantity)) {
        return "$label must be a number.";
    }
    
    if ($quantity < 0) {
        return "$label cannot be negative.";
    }
    return null;
}

/**
 * Validates price.
 * - Required (Cannot be empty)
 * - Must be a number
 * - Cannot be negative
 */
function validatePrice($price, $label = "Price") {
    // Check if empty
    if ($price === '' || $price === null) {
        return "$label is required.";
    }

    if (!is_numeric($price)) {
        return "$label must be a valid number.";
    }

    if ($price < 0) {
        return "$label cannot be negative.";
    }
    return null;
}
?>