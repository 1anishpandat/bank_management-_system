<?php
// loan_details.php
session_start();
require_once 'db_connect.php';
// This prevents MySQL from allowing 0 as an auto-increment value
$conn->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
// Check if employee is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header("Location: employee_login.php");
    exit();
}

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($loan_id <= 0) {
    header("Location: loan_department.php");
    exit();
}

// Get loan details
$loan = [];
$stmt = $conn->prepare("SELECT la.*, c.first_name, c.last_name, c.email, c.phone, 
                       lp.product_name, lp.interest_rate as product_interest_rate,
                       a.account_name, a.account_number, a.balance as account_balance
                       FROM loan_accounts la
                       JOIN customers c ON la.customer_id = c.customer_id
                       JOIN loan_products lp ON la.product_id = lp.product_id
                       JOIN accounts a ON la.account_id = a.account_id
                       WHERE la.loan_id = ? AND c.bank_id = ?");
$stmt->bind_param("ii", $loan_id, $_SESSION['bank_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: loan_department.php");
    exit();
}

$loan = $result->fetch_assoc();
$stmt->close();

// Get payment schedule
$schedule = [];
$stmt = $conn->prepare("SELECT * FROM loan_payment_schedule 
                       WHERE loan_id = ? ORDER BY due_date");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
$schedule = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get payment history
$payments = [];
$stmt = $conn->prepare("SELECT * FROM loan_payments 
                       WHERE loan_id = ? ORDER BY payment_date DESC");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
$payments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate loan stats
$total_paid = 0;
$principal_paid = 0;
$interest_paid = 0;

// Get all payments
$stmt = $conn->prepare("SELECT SUM(amount) as total_paid, 
                       SUM(principal) as principal_paid, 
                       SUM(interest) as interest_paid
                       FROM loan_payments WHERE loan_id = ?");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($result) {
    $total_paid = (float)$result['total_paid'];
    $principal_paid = (float)$result['principal_paid'];
    $interest_paid = (float)$result['interest_paid'];
}

$remaining_balance = $loan['principal_amount'] - $principal_paid;
$completion_percentage = $loan['principal_amount'] > 0 ? 
    ($principal_paid / $loan['principal_amount']) * 100 : 0;

// Get next payment
$next_payment = null;
$stmt = $conn->prepare("SELECT * FROM loan_payment_schedule 
                       WHERE loan_id = ? AND status IN ('pending', 'partial')
                       ORDER BY due_date LIMIT 1");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $next_payment = $result->fetch_assoc();
}
$stmt->close();

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_payment'])) {
    $amount = (float)$_POST['amount'];
    $payment_method = $conn->real_escape_string($_POST['payment_method']);
    $reference_number = $conn->real_escape_string($_POST['reference_number'] ?? '');
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get all pending installments ordered by due date
        $stmt = $conn->prepare("SELECT * FROM loan_payment_schedule 
                              WHERE loan_id = ? AND status IN ('pending', 'partial') 
                              ORDER BY due_date");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $loan_id);
        $stmt->execute();
        $pending_installments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($pending_installments)) {
            throw new Exception("No pending installments found");
        }
        
        $remaining_payment = $amount;
        $total_principal_paid = 0;
        $total_interest_paid = 0;
        $paid_installments = [];
        
        // Process payment against installments
        foreach ($pending_installments as $installment) {
            if ($remaining_payment <= 0) break;
            
            $installment_id = $installment['schedule_id'];
            $remaining_due = $installment['total_due'] - ($installment['paid_amount'] ?? 0);
            $paid_amount = min($remaining_payment, $remaining_due);
            
            // Calculate how to allocate payment between principal and interest
            $remaining_principal = $installment['principal'] - ($installment['principal'] ?? 0);
            $remaining_interest = $installment['interest'] - ($installment['interest'] ?? 0);
            
            $principal = min($paid_amount, $remaining_principal);
            $interest = $paid_amount - $principal;
            
            // Update installment
            $stmt = $conn->prepare("UPDATE loan_payment_schedule 
                                  SET paid_amount = COALESCE(paid_amount, 0) + ?, 
                                      principal = COALESCE(principal, 0) + ?,
                                      interest = COALESCE(interest, 0) + ?,
                                      status = IF(COALESCE(paid_amount, 0) + ? >= total_due, 'paid', 'partial'),
                                      paid_date = IF(COALESCE(paid_amount, 0) + ? >= total_due, CURDATE(), paid_date)
                                  WHERE schedule_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("dddddi", $paid_amount, $principal, $interest, $paid_amount, $paid_amount, $installment_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $stmt->close();
            
            // Record payment allocation
            $total_principal_paid += $principal;
            $total_interest_paid += $interest;
            $remaining_payment -= $paid_amount;
            
            $paid_installments[] = $installment_id;
        }
        
        // Calculate new loan balance
        $new_balance = $loan['balance'] - $total_principal_paid;
        
        // Record payment
        $stmt = $conn->prepare("INSERT INTO loan_payments 
                              (loan_id, payment_date, amount, principal, interest, remaining_balance, 
                               payment_method, reference_number, received_by) 
                              VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iddddssi", $loan_id, $amount, $total_principal_paid, $total_interest_paid, 
                         $new_balance, $payment_method, $reference_number, $_SESSION['employee_id']);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $payment_id = $conn->insert_id;
        $stmt->close();
        
        // Update loan account
        $stmt = $conn->prepare("UPDATE loan_accounts 
                              SET balance = ?, 
                                  last_payment_date = CURDATE(),
                                  next_payment_date = (
                                      SELECT MIN(due_date) 
                                      FROM loan_payment_schedule 
                                      WHERE loan_id = ? AND status IN ('pending', 'partial')
                                  ),
                                  days_delinquent = 0
                              WHERE loan_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ddi", $new_balance, $loan_id, $loan_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        
        // Update account balance if payment is from account
        if ($payment_method === 'bank_transfer' || $payment_method === 'online') {
            $conn->query("UPDATE accounts SET balance = balance - $amount WHERE account_id = {$loan['account_id']}");
        }
        
        // Create transaction record
        $conn->query("INSERT INTO transactions (user_id, customer_id, account_id, category_id, transaction_type, 
                     amount, description, transaction_date, employee_id) 
                     VALUES ({$loan['customer_id']}, {$loan['customer_id']}, {$loan['account_id']}, 4, 'EXPENSE', 
                     $amount, 'Loan Payment #$payment_id', CURDATE(), {$_SESSION['employee_id']})");
        
        // Check if loan is fully paid (using a small epsilon for floating point comparison)
        if ($new_balance < 0.01) {  // Consider paid if balance is less than 1 cent
            if (!$conn->query("UPDATE loan_accounts SET status = 'closed', balance = 0 WHERE loan_id = $loan_id")) {
                throw new Exception("Failed to close loan account: " . $conn->error);
            }
            
            if (!$conn->query("UPDATE loan_payment_schedule SET status = 'paid' WHERE loan_id = $loan_id AND status != 'paid'")) {
                throw new Exception("Failed to update payment schedule: " . $conn->error);
            }
            
            // Log the loan closure
            $conn->query("INSERT INTO activities (employee_id, bank_id, activity_type, action) 
                         VALUES ({$_SESSION['employee_id']}, {$_SESSION['bank_id']}, 'loan', 
                         'Closed loan ID $loan_id with final payment of $amount')");
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['message'] = "Payment #$payment_id processed successfully!";
        header("Location: loan_details.php?id=$loan_id");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Payment failed: " . $e->getMessage();
        header("Location: loan_details.php?id=$loan_id");
        exit();
    }
}

// Handle loan status change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_status'])) {
    $new_status = $conn->real_escape_string($_POST['new_status']);
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    
    $allowed_statuses = ['active', 'closed', 'defaulted', 'written_off'];
    if (!in_array($new_status, $allowed_statuses)) {
        $_SESSION['error'] = "Invalid status selected";
        header("Location: loan_details.php?id=$loan_id");
        exit();
    }
    
    // Validate status transition
    $current_status = $loan['status'];
    $valid_transitions = [
        'active' => ['closed', 'defaulted', 'written_off'],
        'defaulted' => ['closed', 'written_off'],
        'written_off' => ['closed'],
        'closed' => []
    ];
    
    if (!in_array($new_status, $valid_transitions[$current_status])) {
        $_SESSION['error'] = "Invalid status transition from $current_status to $new_status";
        header("Location: loan_details.php?id=$loan_id");
        exit();
    }
    
    // Special handling for closed status
    if ($new_status === 'closed') {
        // Check if loan is actually paid off
        $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM loan_payment_schedule 
                              WHERE loan_id = ? AND status IN ('pending', 'partial')");
        if ($stmt === false) {
            $_SESSION['error'] = "Database error: " . $conn->error;
            header("Location: loan_details.php?id=$loan_id");
            exit();
        }
        
        $stmt->bind_param("i", $loan_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result['pending'] > 0 && $loan['balance'] > 0) {
            $_SESSION['error'] = "Cannot close loan with pending payments and balance";
            header("Location: loan_details.php?id=$loan_id");
            exit();
        }
    }
    
    // Prepare the update statement with error checking
    $stmt = $conn->prepare("UPDATE loan_accounts SET status = ?, notes = ? WHERE loan_id = ?");
    if ($stmt === false) {
        $_SESSION['error'] = "Database error: " . $conn->error;
        header("Location: loan_details.php?id=$loan_id");
        exit();
    }
    
    $stmt->bind_param("ssi", $new_status, $notes, $loan_id);
    
    if ($stmt->execute()) {
        // If marking as defaulted, calculate days delinquent
        if ($new_status === 'defaulted') {
            $next_payment_date = $conn->query("SELECT MIN(due_date) as next_due FROM loan_payment_schedule 
                                             WHERE loan_id = $loan_id AND status IN ('pending', 'partial')")
                                     ->fetch_assoc()['next_due'];
            if ($next_payment_date) {
                $days_delinquent = max(0, (time() - strtotime($next_payment_date))) / (60 * 60 * 24);
                $conn->query("UPDATE loan_accounts SET days_delinquent = $days_delinquent 
                             WHERE loan_id = $loan_id");
            }
        }
        
        $_SESSION['message'] = "Loan status updated to " . ucfirst($new_status);
    } else {
        $_SESSION['error'] = "Failed to update loan status: " . $stmt->error;
    }
    
    $stmt->close();
    header("Location: loan_details.php?id=$loan_id");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - <?= $loan_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .loan-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem;
            margin-bottom: 2rem;
        }
        .loan-summary-card {
            border-left: 4px solid #0d6efd;
        }
        .payment-card {
            border-left: 4px solid #28a745;
        }
        .schedule-card {
            border-left: 4px solid #ffc107;
        }
        .progress {
            height: 1.5rem;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        .badge-active {
            background-color: #28a745;
        }
        .badge-defaulted {
            background-color: #dc3545;
        }
        .badge-closed {
            background-color: #6c757d;
        }
        .badge-written-off {
            background-color: #343a40;
        }
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Loan Details - #<?= $loan_id ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#processPaymentModal">
                                <i class="bi bi-cash"></i> Process Payment
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#changeStatusModal">
                                <i class="bi bi-pencil"></i> Change Status
                            </button>
                            <a href="loan_department.php" class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-arrow-left"></i> Back to Loans
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message']); endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $_SESSION['error'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- Loan Header -->
                <div class="loan-header mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <h3><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></h3>
                            <p class="mb-1"><i class="bi bi-envelope"></i> <?= htmlspecialchars($loan['email']) ?></p>
                            <p class="mb-1"><i class="bi bi-telephone"></i> <?= htmlspecialchars($loan['phone']) ?></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h3>
                                <span class="badge <?= 'badge-' . $loan['status'] ?> status-badge">
                                    <?= ucfirst($loan['status']) ?>
                                </span>
                            </h3>
                            <p class="mb-1">Account: <?= htmlspecialchars($loan['account_name']) ?> (<?= htmlspecialchars($loan['account_number']) ?>)</p>
                            <p class="mb-1">Product: <?= htmlspecialchars($loan['product_name']) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Loan Summary -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card loan-summary-card mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Loan Summary</h5>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Principal Amount</small></p>
                                        <h5>$<?= number_format($loan['principal_amount'], 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Interest Rate</small></p>
                                        <h5><?= number_format($loan['interest_rate'], 2) ?>%</h5>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Term</small></p>
                                        <h5><?= $loan['term_months'] ?> months</h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Payment Frequency</small></p>
                                        <h5><?= ucfirst($loan['payment_frequency']) ?></h5>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Start Date</small></p>
                                        <h5><?= date('M d, Y', strtotime($loan['start_date'])) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Maturity Date</small></p>
                                        <h5><?= date('M d, Y', strtotime($loan['maturity_date'])) ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card payment-card mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Payment Summary</h5>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Total Payable</small></p>
                                        <h5>$<?= number_format($loan['total_payable'], 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Monthly Payment</small></p>
                                        <h5>$<?= number_format($loan['payment_amount'], 2) ?></h5>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Principal Paid</small></p>
                                        <h5>$<?= number_format($principal_paid, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Interest Paid</small></p>
                                        <h5>$<?= number_format($interest_paid, 2) ?></h5>
                                    </div>
                                </div>
                                <div class="progress mt-3">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?= $completion_percentage ?>%" 
                                         aria-valuenow="<?= $completion_percentage ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                        <?= round($completion_percentage, 1) ?>%
                                    </div>
                                </div>
                                <p class="text-center mt-1 mb-0">
                                    <small><?= round($completion_percentage, 1) ?>% Paid ($<?= number_format($principal_paid, 2) ?> of $<?= number_format($loan['principal_amount'], 2) ?>)</small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Status -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Current Status</h5>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Remaining Balance</small></p>
                                        <h5>$<?= number_format($remaining_balance, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Last Payment</small></p>
                                        <h5>
                                            <?= $loan['last_payment_date'] ? 
                                                date('M d, Y', strtotime($loan['last_payment_date'])) : 
                                                'No payments yet' ?>
                                        </h5>
                                    </div>
                                </div>
                                <?php if ($next_payment): ?>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Next Payment Due</small></p>
                                        <h5><?= date('M d, Y', strtotime($next_payment['due_date'])) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Amount Due</small></p>
                                        <h5>$<?= number_format($next_payment['total_due'] - ($next_payment['paid_amount'] ?? 0), 2) ?></h5>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($loan['days_delinquent'] > 0): ?>
                                <div class="alert alert-warning mt-2 mb-0">
                                    <i class="bi bi-exclamation-triangle"></i> 
                                    This loan is <?= $loan['days_delinquent'] ?> days delinquent
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Account Information</h5>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Account Name</small></p>
                                        <h5><?= htmlspecialchars($loan['account_name']) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Account Number</small></p>
                                        <h5><?= htmlspecialchars($loan['account_number']) ?></h5>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Current Balance</small></p>
                                        <h5>$<?= number_format($loan['account_balance'], 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="mb-1"><small class="text-muted">Payment Method</small></p>
                                        <h5><?= ucfirst($loan['payment_frequency']) ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Payment History</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($payments)): ?>
                            <div class="alert alert-info">No payment history found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Principal</th>
                                            <th>Interest</th>
                                            <th>Remaining Balance</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                            <td>$<?= number_format($payment['amount'], 2) ?></td>
                                            <td>$<?= number_format($payment['principal'], 2) ?></td>
                                            <td>$<?= number_format($payment['interest'], 2) ?></td>
                                            <td>$<?= number_format($payment['remaining_balance'], 2) ?></td>
                                            <td><?= ucfirst($payment['payment_method']) ?></td>
                                            <td><?= htmlspecialchars($payment['reference_number']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Schedule -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Schedule</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Due Date</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Total Due</th>
                                        <th>Paid</th>
                                        <th>Status</th>
                                        <th>Paid Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedule as $installment): ?>
                                    <tr class="<?= $installment['status'] === 'pending' ? 'table-light' : 
                                               ($installment['status'] === 'paid' ? 'table-success' : 
                                               ($installment['status'] === 'overdue' ? 'table-danger' : '')) ?>">
                                        <td><?= $installment['installment_number'] ?></td>
                                        <td><?= date('M d, Y', strtotime($installment['due_date'])) ?></td>
                                        <td>$<?= number_format($installment['principal'], 2) ?></td>
                                        <td>$<?= number_format($installment['interest'], 2) ?></td>
                                        <td>$<?= number_format($installment['total_due'], 2) ?></td>
                                        <td>$<?= number_format($installment['paid_amount'] ?? 0, 2) ?></td>
                                        <td>
                                            <span class="badge <?= $installment['status'] === 'paid' ? 'bg-success' : 
                                                              ($installment['status'] === 'pending' ? 'bg-warning text-dark' : 
                                                              ($installment['status'] === 'overdue' ? 'bg-danger' : 'bg-secondary')) ?>">
                                                <?= ucfirst($installment['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $installment['paid_date'] ? date('M d, Y', strtotime($installment['paid_date'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Payment Modal -->
    <div class="modal fade" id="processPaymentModal" tabindex="-1" aria-labelledby="processPaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="processPaymentModalLabel">Process Loan Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Loan Account</label>
                            <p class="form-control-static">#<?= $loan_id ?> - <?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Remaining Balance</label>
                            <p class="form-control-static">$<?= number_format($remaining_balance, 2) ?></p>
                        </div>
                        <?php if ($next_payment): ?>
                        <div class="mb-3">
                            <label class="form-label">Next Payment Due</label>
                            <p class="form-control-static">$<?= number_format($next_payment['total_due'] - ($next_payment['paid_amount'] ?? 0), 2) ?> on <?= date('M d, Y', strtotime($next_payment['due_date'])) ?></p>
                        </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="amount" class="form-label">Payment Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="amount" name="amount" 
                                       value="<?= $next_payment ? number_format($next_payment['total_due'] - ($next_payment['paid_amount'] ?? 0), 2) : '' ?>" 
                                       step="0.01" min="0.01" max="<?= $remaining_balance ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">Select Method</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="online">Online Payment</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="reference_number" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="process_payment" class="btn btn-primary">Process Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Status Modal -->
    <div class="modal fade" id="changeStatusModal" tabindex="-1" aria-labelledby="changeStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changeStatusModalLabel">Change Loan Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Status</label>
                            <p class="form-control-static">
                                <span class="badge <?= 'badge-' . $loan['status'] ?> status-badge">
                                    <?= ucfirst($loan['status']) ?>
                                </span>
                            </p>
                        </div>
                        <div class="mb-3">
                            <label for="new_status" class="form-label">New Status</label>
                            <select class="form-select" id="new_status" name="new_status" required>
                                <option value="active" <?= $loan['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="closed" <?= $loan['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                                <option value="defaulted" <?= $loan['status'] === 'defaulted' ? 'selected' : '' ?>>Defaulted</option>
                                <option value="written_off" <?= $loan['status'] === 'written_off' ? 'selected' : '' ?>>Written Off</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="change_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-set payment amount to next payment due amount if available
        document.getElementById('processPaymentModal').addEventListener('shown.bs.modal', function() {
            const nextPaymentAmount = <?= $next_payment ? ($next_payment['total_due'] - ($next_payment['paid_amount'] ?? 0)) : 0 ?>;
            if (nextPaymentAmount > 0) {
                document.getElementById('amount').value = nextPaymentAmount.toFixed(2);
            }
        });
    </script>
</body>
</html>