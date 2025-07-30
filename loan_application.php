<?php
session_start();
require_once 'db_connect.php';

// Check if employee is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Check if application ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: loan_department.php");
    exit();
}

$application_id = intval($_GET['id']);
$employee_id = $_SESSION['employee_id'];
$role = $_SESSION['role'];
$bank_id = $_SESSION['bank_id'];

// Get loan application details
$query = "SELECT la.*, c.first_name, c.last_name, c.email, c.phone, 
          IFNULL(lp.product_name, 'Unknown Product') as product_name, 
          IFNULL(lp.interest_rate, 0) as product_interest_rate,
          e.employees_first_name as created_by_first, 
          e.employees_last_name as created_by_last,
          a.employees_first_name as approved_by_first, 
          a.employees_last_name as approved_by_last,
          r.employees_first_name as rejected_by_first, 
          r.employees_last_name as rejected_by_last
          FROM loan_applications la
          JOIN customers c ON la.customer_id = c.customer_id
          LEFT JOIN loan_products lp ON la.product_id = lp.product_id
          LEFT JOIN employee e ON la.created_by = e.employee_id
          LEFT JOIN employee a ON la.approved_by = a.employee_id
          LEFT JOIN employee r ON la.rejected_by = r.employee_id
          WHERE la.application_id = ? AND c.bank_id = ?";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Error preparing query: " . $conn->error);
}

$stmt->bind_param("ii", $application_id, $bank_id);
if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}

$result = $stmt->get_result();
$application = $result->fetch_assoc();
$stmt->close();

if (!$application) {
    $_SESSION['error'] = "Loan application not found or you don't have permission to view it.";
    header("Location: loan_department.php");
    exit();
}

