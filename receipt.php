<?php
// Start session
session_start();

// Include database configuration
require_once 'config.php';

// Check if transaction ID is provided
if (!isset($_GET['transaction_id'])) {
    header("Location: transactions.php");
    exit();
}

$transaction_id = intval($_GET['transaction_id']);

// Fetch transaction details
try {
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASSWORD);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("
        SELECT t.*, 
               a1.account_name as from_account_name, 
               a1.account_number as from_account_number,
               a2.account_name as to_account_name, 
               a2.account_number as to_account_number,
               c.category_name,
               u.first_name, u.last_name
        FROM transactions t
        LEFT JOIN accounts a1 ON t.account_id = a1.account_id
        LEFT JOIN accounts a2 ON t.to_account_id = a2.account_id
        LEFT JOIN transaction_categories c ON t.category_id = c.category_id
        LEFT JOIN users u ON t.user_id = u.user_id
        WHERE t.transaction_id = :transaction_id
    ");
    $stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        throw new Exception("Transaction not found");
    }
    
    // Format amounts and dates
    $transaction['amount_formatted'] = number_format($transaction['amount'], 2);
    $transaction['transaction_date_formatted'] = date('F j, Y', strtotime($transaction['transaction_date']));
    $transaction['created_at_formatted'] = date('F j, Y H:i:s', strtotime($transaction['created_at']));
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Receipt - Bank Management System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .receipt-container {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .header p {
            color: #7f8c8d;
            margin-top: 0;
        }
        .bank-logo {
            max-height: 60px;
            margin-bottom: 15px;
        }
        .receipt-details {
            margin-bottom: 30px;
        }
        .receipt-details h2 {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            color: #2c3e50;
        }
        .detail-row {
            display: flex;
            margin-bottom: 10px;
        }
        .detail-label {
            font-weight: bold;
            width: 200px;
        }
        .detail-value {
            flex: 1;
        }
        .amount {
            font-size: 24px;
            font-weight: bold;
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .credit {
            color: #27ae60;
        }
        .debit {
            color: #e74c3c;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #7f8c8d;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .print-button {
            display: block;
            width: 100%;
            padding: 10px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 20px;
        }
        .print-button:hover {
            background-color: #2980b9;
        }
        @media print {
            .print-button {
                display: none;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="header">
            <img src="images/bank-logo.png" alt="Bank Logo" class="bank-logo">
            <h1>Transaction Receipt</h1>
            <p>Transaction #<?php echo htmlspecialchars($transaction['transaction_id']); ?></p>
        </div>
        
        <div class="receipt-details">
            <h2>Transaction Details</h2>
            
            <div class="detail-row">
                <div class="detail-label">Date:</div>
                <div class="detail-value"><?php echo htmlspecialchars($transaction['transaction_date_formatted']); ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Customer:</div>
                <div class="detail-value"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Transaction Type:</div>
                <div class="detail-value"><?php echo htmlspecialchars(ucfirst(strtolower($transaction['transaction_type']))); ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Category:</div>
                <div class="detail-value"><?php echo htmlspecialchars($transaction['category_name']); ?></div>
            </div>
            
            <?php if ($transaction['transaction_type'] === 'TRANSFER'): ?>
            <div class="detail-row">
                <div class="detail-label">From Account:</div>
                <div class="detail-value">
                    <?php echo htmlspecialchars($transaction['from_account_name']); ?> 
                    (****<?php echo substr($transaction['from_account_number'], -4); ?>)
                </div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">To Account:</div>
                <div class="detail-value">
                    <?php echo htmlspecialchars($transaction['to_account_name']); ?> 
                    (****<?php echo substr($transaction['to_account_number'], -4); ?>)
                </div>
            </div>
            <?php else: ?>
            <div class="detail-row">
                <div class="detail-label">Account:</div>
                <div class="detail-value">
                    <?php echo htmlspecialchars($transaction['from_account_name']); ?> 
                    (****<?php echo substr($transaction['from_account_number'], -4); ?>)
                </div>
            </div>
            <?php endif; ?>
            
            <div class="detail-row">
                <div class="detail-label">Reference Number:</div>
                <div class="detail-value"><?php echo htmlspecialchars($transaction['reference_number'] ?? 'N/A'); ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Description:</div>
                <div class="detail-value"><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Processed At:</div>
                <div class="detail-value"><?php echo htmlspecialchars($transaction['created_at_formatted']); ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Status:</div>
                <div class="detail-value"><?php echo htmlspecialchars(ucfirst($transaction['approval_status'])); ?></div>
            </div>
            
            <div class="amount <?php echo ($transaction['transaction_type'] === 'INCOME') ? 'credit' : 'debit'; ?>">
                <?php echo ($transaction['transaction_type'] === 'INCOME') ? '+' : '-'; ?>
                $<?php echo htmlspecialchars($transaction['amount_formatted']); ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Thank you for banking with us. This is an official receipt of your transaction.</p>
            <p>For any questions, please contact customer support.</p>
            <p>Bank Management System © <?php echo date('Y'); ?></p>
        </div>
        
        <button class="print-button" onclick="window.print()">Print Receipt</button>
    </div>
</body>
</html>