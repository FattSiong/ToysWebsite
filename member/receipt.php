<?php
require_once '../vendor/autoload.php';
require_once '../includes/db.php'; // Ensure this path is correct

if (session_status() === PHP_SESSION_NONE) session_start();

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Security Check
if (!isset($_SESSION['user_id']) || !isset($_GET['order_id'])) {
    die("Access Denied");
}

$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'];

// 2. Fetch Order Data (Updated to join Vouchers and get Receiver Name)
$sql = "SELECT o.*, 
        u.username, u.email,
        sa.receiver_name, sa.line1, sa.city, sa.state, sa.postcode, sa.phone,
        v.code as voucher_code
        FROM orders o
        JOIN users u ON o.user_id = u.user_id
        JOIN shipping_addresses sa ON o.shipping_address_id = sa.shipping_address_id
        LEFT JOIN vouchers v ON o.voucher_id = v.voucher_id
        WHERE o.order_id = ? AND o.user_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) die("Order not found.");

// 3. Fetch Order Items
$sqlItems = "SELECT oi.*, p.name 
             FROM order_items oi 
             JOIN products p ON oi.product_id = p.product_id 
             WHERE oi.order_id = ?";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$order_id]);
$items = $stmtI->fetchAll();

// --- CALCULATION LOGIC ---
// 1. Calculate the true subtotal based on items
$item_subtotal = 0;
foreach ($items as $item) {
    $item_subtotal += ($item['price_at_purchase'] * $item['quantity']);
}

// 2. Calculate Voucher Amount
// Logic: Voucher = (Item Subtotal) - (Points Discount) - (Final Amount Paid)
// We use max(0, ...) to prevent negative numbers due to floating point rounding
$voucher_discount = max(0, $item_subtotal - $order['point_discount'] - $order['total_amount']);

// Determine the display name (Receiver Name is preferred over Username)
$display_name = !empty($order['receiver_name']) ? $order['receiver_name'] : $order['username'];

// 4. HTML TEMPLATE
$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Helvetica, sans-serif; color: #333; font-size: 14px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
        .header h1 { color: #007bff; margin: 0; }
        .header p { margin: 5px 0 0; color: #666; font-size: 12px; }
        
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { vertical-align: top; padding: 5px 0; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .items-table th { background: #f8f9fa; border-bottom: 2px solid #ddd; padding: 10px; text-align: left; font-size: 12px; text-transform: uppercase; }
        .items-table td { border-bottom: 1px solid #eee; padding: 10px; }
        
        .totals { margin-top: 20px; text-align: right; }
        .totals table { float: right; width: 45%; border-collapse: collapse; }
        .totals td { padding: 5px; }
        .totals .final-row td { border-top: 2px solid #333; padding-top: 10px; font-weight: bold; font-size: 16px; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>ToyShop</h1>
        <p>Receipt / Tax Invoice</p>
    </div>

    <table class="info-table">
        <tr>
            <td width="60%">
                <strong>Billed To:</strong><br>
                ' . htmlspecialchars($display_name) . '<br>
                ' . htmlspecialchars($order['line1']) . '<br>
                ' . htmlspecialchars($order['city'] . ', ' . $order['state'] . ' ' . $order['postcode']) . '<br>
                Phone: ' . htmlspecialchars($order['phone']) . '<br>
                Email: ' . htmlspecialchars($order['email']) . '
            </td>
            <td width="40%" style="text-align: right;">
                <strong>Order Reference:</strong><br>
                Order ID: #' . $order['order_id'] . '<br>
                Date: ' . date('d M Y, h:i A', strtotime($order['order_date'])) . '<br>
                Status: ' . ucfirst($order['order_status']) . '
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th width="50%">Product</th>
                <th width="15%" style="text-align: center;">Qty</th>
                <th width="15%" style="text-align: right;">Price</th>
                <th width="20%" style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($items as $item) {
            $rowTotal = $item['price_at_purchase'] * $item['quantity'];
            $html .= '
            <tr>
                <td>' . htmlspecialchars($item['name']) . '</td>
                <td style="text-align: center;">' . $item['quantity'] . '</td>
                <td style="text-align: right;">RM ' . number_format($item['price_at_purchase'], 2) . '</td>
                <td style="text-align: right;">RM ' . number_format($rowTotal, 2) . '</td>
            </tr>';
        }

$html .= '
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td><strong>Subtotal:</strong></td>
                <td>RM ' . number_format($item_subtotal, 2) . '</td>
            </tr>';

            // 1. Voucher Discount
            if ($voucher_discount > 0) {
                $code = $order['voucher_code'] ? "(" . $order['voucher_code'] . ")" : "";
                $html .= '
                <tr>
                    <td style="color: #dc3545;">Voucher Discount ' . htmlspecialchars($code) . ':</td>
                    <td style="color: #dc3545;">- RM ' . number_format($voucher_discount, 2) . '</td>
                </tr>';
            }

            // 2. Points Discount
            if ($order['point_discount'] > 0) {
                $html .= '
                <tr>
                    <td style="color: #28a745;">Points Redeemed (' . $order['points_redeemed'] . '):</td>
                    <td style="color: #28a745;">- RM ' . number_format($order['point_discount'], 2) . '</td>
                </tr>';
            }

$html .= '
            <tr class="final-row">
                <td>Total Paid:</td>
                <td>RM ' . number_format($order['total_amount'], 2) . '</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Thank you for shopping with ToyShop!<br>
        This is a computer-generated receipt and requires no signature.<br>
        Generated on: ' . date('d M Y H:i:s') . '
    </div>

</body>
</html>';

// 5. GENERATE PDF
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isRemoteEnabled', true); 

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// 6. OUTPUT
$dompdf->stream("ToyShop_Receipt_" . $order_id . ".pdf", ["Attachment" => false]);
?>