// Get customer's existing loans
$customer_loans = $conn->query("SELECT COUNT(*) as active_loans FROM loan_accounts 
                               WHERE customer_id = {$application['customer_id']} AND status = 'active'")->fetch_assoc();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (($role == 'manager' || $role == 'admin') && isset($_POST['approve_application'])) {
        $approved_amount = $_POST['approved_amount'];
        $approved_term = $_POST['approved_term'];
        $interest_rate = $_POST['interest_rate'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update application status - REMOVED interest_rate column (doesn't exist in loan_applications table)
            $stmt = $conn->prepare("UPDATE loan_applications 
                                   SET status = 'approved', 
                                   approved_amount = ?, 
                                   approved_term = ?, 
                                   approved_by = ?, 
                                   approved_date = CURDATE() 
                                   WHERE application_id = ?");
            
            // Check if prepare was successful
            if (!$stmt) {
                throw new Exception("Error preparing update query: " . $conn->error);
            }
            
            $stmt->bind_param("diii", $approved_amount, $approved_term, $employee_id, $application_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error executing update query: " . $stmt->error);
            }
            
            // Create loan account only if approval was successful
            $product_query = $conn->prepare("SELECT * FROM loan_products WHERE product_id = ?");
            if (!$product_query) {
                throw new Exception("Error preparing product query: " . $conn->error);
            }
            
            $product_query->bind_param("i", $application['product_id']);
            if (!$product_query->execute()) {
                throw new Exception("Error executing product query: " . $product_query->error);
            }
            
            $product = $product_query->get_result()->fetch_assoc();
            $product_query->close();
            
            if (!$product) {
                throw new Exception("Loan product not found");
            }
            
            // Calculate payment schedule
            $monthly_rate = $interest_rate / 100 / 12;
            $payment_amount = $approved_amount * ($monthly_rate * pow(1 + $monthly_rate, $approved_term)) / (pow(1 + $monthly_rate, $approved_term) - 1);
            $total_interest = ($payment_amount * $approved_term) - $approved_amount;
            
            // Create account in accounts table
            $account_name = "Loan - " . $product['product_name'];
            $stmt = $conn->prepare("INSERT INTO accounts 
                                  (user_id, employee_id, account_type_id, account_name, balance, created_at) 
                                  VALUES (?, ?, 4, ?, ?, NOW())");
            
            if (!$stmt) {
                throw new Exception("Error preparing accounts insert: " . $conn->error);
            }
            
            $stmt->bind_param("iisd", $application['customer_id'], $employee_id, $account_name, $approved_amount);
            
            if (!$stmt->execute()) {
                throw new Exception("Error creating account: " . $stmt->error);
            }
            
            $account_id = $conn->insert_id;
            
            // Create loan account
            $stmt = $conn->prepare("INSERT INTO loan_accounts 
                                  (application_id, account_id, customer_id, product_id, principal_amount, interest_rate, term_months, 
                                  start_date, maturity_date, payment_frequency, payment_amount, total_interest, total_payable, balance, status, next_payment_date, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?, ?, ?, 'active', 
                                  DATE_ADD(CURDATE(), INTERVAL 1 MONTH), ?)");
            
            if (!$stmt) {
                throw new Exception("Error preparing loan_accounts insert: " . $conn->error);
            }
            
            // Count: 14 parameters total
            // 1=application_id, 2=account_id, 3=customer_id, 4=product_id, 5=principal_amount, 6=interest_rate, 7=term_months, 
            // 8=term_months(for maturity calc), 9=payment_frequency, 10=payment_amount, 11=total_interest, 12=total_payable, 13=balance, 14=created_by
            $total_payable = $approved_amount + $total_interest;
            $stmt->bind_param("iiiiddiidddddi", 
                             $application_id,           // i - 1
                             $account_id,               // i - 2  
                             $application['customer_id'], // i - 3
                             $application['product_id'], // i - 4
                             $approved_amount,          // d - 5
                             $interest_rate,            // d - 6 (changed from i to d for decimal)
                             $approved_term,            // i - 7
                             $approved_term,            // i - 8 (for maturity date calculation)
                             $product['payment_frequency'], // d - 9
                             $payment_amount,           // d - 10
                             $total_interest,           // d - 11
                             $total_payable,            // d - 12
                             $total_payable,            // d - 13 (balance = total payable initially)
                             $employee_id);             // i - 14
            
            if (!$stmt->execute()) {
                throw new Exception("Error creating loan account: " . $stmt->error);
            }
            
            $loan_id = $conn->insert_id;
            
            // Debug: Check if we got a valid loan_id and show more details
            if ($loan_id == 0) {
                // Workaround: If loan_id is not AUTO_INCREMENT, get the last inserted loan_id manually
                $last_loan = $conn->query("SELECT MAX(loan_id) as max_id FROM loan_accounts WHERE application_id = $application_id");
                if ($last_loan && $row = $last_loan->fetch_assoc()) {
                    $loan_id = $row['max_id'];
                }
                
                if ($loan_id == 0) {
                    // Check if loan_accounts table exists
                    $table_check = $conn->query("SHOW TABLES LIKE 'loan_accounts'");
                    if ($table_check->num_rows == 0) {
                        throw new Exception("loan_accounts table does not exist!");
                    }
                    
                    // Check table structure
                    $structure = $conn->query("DESCRIBE loan_accounts");
                    $columns = [];
                    while ($row = $structure->fetch_assoc()) {
                        $columns[] = $row['Field'] . ' (' . $row['Type'] . ')' . ($row['Extra'] ? ' ' . $row['Extra'] : '');
                    }
                    
                    throw new Exception("Failed to create loan account - no loan_id generated. loan_accounts table structure: " . implode(', ', $columns) . ". Affected rows: " . $stmt->affected_rows . ". Fix: loan_id column needs AUTO_INCREMENT. Run: ALTER TABLE loan_accounts MODIFY loan_id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY;");
                }
            }
            
            // Create payment schedule
     // Create payment schedule
$balance = $approved_amount;
for ($i = 1; $i <= $approved_term; $i++) {
    $interest = $balance * $monthly_rate;
    $principal = $payment_amount - $interest;
    $balance -= $principal;
    
    $stmt = $conn->prepare("INSERT INTO loan_payment_schedule 
                          (loan_id, installment_number, due_date, principal, interest, total_due) 
                          VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Error preparing payment schedule insert: " . $conn->error);
    }
    
    $stmt->bind_param("iiiddd", $loan_id, $i, $i, $principal, $interest, $payment_amount);
    
    if (!$stmt->execute()) {
        throw new Exception("Error creating payment schedule: " . $stmt->error);
    }
}
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['message'] = "Loan #$loan_id approved and disbursed successfully!";
            header("Location: loan_department.php");
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $_SESSION['error'] = "Error approving loan: " . $e->getMessage();
            header("Location: loan_application.php?id=$application_id");
            exit();
        }
    }
    
    if (($role == 'manager' || $role == 'admin') && isset($_POST['reject_application'])) {
        $rejection_reason = $_POST['rejection_reason'] ?? 'Not specified';
        
        $stmt = $conn->prepare("UPDATE loan_applications 
                               SET status = 'rejected', 
                               rejected_by = ?, 
                               rejected_date = CURDATE(), 
                               rejection_reason = ?
                               WHERE application_id = ?");
        
        // Add error checking for rejection as well
        if (!$stmt) {
            $_SESSION['error'] = "Error preparing rejection query: " . $conn->error;
            header("Location: loan_application.php?id=$application_id");
            exit();
        }
        
        $stmt->bind_param("isi", $employee_id, $rejection_reason, $application_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Loan application #$application_id has been rejected.";
            header("Location: loan_department.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to reject application: " . $stmt->error;
            header("Location: loan_application.php?id=$application_id");
            exit();
        }
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Application #<?= $application_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .application-details {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .status-badge {
            font-size: 0.9rem;
            padding: 0.35em 0.65em;
        }
        .badge-pending {
            background-color: #6c757d;
        }
        .badge-approved {
            background-color: #28a745;
        }
        .badge-rejected {
            background-color: #dc3545;
        }
        .document-preview {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .timeline-item {
            position: relative;
            padding-left: 30px;
            margin-bottom: 20px;
        }
        .timeline-item:before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            width: 2px;
            height: 100%;
            background-color: #dee2e6;
        }
        .timeline-badge {
            position: absolute;
            left: 0;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            text-align: center;
            line-height: 20px;
            font-size: 12px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Loan Application #<?= $application_id ?></h1>
            <a href="loan_department.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Loan Department
            </a>
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
            <div class="col-md-8">
                <div class="application-details">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h4">Application Details</h3>
                        <span class="badge status-badge badge-<?= strtolower($application['status']) ?>">
                            <?= ucfirst($application['status']) ?>
                        </span>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>Customer:</strong> <?= $application['first_name'] ?> <?= $application['last_name'] ?></p>
                            <p><strong>Email:</strong> <?= $application['email'] ?></p>
                            <p><strong>Phone:</strong> <?= $application['phone'] ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Loan Product:</strong> <?= $application['product_name'] ?></p>
                            <p><strong>Applied Amount:</strong> $<?= number_format($application['applied_amount'], 2) ?></p>
                            <p><strong>Requested Term:</strong> <?= $application['requested_term'] ?> months</p>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <p><strong>Purpose:</strong></p>
                        <p><?= nl2br(htmlspecialchars($application['purpose'])) ?></p>
                    </div>
                </div>
                
                <!-- Decision Details -->
                <?php if ($application['status'] == 'approved' || $application['status'] == 'rejected'): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Decision Details</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($application['status'] == 'approved'): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Approved Amount:</strong> $<?= number_format($application['approved_amount'], 2) ?></p>
                                <p><strong>Approved Term:</strong> <?= $application['approved_term'] ?> months</p>
                                <p><strong>Interest Rate:</strong> <?= $application['interest_rate'] ?>%</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Approved By:</strong> 
                                    <?= $application['approved_by_first'] ? $application['approved_by_first'] . ' ' . $application['approved_by_last'] : 'System' ?>
                                </p>
                                <p><strong>Approval Date:</strong> <?= date('M d, Y', strtotime($application['approved_date'])) ?></p>
                            </div>
                        </div>
                        <?php elseif ($application['status'] == 'rejected'): ?>
                        <div class="row">
                            <div class="col-md-12">
                                <p><strong>Rejection Reason:</strong></p>
                                <p><?= nl2br(htmlspecialchars($application['rejection_reason'])) ?></p>
                                <p><strong>Rejected By:</strong> 
                                    <?= $application['rejected_by_first'] ? $application['rejected_by_first'] . ' ' . $application['rejected_by_last'] : 'System' ?>
                                </p>
                                <p><strong>Rejection Date:</strong> <?= date('M d, Y', strtotime($application['rejected_date'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Customer Loan History -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Customer Loan History</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Active Loans:</strong> <?= $customer_loans['active_loans'] ?></p>
                        <?php
                        $loan_history = $conn->query("SELECT la.loan_id, la.principal_amount, la.balance, 
                                                     la.start_date, la.status, lp.product_name
                                                     FROM loan_accounts la
                                                     JOIN loan_products lp ON la.product_id = lp.product_id
                                                     WHERE la.customer_id = {$application['customer_id']}
                                                     ORDER BY la.start_date DESC");
                        if ($loan_history->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Loan ID</th>
                                            <th>Product</th>
                                            <th>Principal</th>
                                            <th>Balance</th>
                                            <th>Status</th>
                                            <th>Start Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($loan = $loan_history->fetch_assoc()): ?>
                                        <tr>
                                            <td><a href="loan_details.php?id=<?= $loan['loan_id'] ?>">#<?= $loan['loan_id'] ?></a></td>
                                            <td><?= $loan['product_name'] ?></td>
                                            <td>$<?= number_format($loan['principal_amount'], 2) ?></td>
                                            <td>$<?= number_format($loan['balance'], 2) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $loan['status'] == 'active' ? 'success' : ($loan['status'] == 'defaulted' ? 'warning' : 'secondary') ?>">
                                                    <?= ucfirst($loan['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($loan['start_date'])) ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No previous loan history found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Attached Documents -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Attached Documents</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $documents = $conn->query("SELECT * FROM loan_documents WHERE application_id = $application_id");
                        if ($documents->num_rows > 0): ?>
                            <div class="row">
                                <?php while ($doc = $documents->fetch_assoc()): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="document-preview">
                                        <h6><?= htmlspecialchars($doc['document_type']) ?></h6>
                                        <p class="small text-muted">Uploaded: <?= date('M d, Y', strtotime($doc['uploaded_at'])) ?></p>
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" download class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No documents attached to this application.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Approval/Rejection Form -->
                <?php if (($role == 'manager' || $role == 'admin') && $application['status'] == 'pending'): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Loan Decision</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label for="approved_amount" class="form-label">Approved Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="approved_amount" name="approved_amount" 
                                           value="<?= $application['applied_amount'] ?>" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="approved_term" class="form-label">Approved Term (months)</label>
                                <input type="number" class="form-control" id="approved_term" name="approved_term" 
                                       value="<?= $application['requested_term'] ?>" min="1" required>
                            </div>
                            <div class="mb-3">
                                <label for="interest_rate" class="form-label">Interest Rate (%)</label>
                                <input type="number" class="form-control" id="interest_rate" name="interest_rate" 
                                       value="<?= $application['product_interest_rate'] ?>" step="0.01" min="0" max="100" required>
                            </div>
                            <div class="mb-3">
                                <label for="rejection_reason" class="form-label">Rejection Reason (if rejecting)</label>
                                <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3"></textarea>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="reject_application" class="btn btn-danger">
                                    <i class="bi bi-x-circle"></i> Reject Application
                                </button>
                                <button type="submit" name="approve_application" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Approve Loan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Application Timeline -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Application Timeline</h5>
                    </div>
                    <div class="card-body">
                        <div class="timeline-item">
                            <span class="timeline-badge bg-primary">
                                <i class="bi bi-file-earmark-text"></i>
                            </span>
                            <div>
                                <h6 class="mb-1">Application Submitted</h6>
                                <p class="small text-muted mb-0"><?= date('M d, Y H:i', strtotime($application['application_date'])) ?></p>
                                <p class="small mb-0">By: <?= $application['created_by_first'] ? $application['created_by_first'] . ' ' . $application['created_by_last'] : 'System' ?></p>
                            </div>
                        </div>
                        
                        <?php if ($application['status'] == 'approved'): ?>
                        <div class="timeline-item">
                            <span class="timeline-badge bg-success">
                                <i class="bi bi-check-circle"></i>
                            </span>
                            <div>
                                <h6 class="mb-1">Application Approved</h6>
                                <p class="small text-muted mb-0"><?= date('M d, Y H:i', strtotime($application['approved_date'])) ?></p>
                                <p class="small mb-0">By: <?= $application['approved_by_first'] ? $application['approved_by_first'] . ' ' . $application['approved_by_last'] : 'System' ?></p>
                                <p class="small mb-0">Amount: $<?= number_format($application['approved_amount'], 2) ?></p>
                                <p class="small mb-0">Term: <?= $application['approved_term'] ?> months</p>
                            </div>
                        </div>
                        <?php elseif ($application['status'] == 'rejected'): ?>
                        <div class="timeline-item">
                            <span class="timeline-badge bg-danger">
                                <i class="bi bi-x-circle"></i>
                            </span>
                            <div>
                                <h6 class="mb-1">Application Rejected</h6>
                                <p class="small text-muted mb-0"><?= date('M d, Y H:i', strtotime($application['rejected_date'])) ?></p>
                                <p class="small mb-0">By: <?= $application['rejected_by_first'] ? $application['rejected_by_first'] . ' ' . $application['rejected_by_last'] : 'System' ?></p>
                                <p class="small mb-0">Reason: <?= $application['rejection_reason'] ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add confirmation for reject/approve actions
        document.querySelector('button[name="reject_application"]')?.addEventListener('click', function(e) {
            const reason = document.getElementById('rejection_reason').value;
            if (!reason || reason.trim() === '') {
                e.preventDefault();
                alert('Please provide a rejection reason before submitting.');
            } else if (!confirm('Are you sure you want to reject this loan application?')) {
                e.preventDefault();
            }
        });
        
        document.querySelector('button[name="approve_application"]')?.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to approve this loan application?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>