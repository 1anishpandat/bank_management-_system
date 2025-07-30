<?php
session_start();
require_once 'db_connect.php';

// Check if employee is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header("Location: employee_login.php");
    exit();
}

$employee_id = $_SESSION['employee_id'];
$role = $_SESSION['role'];
$bank_id = $_SESSION['bank_id'];

// Function to get loan applications
function getLoanApplications($status = null, $bank_id) {
    global $conn;
    
    $query = "SELECT la.*, c.first_name, c.last_name, lp.product_name 
              FROM loan_applications la
              JOIN customers c ON la.customer_id = c.customer_id
              JOIN loan_products lp ON la.product_id = lp.product_id
              WHERE c.bank_id = ?";
    
    if ($status) {
        $query .= " AND la.status = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $bank_id, $status);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $bank_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to get loan accounts
function getLoanAccounts($status = null, $bank_id) {
    global $conn;
    
    $query = "SELECT la.*, c.first_name, c.last_name, lp.product_name, c.email, c.phone
              FROM loan_accounts la
              JOIN customers c ON la.customer_id = c.customer_id
              JOIN loan_products lp ON la.product_id = lp.product_id
              WHERE c.bank_id = ?";
    
    if ($status) {
        $query .= " AND la.status = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $bank_id, $status);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $bank_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to get loan products
function getLoanProducts($bank_id) {
    global $conn;
    
    $query = "SELECT * FROM loan_products WHERE is_active = 1";
    $result = $conn->query($query);
    
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    return [];
}

// Function to get customers
function getCustomers($bank_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM customers WHERE bank_id = ? AND status = 'active'");
    $stmt->bind_param("i", $bank_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Create new loan application
    if (isset($_POST['create_application'])) {
        $customer_id = (int)$_POST['customer_id'];
        $product_id = (int)$_POST['product_id'];
        $applied_amount = (float)$_POST['applied_amount'];
        $requested_term = (int)$_POST['requested_term'];
        $purpose = $conn->real_escape_string($_POST['purpose']);
        
        // Validate product exists
        $product_check = $conn->query("SELECT 1 FROM loan_products WHERE product_id = $product_id");
        if ($product_check->num_rows === 0) {
            $_SESSION['error'] = "Invalid loan product selected";
            header("Location: loan_department");
            exit();
        }
        
        // Validate customer exists
        $customer_check = $conn->query("SELECT 1 FROM customers WHERE customer_id = $customer_id AND bank_id = $bank_id");
        if ($customer_check->num_rows === 0) {
            $_SESSION['error'] = "Invalid customer selected";
            header("Location: loan_department");
            exit();
        }
       // To explicitly exclude application_id:
$stmt = $conn->prepare("INSERT INTO loan_applications 
(customer_id, product_id, applied_amount, requested_term, purpose, status, application_date, created_by) 
VALUES (?, ?, ?, ?, ?, 'pending', CURDATE(), ?)");
        $stmt->bind_param("iidisi", $customer_id, $product_id, $applied_amount, $requested_term, $purpose, $employee_id);
        
        if ($stmt->execute()) {
            $application_id = $conn->insert_id;
            $_SESSION['message'] = "Loan application #$application_id created successfully!";
        } else {
            $_SESSION['error'] = "Failed to create loan application: " . $stmt->error;
        }
    
        header("Location: loan_department");
        exit();
    }
    
    // Approve loan application
    if (isset($_POST['approve_application'])) {
        $application_id = (int)$_POST['application_id'];
        $approved_amount = (float)$_POST['approved_amount'];
        $approved_term = (int)$_POST['approved_term'];
        $interest_rate = (float)$_POST['interest_rate'];
        
        // Get application details
        $app = $conn->query("SELECT * FROM loan_applications WHERE application_id = $application_id")->fetch_assoc();
        if (!$app) {
            $_SESSION['error'] = "Invalid loan application";
            header("Location: loan_department");
            exit();
        }
        
        // Update application status
        $stmt = $conn->prepare("UPDATE loan_applications 
                               SET status = 'approved', approved_amount = ?, approved_term = ?, approved_by = ?, approved_date = CURDATE() 
                               WHERE application_id = ?");
        $stmt->bind_param("diii", $approved_amount, $approved_term, $employee_id, $application_id);
        $stmt->execute();
        
        // Create loan account
        $product = $conn->query("SELECT * FROM loan_products WHERE product_id = {$app['product_id']}")->fetch_assoc();
        
        // Calculate payment schedule
        $monthly_rate = $interest_rate / 100 / 12;
        $payment_amount = $approved_amount * ($monthly_rate * pow(1 + $monthly_rate, $approved_term)) / (pow(1 + $monthly_rate, $approved_term) - 1);
        $total_interest = ($payment_amount * $approved_term) - $approved_amount;
        
        // Create account in accounts table
        $account_name = "Loan - " . $product['product_name'];
        $conn->query("INSERT INTO accounts (user_id, employee_id, account_type_id, account_name, balance, created_at) 
                     VALUES ({$app['customer_id']}, $employee_id, 4, '$account_name', $approved_amount, NOW())");
        $account_id = $conn->insert_id;
        
   // Create loan account
$status = 'active'; // First assign to a variable
$stmt = $conn->prepare("INSERT INTO loan_accounts 
                      (application_id, account_id, customer_id, product_id, principal_amount, interest_rate, term_months, 
                      start_date, maturity_date, payment_frequency, payment_amount, total_interest, total_payable, balance, status, next_payment_date, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?, ?, ?, ?, 
                      DATE_ADD(CURDATE(), INTERVAL 1 MONTH), ?)");

// Bind parameters - note the status variable
$stmt->bind_param(
    "iiiiidididdddssi", // Changed to "ss" for the two string parameters
    $application_id, 
    $account_id, 
    $app['customer_id'], 
    $app['product_id'], 
    $approved_amount,
    $interest_rate, 
    $approved_term, 
    $approved_term, 
    $product['payment_frequency'], 
    $payment_amount,
    $total_interest, 
    $approved_amount + $total_interest, 
    $approved_amount + $total_interest,
    $status, // Now using variable instead of literal
    $employee_id
);

$stmt->execute();
$loan_id = $conn->insert_id;
        
        // Create payment schedule
        $balance = $approved_amount;
        for ($i = 1; $i <= $approved_term; $i++) {
            $interest = $balance * $monthly_rate;
            $principal = $payment_amount - $interest;
            $balance -= $principal;
            
            $stmt = $conn->prepare("INSERT INTO loan_payment_schedule 
                                  (loan_id, installment_number, due_date, principal, interest, total_due) 
                                  VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?)");
            $stmt->bind_param("iiidd", $loan_id, $i, $i, $principal, $interest, $payment_amount);
            $stmt->execute();
        }
        
        $_SESSION['message'] = "Loan #$loan_id approved and disbursed successfully!";
        header("Location: loan_department");
        exit();
    }
    
    // Reject loan application
    if (isset($_POST['reject_application'])) {
        $application_id = (int)$_POST['application_id'];
        $rejection_reason = $conn->real_escape_string($_POST['rejection_reason'] ?? 'Not specified');
        
        $stmt = $conn->prepare("UPDATE loan_applications 
                               SET status = 'rejected', rejected_by = ?, rejected_date = CURDATE(), 
                               rejection_reason = ?
                               WHERE application_id = ?");
        $stmt->bind_param("isi", $employee_id, $rejection_reason, $application_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Loan application #$application_id has been rejected.";
        } else {
            $_SESSION['error'] = "Failed to reject application: " . $stmt->error;
        }
        
        header("Location: loan_department");
        exit();
    }
    
    // Process loan payment
    if (isset($_POST['process_payment'])) {
        $loan_id = (int)$_POST['loan_id'];
        $amount = (float)$_POST['amount'];
        $payment_method = $conn->real_escape_string($_POST['payment_method']);
        $reference_number = $conn->real_escape_string($_POST['reference_number'] ?? '');
        
        // Get loan details
        $loan = $conn->query("SELECT * FROM loan_accounts WHERE loan_id = $loan_id")->fetch_assoc();
        if (!$loan) {
            $_SESSION['error'] = "Invalid loan account";
            header("Location: loan_department");
            exit();
        }
        
        // Calculate payment allocation
        $interest = min($amount, $loan['balance'] * ($loan['interest_rate'] / 100 / 12));
        $principal = $amount - $interest;
        $new_balance = $loan['balance'] - $principal;
        
        // Record payment
        $stmt = $conn->prepare("INSERT INTO loan_payments 
                              (loan_id, payment_date, amount, principal, interest, remaining_balance, payment_method, reference_number, received_by) 
                              VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iddddssi", $loan_id, $amount, $principal, $interest, $new_balance, $payment_method, $reference_number, $employee_id);
        $stmt->execute();
        $payment_id = $conn->insert_id;
        
        // Update loan account
        $conn->query("UPDATE loan_accounts SET balance = $new_balance, last_payment_date = CURDATE(), 
                     next_payment_date = DATE_ADD(CURDATE(), INTERVAL 1 MONTH) WHERE loan_id = $loan_id");
        
        // Update payment schedule
        $conn->query("UPDATE loan_payment_schedule SET status = 'paid', paid_amount = $amount, paid_date = CURDATE() 
                     WHERE loan_id = $loan_id AND status = 'pending' ORDER BY due_date LIMIT 1");
        
        // Create transaction record
        $conn->query("INSERT INTO transactions (user_id, customer_id, account_id, category_id, transaction_type, amount, description, transaction_date, employee_id) 
                     VALUES ({$loan['customer_id']}, {$loan['customer_id']}, {$loan['account_id']}, 4, 'EXPENSE', $amount, 'Loan Payment', CURDATE(), $employee_id)");
        
        $_SESSION['message'] = "Payment #$payment_id processed successfully!";
        header("Location: loan_department");
        exit();
    }
    
    // Add new loan product
    if (isset($_POST['add_loan_product'])) {
        $product_name = $conn->real_escape_string($_POST['product_name']);
        $description = $conn->real_escape_string($_POST['description']);
        $min_amount = (float)$_POST['min_amount'];
        $max_amount = (float)$_POST['max_amount'];
        $interest_rate = (float)$_POST['interest_rate'];
        $term_min = (int)$_POST['term_min'];
        $term_max = (int)$_POST['term_max'];
        $payment_frequency = $conn->real_escape_string($_POST['payment_frequency']);
        
        $loan_type_id = 1; // Default value
        
        $stmt = $conn->prepare("INSERT INTO loan_products 
                               (product_name, description, loan_type_id, min_amount, max_amount, 
                               interest_rate, term_min, term_max, payment_frequency, created_by) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssidddddii", 
            $product_name, $description, $loan_type_id, $min_amount, $max_amount, 
            $interest_rate, $term_min, $term_max, $payment_frequency, $employee_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Loan product '$product_name' added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add loan product: " . $stmt->error;
        }
        
        header("Location: loan_department");
        exit();
    }
}

// Get data for display
$pending_applications = getLoanApplications('pending', $bank_id);
$approved_applications = getLoanApplications('approved', $bank_id);
$rejected_applications = getLoanApplications('rejected', $bank_id);
$active_loans = getLoanAccounts('active', $bank_id);
$delinquent_loans = getLoanAccounts('defaulted', $bank_id);
$loan_products = getLoanProducts($bank_id);
$customers = getCustomers($bank_id);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Management System - Loan Department</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    
    
    <style>
        
    .nav-link {
            color: #495057;
        }
        .nav-link.active {
            color: #0d6efd;
            font-weight: bold;
        }
        .card {
            margin-bottom: 20px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
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
                    <h1 class="h2">Loan Department</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#newApplicationModal">
                                <i class="bi bi-plus-circle"></i> New Application
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#processPaymentModal">
                                <i class="bi bi-cash"></i> Process Payment
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addLoanProductModal">
                                <i class="bi bi-file-earmark-plus"></i> Add Product
                            </button>
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

                <div class="row">
                    <!-- Quick Stats -->
                    <div class="col-md-3">
                        <div class="card text-white bg-primary mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Pending Applications</h5>
                                <p class="card-text display-6"><?= count($pending_applications) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Active Loans</h5>
                                <p class="card-text display-6"><?= count($active_loans) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Delinquent Loans</h5>
                                <p class="card-text display-6"><?= count($delinquent_loans) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Rejected Applications</h5>
                                <p class="card-text display-6"><?= count($rejected_applications) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Applications -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Pending Loan Applications</h5>
                        <span class="badge bg-primary rounded-pill"><?= count($pending_applications) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_applications)): ?>
                            <div class="alert alert-info">No pending loan applications found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Customer</th>
                                            <th>Product</th>
                                            <th>Amount</th>
                                            <th>Term</th>
                                            <th>Applied Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_applications as $app): ?>
                                        <tr>
                                            <td>#<?= $app['application_id'] ?></td>
                                            <td><?= $app['first_name'] ?> <?= $app['last_name'] ?></td>
                                            <td><?= $app['product_name'] ?></td>
                                            <td>$<?= number_format($app['applied_amount'], 2) ?></td>
                                            <td><?= $app['requested_term'] ?> months</td>
                                            <td><?= date('M d, Y', strtotime($app['application_date'])) ?></td>
                                            <td>
                                                <?php if ($role == 'manager' || $role == 'admin'): ?>
                                               
                                                <?php endif; ?>
                                                <a href="loan_application?id=<?= $app['application_id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Active Loans -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Active Loans</h5>
                        <span class="badge bg-success rounded-pill"><?= count($active_loans) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($active_loans)): ?>
                            <div class="alert alert-info">No active loans found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Loan ID</th>
                                            <th>Customer</th>
                                            <th>Product</th>
                                            <th>Principal</th>
                                            <th>Balance</th>
                                            <th>Next Payment</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($active_loans as $loan): ?>
                                        <tr>
                                            <td>#<?= $loan['loan_id'] ?></td>
                                            <td><?= $loan['first_name'] ?> <?= $loan['last_name'] ?></td>
                                            <td><?= $loan['product_name'] ?></td>
                                            <td>$<?= number_format($loan['principal_amount'], 2) ?></td>
                                            <td>$<?= number_format($loan['balance'], 2) ?></td>
                                            <td><?= date('M d, Y', strtotime($loan['next_payment_date'])) ?></td>
                                            <td>
                                                <span class="badge bg-success status-badge">Active</span>
                                            </td>
                                            <td>
                                                <a href="loan_details.php?id=<?= $loan['loan_id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-eye"></i> Details
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Rejected Applications -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Rejected Loan Applications</h5>
                        <span class="badge bg-danger rounded-pill"><?= count($rejected_applications) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rejected_applications)): ?>
                            <div class="alert alert-info">No rejected loan applications found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Customer</th>
                                            <th>Product</th>
                                            <th>Amount</th>
                                            <th>Term</th>
                                            <th>Rejected Date</th>
                                            <th>Reason</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rejected_applications as $app): ?>
                                        <tr>
                                            <td>#<?= $app['application_id'] ?></td>
                                            <td><?= $app['first_name'] ?> <?= $app['last_name'] ?></td>
                                            <td><?= $app['product_name'] ?></td>
                                            <td>$<?= number_format($app['applied_amount'], 2) ?></td>
                                            <td><?= $app['requested_term'] ?> months</td>
                                            <td><?= date('M d, Y', strtotime($app['rejected_date'])) ?></td>
                                            <td><?= $app['rejection_reason'] ?></td>
                                            <td>
                                                <a href="loan_application.php?id=<?= $app['application_id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Delinquent Loans -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Delinquent Loans</h5>
                        <span class="badge bg-warning rounded-pill"><?= count($delinquent_loans) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($delinquent_loans)): ?>
                            <div class="alert alert-info">No delinquent loans found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Loan ID</th>
                                            <th>Customer</th>
                                            <th>Product</th>
                                            <th>Balance</th>
                                            <th>Days Delinquent</th>
                                            <th>Last Payment</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($delinquent_loans as $loan): ?>
                                        <tr>
                                            <td>#<?= $loan['loan_id'] ?></td>
                                            <td><?= $loan['first_name'] ?> <?= $loan['last_name'] ?></td>
                                            <td><?= $loan['product_name'] ?></td>
                                            <td>$<?= number_format($loan['balance'], 2) ?></td>
                                            <td><?= $loan['days_delinquent'] ?> days</td>
                                            <td>
                                                <?= $loan['last_payment_date'] ? date('M d, Y', strtotime($loan['last_payment_date'])) : 'Never' ?>
                                            </td>
                                            <td>
                                                <a href="loan_details.php?id=<?= $loan['loan_id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-eye"></i> Details
                                                </a>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#contactModal<?= $loan['loan_id'] ?>">
                                                    <i class="bi bi-telephone"></i> Contact
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Application Modal -->
    <div class="modal fade" id="newApplicationModal" tabindex="-1" aria-labelledby="newApplicationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newApplicationModalLabel">New Loan Application</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" id="customer_id" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                <option value="<?= $customer['customer_id'] ?>">
                                    <?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="product_id" class="form-label">Loan Product</label>
                            <select class="form-select" id="product_id" name="product_id" required>
                                <option value="">Select Loan Product</option>
                                <?php if (!empty($loan_products)): ?>
                                    <?php foreach ($loan_products as $product): ?>
                                    <option value="<?= $product['product_id'] ?>" 
                                            data-min="<?= $product['min_amount'] ?>" 
                                            data-max="<?= $product['max_amount'] ?>" 
                                            data-term-min="<?= $product['term_min'] ?>" 
                                            data-term-max="<?= $product['term_max'] ?>">
                                        <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['interest_rate']) ?>% APR)
                                    </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No loan products available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="applied_amount" class="form-label">Amount Requested</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="applied_amount" name="applied_amount" step="0.01" min="0" required>
                            </div>
                            <small class="text-muted" id="amountRange"></small>
                        </div>
                        <div class="mb-3">
                            <label for="requested_term" class="form-label">Term (months)</label>
                            <input type="number" class="form-control" id="requested_term" name="requested_term" min="1" required>
                            <small class="text-muted" id="termRange"></small>
                        </div>
                        <div class="mb-3">
                            <label for="purpose" class="form-label">Purpose</label>
                            <textarea class="form-control" id="purpose" name="purpose" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_application" class="btn btn-primary">Submit Application</button>
                    </div>
                </form>
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
                            <label for="loan_id_payment" class="form-label">Loan Account</label>
                            <select class="form-select" id="loan_id_payment" name="loan_id" required>
                                <option value="">Select Loan Account</option>
                                <?php if (!empty($active_loans)): ?>
                                    <?php foreach ($active_loans as $loan): ?>
                                    <option value="<?= $loan['loan_id'] ?>">
                                        #<?= $loan['loan_id'] ?> - <?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?> ($<?= number_format($loan['balance'], 2) ?> balance)
                                    </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No active loans found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="amount" class="form-label">Payment Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0.01" required>
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

    <!-- Add Loan Product Modal -->
    <div class="modal fade" id="addLoanProductModal" tabindex="-1" aria-labelledby="addLoanProductModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLoanProductModalLabel">Add New Loan Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="product_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="product_name" name="product_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="min_amount" class="form-label">Minimum Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="min_amount" name="min_amount" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="max_amount" class="form-label">Maximum Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="max_amount" name="max_amount" step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="interest_rate" class="form-label">Interest Rate (%)</label>
                            <input type="number" class="form-control" id="interest_rate" name="interest_rate" step="0.01" min="0" max="100" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="term_min" class="form-label">Minimum Term (months)</label>
                                <input type="number" class="form-control" id="term_min" name="term_min" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="term_max" class="form-label">Maximum Term (months)</label>
                                <input type="number" class="form-control" id="term_max" name="term_max" min="1" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="payment_frequency" class="form-label">Payment Frequency</label>
                            <select class="form-select" id="payment_frequency" name="payment_frequency" required>
                                <option value="monthly">Monthly</option>
                                <option value="weekly">Weekly</option>
                                <option value="bi-weekly">Bi-Weekly</option>
                                <option value="quarterly">Quarterly</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_loan_product" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approve/Reject Application Modals -->
    <?php foreach ($pending_applications as $app): ?>
    <div class="modal fade" id="approveModal<?= $app['application_id'] ?>" tabindex="-1" aria-labelledby="approveModalLabel<?= $app['application_id'] ?>" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approveModalLabel<?= $app['application_id'] ?>">Review Loan Application #<?= $app['application_id'] ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Customer</label>
                            <p class="form-control-static"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Product</label>
                            <p class="form-control-static"><?= htmlspecialchars($app['product_name']) ?></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Requested Amount</label>
                            <p class="form-control-static">$<?= number_format(htmlspecialchars($app['applied_amount']), 2) ?></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Requested Term</label>
                            <p class="form-control-static"><?= htmlspecialchars($app['requested_term']) ?> months</p>
                        </div>
                        <div class="mb-3">
                            <label for="approved_amount" class="form-label">Approved Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="approved_amount" name="approved_amount" value="<?= htmlspecialchars($app['applied_amount']) ?>" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="approved_term" class="form-label">Approved Term (months)</label>
                            <input type="number" class="form-control" id="approved_term" name="approved_term" value="<?= htmlspecialchars($app['requested_term']) ?>" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="interest_rate" class="form-label">Interest Rate (%)</label>
                            <input type="number" class="form-control" id="interest_rate" name="interest_rate" step="0.01" min="0" max="100" required>
                        </div>
                        <div class="mb-3">
                            <label for="rejection_reason_<?= $app['application_id'] ?>" class="form-label">Rejection Reason (if rejecting)</label>
                            <textarea class="form-control" id="rejection_reason_<?= $app['application_id'] ?>" name="rejection_reason" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="reject_application" class="btn btn-danger">Reject Application</button>
                        <button type="submit" name="approve_application" class="btn btn-success">Approve Loan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Contact Customer Modals -->
    <?php foreach ($delinquent_loans as $loan): ?>
    <div class="modal fade" id="contactModal<?= $loan['loan_id'] ?>" tabindex="-1" aria-labelledby="contactModalLabel<?= $loan['loan_id'] ?>" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel<?= $loan['loan_id'] ?>">Contact Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <p class="form-control-static"><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <p class="form-control-static"><?= htmlspecialchars($loan['email']) ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <p class="form-control-static"><?= htmlspecialchars($loan['phone']) ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Loan Details</label>
                        <p class="form-control-static">
                            #<?= htmlspecialchars($loan['loan_id']) ?> - <?= htmlspecialchars($loan['product_name']) ?><br>
                            Balance: $<?= number_format(htmlspecialchars($loan['balance']), 2) ?><br>
                            Days Delinquent: <?= htmlspecialchars($loan['days_delinquent']) ?>
                        </p>
                    </div>
                    <div class="mb-3">
                        <label for="contact_notes" class="form-label">Contact Notes</label>
                        <textarea class="form-control" id="contact_notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary">Save Contact Record</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update amount and term ranges when product is selected in New Application Modal
        document.getElementById('product_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value === "") return;
            
            const minAmount = selectedOption.getAttribute('data-min');
            const maxAmount = selectedOption.getAttribute('data-max');
            const minTerm = selectedOption.getAttribute('data-term-min');
            const maxTerm = selectedOption.getAttribute('data-term-max');
            
            document.getElementById('applied_amount').min = minAmount;
            document.getElementById('applied_amount').max = maxAmount;
            document.getElementById('applied_amount').value = minAmount; // Set initial value to min
            
            document.getElementById('requested_term').min = minTerm;
            document.getElementById('requested_term').max = maxTerm;
            document.getElementById('requested_term').value = minTerm; // Set initial value to min
            
            document.getElementById('amountRange').textContent = `Range: $${minAmount} - $${maxAmount}`;
            document.getElementById('termRange').textContent = `Range: ${minTerm} - ${maxTerm} months`;
        });
        
        // Auto-fill interest rate when approving application
        <?php foreach ($pending_applications as $app): ?>
        document.getElementById('approveModal<?= $app['application_id'] ?>').addEventListener('shown.bs.modal', function() {
            // Find the product ID dynamically from the modal's context if needed,
            // or ensure 'product_id' is directly available in the $app array for simplicity.
            // Assuming $app['product_id'] is available as it's used in PHP.
            const productId = <?= htmlspecialchars($app['product_id']) ?>;
            // Get the interest rate from the data attributes of the loan products in the modal's product dropdown
            // This is a workaround since the original `get_product_details.php` is not provided.
            const productSelect = document.querySelector('#newApplicationModal #product_id'); // Using the ID from the new application modal
            let interestRate = 0;
            if (productSelect) {
                for (let i = 0; i < productSelect.options.length; i++) {
                    if (productSelect.options[i].value == productId) {
                        interestRate = productSelect.options[i].getAttribute('data-interest-rate');
                        break;
                    }
                }
            }
            // Set the interest rate in the approval modal's input field
            const interestRateInput = this.querySelector('#interest_rate');
            if (interestRateInput) {
                interestRateInput.value = interestRate;
            }
        });
        <?php endforeach; ?>
    </script>
</body>
</html>
