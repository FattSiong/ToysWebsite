<?php
require_once 'vendor/autoload.php';
// We use a simplified DB connection here because Dompdf conflicts with some include headers
require_once 'includes/db.php'; 
// Start session to check login
if (session_status() === PHP_SESSION_NONE) session_start();

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Security Check
if (!isset($_SESSION['user_id']) || !isset($_GET['order_id'])) {
    die("Access Denied");
}

$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'];

// 2. Fetch Order Data
$sql = "SELECT o.*, u.username, sa.line1, sa.city, sa.state, sa.postcode, sa.phone
        FROM orders o
        JOIN users u ON o.user_id = u.user_id
        JOIN shipping_addresses sa ON o.shipping_address_id = sa.shipping_address_id
        WHERE o.order_id = ? AND o.user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) die("Order not found.");

// 3. Fetch Order Items
$sqlItems = "SELECT oi.*, p.name FROM order_items oi 
             JOIN products p ON oi.product_id = p.product_id 
             WHERE oi.order_id = ?";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$order_id]);
$items = $stmtI->fetchAll();

// 4. HTML TEMPLATE FOR PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Helvetica, sans-serif; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
        .header h1 { color: #007bff; margin: 0; }
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { vertical-align: top; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .items-table th { background: #f8f9fa; border-bottom: 1px solid #ddd; padding: 10px; text-align: left; }
        .items-table td { border-bottom: 1px solid #eee; padding: 10px; }
        .totals { margin-top: 20px; text-align: right; }
        .totals table { float: right; width: 40%; }
        .totals td { padding: 5px; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 10px; }
        .badge { background: #28a745; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>ToyShop</h1>
        <p>Official E-Receipt</p>
    </div>

    <table class="info-table">
        <tr>
            <td>
                <strong>Billed To:</strong><br>
                ' . htmlspecialchars($order['username']) . '<br>
                ' . htmlspecialchars($order['line1']) . '<br>
                ' . htmlspecialchars($order['city'] . ', ' . $order['state'] . ' ' . $order['postcode']) . '<br>
                Phone: ' . htmlspecialchars($order['phone']) . '
            </td>
            <td style="text-align: right;">
                <strong>Order Details:</strong><br>
                Order ID: #' . $order['order_id'] . '<br>
                Date: ' . date('M d, Y', strtotime($order['order_date'])) . '<br>
                Status: ' . $order['order_status'] . '
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Product</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Price</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($items as $item) {
            $rowTotal = $item['price_at_purchase'] * $item['quantity'];
            $html .= '
            <tr>
                <td>' . htmlspecialchars($item['name']) . '</td>
                <td style="text-align: center;">' . $item['quantity'] . '</td>
                <!-- CHANGED TO RM -->
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
                <!-- CHANGED TO RM -->
                <td>RM ' . number_format($order['total_amount'] + $order['point_discount'], 2) . '</td>
            </tr>';

            if ($order['point_discount'] > 0) {
                $html .= '
                <tr>
                    <td style="color: green;"><strong>Discount:</strong></td>
                    <!-- CHANGED TO RM -->
                    <td style="color: green;">-RM ' . number_format($order['point_discount'], 2) . '</td>
                </tr>';
            }

$html .= '
            <tr>
                <td style="font-size: 16px;"><strong>Grand Total:</strong></td>
                <!-- CHANGED TO RM -->
                <td style="font-size: 16px; font-weight: bold;">RM ' . number_format($order['total_amount'], 2) . '</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Thank you for shopping with ToyShop! <br>
        This is a computer-generated receipt and requires no signature.
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