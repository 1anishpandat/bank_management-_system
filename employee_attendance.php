<?php
require_once 'db_connect.php';

// Start session and check authentication
session_start();
if (!isset($_SESSION['employee_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Get employee and bank details (These variables are from the original code and might not be directly used in this specific file's display logic if the primary purpose is attendance/salary management for all employees. Kept for context.)
$employee_id = $_SESSION['employee_id'];
$stmt = $conn->prepare("SELECT * FROM employee WHERE employee_id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

$bank_id = $employee['bank_id'];
$stmt = $conn->prepare("SELECT * FROM bank_details WHERE bank_id = ?");
$stmt->bind_param("i", $bank_id);
$stmt->execute();
$bank = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Initialize systems
$attendance = new EmployeeAttendance($conn);

// Message variable to display feedback to the user
$message = '';
$employee_details_result = null;
$salary_details_for_display = null;
$attendance_summary_for_display = null;
$employee_attendance_records = [];
$view_month = date('n');
$view_year = date('Y');

class EmployeeAttendance {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    // Mark employee attendance
    public function markAttendance($employee_id, $date, $check_in, $check_out, $status, $notes = '') {
        try {
            // Check if attendance already exists for this employee on this date
            $query = "SELECT attendance_id FROM employee_attendance 
                      WHERE employee_id = ? AND date = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("is", $employee_id, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing record
                $query = "UPDATE employee_attendance 
                          SET check_in = ?, check_out = ?, status = ?, notes = ?, updated_at = NOW()
                          WHERE employee_id = ? AND date = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("ssssis", $check_in, $check_out, $status, $notes, $employee_id, $date);
            } else {
                // Insert new record
                $query = "INSERT INTO employee_attendance 
                          (employee_id, date, check_in, check_out, status, notes, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("isssss", $employee_id, $date, $check_in, $check_out, $status, $notes);
            }
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Attendance marking error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get attendance for an employee in a date range
    public function getAttendance($employee_id, $start_date, $end_date) {
        try {
            $query = "SELECT * FROM employee_attendance 
                      WHERE employee_id = ? AND date BETWEEN ? AND ?
                      ORDER BY date DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iss", $employee_id, $start_date, $end_date);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Attendance retrieval error: " . $e->getMessage());
            return [];
        }
    }
    
    // Get attendance summary for payroll calculation
    public function getAttendanceSummary($employee_id, $month, $year) {
        try {
            $first_day = date("$year-$month-01");
            $last_day = date("Y-m-t", strtotime($first_day));
            
            $query = "SELECT 
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                        SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
                        SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
                        SUM(CASE WHEN status = 'holiday' THEN 1 ELSE 0 END) as holiday_days,
                        COUNT(attendance_id) as total_entries
                      FROM employee_attendance
                      WHERE employee_id = ? AND date BETWEEN ? AND ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iss", $employee_id, $first_day, $last_day);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            // Ensure all keys are present even if SUM returns NULL for some categories
            return [
                'present_days' => (int)($result['present_days'] ?? 0),
                'absent_days' => (int)($result['absent_days'] ?? 0),
                'half_days' => (int)($result['half_days'] ?? 0),
                'leave_days' => (int)($result['leave_days'] ?? 0),
                'holiday_days' => (int)($result['holiday_days'] ?? 0),
                'total_entries' => (int)($result['total_entries'] ?? 0)
            ];
        } catch (Exception $e) {
            error_log("Attendance summary error: " . $e->getMessage());
            return [
                'present_days' => 0,
                'absent_days' => 0,
                'half_days' => 0,
                'leave_days' => 0,
                'holiday_days' => 0,
                'total_entries' => 0
            ];
        }
    }

    // New method to get employee salary details
    public function getEmployeeSalaryDetails($employee_id) {
        try {
            $query = "SELECT * FROM employee_salary WHERE employee_id = ? AND is_current = 1 ORDER BY effective_from DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        } catch (Exception $e) {
            error_log("Error fetching salary details: " . $e->getMessage());
            return null;
        }
    }

    // Add or Update Salary Structure for an employee
    public function addOrUpdateSalaryStructure($employee_id, $basic_salary, $hra, $da, $allowances, $deductions, $tax) {
        try {
            // Start a transaction for atomicity
            $this->conn->begin_transaction();

            // First, set all existing salary structures for this employee to not current
            $update_prev_query = "UPDATE employee_salary SET is_current = 0, updated_at = NOW() WHERE employee_id = ?";
            $stmt_prev = $this->conn->prepare($update_prev_query);
            $stmt_prev->bind_param("i", $employee_id);
            $stmt_prev->execute();

            // Insert the new salary structure as current
            $insert_query = "INSERT INTO employee_salary 
                             (employee_id, basic_salary, hra, da, allowances, deductions, tax, net_salary, effective_from, is_current, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, CURDATE(), 1, NOW(), NOW())";
            $stmt_insert = $this->conn->prepare($insert_query);
            $stmt_insert->bind_param("idddddd", $employee_id, $basic_salary, $hra, $da, $allowances, $deductions, $tax);
            $success = $stmt_insert->execute();

            if ($success) {
                $this->conn->commit();
                return ['success' => true, 'message' => "Salary structure added/updated successfully."];
            } else {
                $this->conn->rollback();
                return ['success' => false, 'message' => "Failed to add/update salary structure."];
            }

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Salary structure error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error adding/updating salary structure: " . $e->getMessage()];
        }
    }

    // Generate salary based on attendance
    public function generateSalary($employee_id, $month, $year) {
        try {
            // 1. Get salary details for the employee
            $salary_details = $this->getEmployeeSalaryDetails($employee_id);
            if (!$salary_details) {
                return ['success' => false, 'message' => "Salary details not found for employee ID: $employee_id"];
            }

            $basic_salary = $salary_details['basic_salary'];
            $hra = $salary_details['hra'];
            $da = $salary_details['da'];
            $allowances = $salary_details['allowances'];
            $deductions = $salary_details['deductions'];
            $tax = $salary_details['tax'];
            $salary_id = $salary_details['salary_id'];

            // 2. Get attendance summary for the month
            $attendance_summary = $this->getAttendanceSummary($employee_id, $month, $year);
            
            $present_days = $attendance_summary['present_days'];
            $half_days = $attendance_summary['half_days'];
            $leave_days = $attendance_summary['leave_days'];
            $holiday_days = $attendance_summary['holiday_days']; // Assuming holidays are paid

            // Calculate total payable days
            // A half-day typically counts as 0.5, and leave/holidays might be paid depending on policy.
            // For simplicity, let's assume half_day is 0.5, and leave/holiday days are fully paid.
            $payable_days = $present_days + ($half_days * 0.5) + $leave_days + $holiday_days;

            // Get total days in the month to calculate daily rate
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

            // Calculate daily rate
            // Avoid division by zero if days_in_month is 0 (shouldn't happen for valid month/year)
            $daily_basic_salary = ($days_in_month > 0) ? $basic_salary / $days_in_month : 0;
            $daily_hra = ($days_in_month > 0) ? $hra / $days_in_month : 0;
            $daily_da = ($days_in_month > 0) ? $da / $days_in_month : 0;
            $daily_allowances = ($days_in_month > 0) ? $allowances / $days_in_month : 0;

            // Calculate gross salary for payable days
            $gross_salary = ($payable_days * $daily_basic_salary) +
                            ($payable_days * $daily_hra) +
                            ($payable_days * $daily_da) +
                            ($payable_days * $daily_allowances);

            // Calculate net salary after deductions and tax
            $calculated_net_salary = $gross_salary - $deductions - $tax;
            if($calculated_net_salary < 0){
              $calculated_net_salary = 0.00; // Salary can't be negative.
            }
            // 3. Record salary payment
            $payment_date = date('Y-m-d'); // Today's date as payment date

            // Check if salary already paid for this month and year
            $check_query = "SELECT payment_id FROM salary_payments WHERE employee_id = ? AND month = ? AND year = ?";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bind_param("iii", $employee_id, $month, $year);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                // Update existing salary payment record
                $update_query = "UPDATE salary_payments 
                                 SET days_present = ?, days_absent = ?, days_leave = ?, amount_paid = ?, payment_date = ?, status = 'paid', updated_at = NOW()
                                 WHERE employee_id = ? AND month = ? AND year = ?";
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bind_param("iiddsiii", 
                    $present_days, 
                    $attendance_summary['absent_days'], // Use absent_days from summary
                    $leave_days, 
                    $calculated_net_salary, 
                    $payment_date, 
                    $employee_id, 
                    $month, 
                    $year
                );
                $success = $update_stmt->execute();
                $message = $success ? "Salary updated successfully." : "Failed to update salary.";
            } else {
                // Insert new salary payment record
                $insert_query = "INSERT INTO salary_payments 
                                 (employee_id, salary_id, month, year, days_present, days_absent, days_leave, amount_paid, payment_date, status, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', NOW(), NOW())";
                $insert_stmt = $this->conn->prepare($insert_query);
                $insert_stmt->bind_param("iiiiiiids", 
                    $employee_id, 
                    $salary_id, 
                    $month, 
                    $year, 
                    $present_days, 
                    $attendance_summary['absent_days'], // Use absent_days from summary
                    $leave_days, 
                    $calculated_net_salary, 
                    $payment_date
                );
                $success = $insert_stmt->execute();
                $message = $success ? "Salary generated and recorded successfully." : "Failed to generate and record salary.";
            }

            return ['success' => $success, 'message' => $message, 'net_salary' => $calculated_net_salary];

        } catch (Exception $e) {
            error_log("Salary generation error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error generating salary: " . $e->getMessage()];
        }
    }

    // New method to get full employee details by ID
    public function getEmployeeDetails($employee_id) {
        try {
            $query = "SELECT employee_id, employees_first_name, employees_last_name, employees_job_title, employees_department FROM employee WHERE employee_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc();
        } catch (Exception $e) {
            error_log("Error fetching employee details: " . $e->getMessage());
            return null;
        }
    }
}

// Initialize attendance system
$attendance = new EmployeeAttendance($conn);

// Check if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'mark_attendance':
                $employee_id = (int)$_POST['employee_id'];
                $date = $_POST['date'];
                $check_in = $_POST['check_in'] ?? null;
                $check_out = $_POST['check_out'] ?? null;
                $status = $_POST['status'];
                $notes = $_POST['notes'] ?? '';
                
                $result = $attendance->markAttendance($employee_id, $date, $check_in, $check_out, $status, $notes);
                $message = $result ? "Attendance marked successfully" : "Failed to mark attendance";
                break;
                
            case 'generate_salary':
                $employee_id = (int)$_POST['salary_employee_id'];
                $month = (int)$_POST['salary_month'];
                $year = (int)$_POST['salary_year'];

                $result = $attendance->generateSalary($employee_id, $month, $year);
                $message = $result['message'];
                if ($result['success']) {
                    $message .= " Net Salary: $" . number_format($result['net_salary'], 2);
                }
                break;

            case 'add_salary_structure':
                $employee_id = (int)$_POST['structure_employee_id'];
                $basic_salary = (float)$_POST['basic_salary'];
                $hra = (float)$_POST['hra'];
                $da = (float)$_POST['da'];
                $allowances = (float)$_POST['allowances'];
                $deductions = (float)$_POST['deductions'];
                $tax = (float)$_POST['tax'];

                $result = $attendance->addOrUpdateSalaryStructure($employee_id, $basic_salary, $hra, $da, $allowances, $deductions, $tax);
                $message = $result['message'];
                break;

            case 'view_employee_data':
                $employee_id_to_view = (int)$_POST['view_employee_id'];
                $view_month = (int)$_POST['view_month'];
                $view_year = (int)$_POST['view_year'];

                $employee_details_result = $attendance->getEmployeeDetails($employee_id_to_view);
                if ($employee_details_result) {
                    $salary_details_for_display = $attendance->getEmployeeSalaryDetails($employee_id_to_view);
                    $attendance_summary_for_display = $attendance->getAttendanceSummary($employee_id_to_view, $view_month, $view_year);
                    
                    // Also fetch detailed attendance records for the selected month/year
                    $first_day_of_month = date("$view_year-$view_month-01");
                    $last_day_of_month = date("Y-m-t", strtotime($first_day_of_month));
                    $employee_attendance_records = $attendance->getAttendance($employee_id_to_view, $first_day_of_month, $last_day_of_month);

                    $message = "Displaying data for Employee ID: " . $employee_id_to_view;
                } else {
                    $message = "Employee ID: " . $employee_id_to_view . " not found.";
                }
                break;

            case 'download_attendance_report':
                $employee_id = (int)$_POST['report_employee_id'];
                $start_date = $_POST['report_start_date'];
                $end_date = $_POST['report_end_date'];

                $records = $attendance->getAttendance($employee_id, $start_date, $end_date);
                $employee_info = $attendance->getEmployeeDetails($employee_id);
                $employee_name = $employee_info ? $employee_info['employees_first_name'] . ' ' . $employee_info['employees_last_name'] : 'Unknown';

                // Set headers for CSV download
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="attendance_report_employee_' . $employee_id . '_' . $start_date . '_to_' . $end_date . '.csv"');

                // Open output stream
                $output = fopen('php://output', 'w');

                // Add CSV header row with employee name
                fputcsv($output, ['Attendance Report for: ' . $employee_name . ' (ID: ' . $employee_id . ')']);
                fputcsv($output, ['Date Range: ' . $start_date . ' to ' . $end_date]);
                fputcsv($output, []); // Empty row for spacing
                fputcsv($output, ['Attendance ID', 'Employee ID', 'Date', 'Check In', 'Check Out', 'Status', 'Notes', 'Created At', 'Updated At']);

                // Add data rows
                foreach ($records as $row) {
                    fputcsv($output, $row);
                }

                fclose($output);
                exit(); // Important to exit after sending the file

            case 'download_salary_report':
                $employee_id = (int)$_POST['salary_report_employee_id'];
                $month = (int)$_POST['salary_report_month'];
                $year = (int)$_POST['salary_report_year'];

                try {
                    $query = "SELECT sp.*, e.employees_first_name, e.employees_last_name, es.basic_salary, es.hra, es.da, es.allowances, es.deductions AS structure_deductions, es.tax AS structure_tax, es.effective_from
                              FROM salary_payments sp
                              JOIN employee e ON sp.employee_id = e.employee_id
                              JOIN employee_salary es ON sp.salary_id = es.salary_id
                              WHERE sp.employee_id = ? AND sp.month = ? AND sp.year = ?
                              ORDER BY sp.payment_date DESC";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("iii", $employee_id, $month, $year);
                    $stmt->execute();
                    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                } catch (Exception $e) {
                    error_log("Error fetching salary report data: " . $e->getMessage());
                    die("Error fetching salary report data.");
                }

                // Set headers for CSV download
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="salary_report_employee_' . $employee_id . '_' . $month . '_' . $year . '.csv"');

                // Open output stream
                $output = fopen('php://output', 'w');

                // Add CSV header row with employee name and period
                $employee_info = $attendance->getEmployeeDetails($employee_id);
                $employee_name = $employee_info ? $employee_info['employees_first_name'] . ' ' . $employee_info['employees_last_name'] : 'Unknown';
                fputcsv($output, ['Salary Report for: ' . $employee_name . ' (ID: ' . $employee_id . ')']);
                fputcsv($output, ['Month/Year: ' . date('F', mktime(0, 0, 0, $month, 10)) . ' ' . $year]);
                fputcsv($output, []); // Empty row for spacing

                // Add detailed CSV header row
                fputcsv($output, [
                    'Payment ID', 'Employee ID', 'Employee Name', 'Salary Structure ID', 'Effective From',
                    'Basic Salary (Structure)', 'HRA (Structure)', 'DA (Structure)', 
                    'Allowances (Structure)', 'Deductions (Structure)', 'Tax (Structure)',
                    'Month Paid', 'Year Paid', 'Days Present', 'Days Absent', 'Days Leave', 
                    'Calculated Net Amount Paid', 'Payment Date', 'Status', 'Created At', 'Updated At'
                ]);

                // Add data rows
                foreach ($records as $row) {
                    // Prepare data for CSV, including employee name and detailed salary structure
                    $rowData = [
                        $row['payment_id'],
                        $row['employee_id'],
                        $row['employees_first_name'] . ' ' . $row['employees_last_name'],
                        $row['salary_id'],
                        $row['effective_from'], // From employee_salary
                        $row['basic_salary'],   // From employee_salary
                        $row['hra'],            // From employee_salary
                        $row['da'],             // From employee_salary
                        $row['allowances'],     // From employee_salary
                        $row['structure_deductions'], // Renamed to avoid conflict with payment deductions if any
                        $row['structure_tax'],        // Renamed to avoid conflict with payment tax if any
                        $row['month'],
                        $row['year'],
                        $row['days_present'],
                        $row['days_absent'],
                        $row['days_leave'],
                        $row['amount_paid'],
                        $row['payment_date'],
                        $row['status'],
                        $row['created_at'],
                        $row['updated_at']
                    ];
                    fputcsv($output, $rowData);
                }

                fclose($output);
                exit(); // Important to exit after sending the file

            default:
                $message = "Invalid action";
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}
include 'header.php';

// Include sidebar
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Employee Attendance and Salary System</title>
    <style>
        /* General styling */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            color: #333;
        }

        /* Header section */
        header {
            background-color: #2c3e50;
            color: white;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        /* Logout button */
        .logout-btn {
            background-color: #e74c3c;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .logout-btn:hover {
            background-color: #c0392b;
        }

        /* Main content area */
        .main-content {
            max-width: 1000px;
            margin: 30px auto; /* Centers the div horizontally */
            padding: 0 20px;
        }

        /* Action buttons container */
        .actions-container {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 30px; /* Added margin for separation */
        }

        /* Individual action buttons */
        .action-btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 20px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            text-align: center;
            min-width: 200px;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .action-btn:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0,0,0,0.15);
        }

        /* Section Styling */
        .section {
            margin-bottom: 40px;
            padding: 30px;
            border: 1px solid #dcdcdc;
            border-radius: 10px;
            background-color: #fdfdfd;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            display: none; /* Hidden by default - Managed by JS active class */
        }
        .section.active {
            display: block; /* Shown when active */
        }
        .section:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .section h2 {
            color: #34495e; /* Slightly lighter dark blue */
            margin-top: 0;
            margin-bottom: 25px;
            font-size: 1.8em;
            border-bottom: 2px solid #5faee3; /* Lighter blue underline */
            padding-bottom: 10px;
            position: relative;
        }
        .section h2::after {
            content: '';
            display: block;
            width: 50px;
            height: 4px;
            background: #3498db;
            position: absolute;
            bottom: -2px;
            left: 0;
            border-radius: 2px;
        }

        /* Form Group Styling */
        .form-group {
            margin-bottom: 20px;
            display: flex; /* Use flexbox for label and input alignment */
            align-items: center; /* Vertically align items */
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }

        label {
            flex: 0 0 180px; /* Fixed width for labels */
            margin-right: 20px;
            margin-bottom: 5px; /* Spacing for wrap */
            font-weight: bold;
            color: #555;
            text-align: right; /* Align label text to the right */
        }

        input[type="number"],
        input[type="date"],
        input[type="time"],
        input[type="text"],
        select,
        textarea {
            flex: 1 1 250px; /* Allow inputs to grow but have a minimum width */
            padding: 12px 15px;
            border: 1px solid #c0c0c0;
            border-radius: 6px;
            font-size: 1em;
            box-sizing: border-box;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background-color: #f8f8f8;
        }

        input[type="number"]:focus,
        input[type="date"]:focus,
        input[type="time"]:focus,
        input[type="text"]:focus,
        select:focus,
        textarea:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
            background-color: #ffffff;
        }

        textarea {
            resize: vertical; /* Allow vertical resizing */
            min-height: 80px;
        }

        /* Button Container - Added for centering dropdown button and other buttons */
        .btn-container {
            display: flex; /* Use flex to allow spacing and alignment for multiple buttons */
            justify-content: center; /* Center items within the flex container */
            gap: 15px; /* Space between buttons */
            flex-wrap: wrap; /* Allow buttons to wrap to next line */
            margin-top: 20px;
            margin-bottom: 10px;
        }

        /* Dropdown Styling */
        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-button {
            padding: 12px 25px;
            background: #3498db; /* Bright blue */
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            white-space: nowrap; /* Prevent text wrapping */
        }

        .dropdown-button:hover {
            background: #2980b9; /* Darker blue on hover */
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }
        .dropdown-button:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 200px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 6px;
            overflow: hidden;
            top: 100%; /* Position below the button */
            left: 50%; /* Start from center */
            transform: translateX(-50%); /* Adjust to truly center dropdown content */
            margin-top: 5px; /* Small gap between button and dropdown */
        }

        .dropdown-content a {
            color: black;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            text-align: left;
            transition: background-color 0.2s;
        }

        .dropdown-content a:hover {
            background-color: #e2f0ff;
            color: #3498db;
        }

        /* Keep dropdown open on button hover/focus */
        .dropdown:hover .dropdown-content,
        .dropdown-button:focus + .dropdown-content {
            display: block;
        }

        /* Message Box Styling */
        .message {
            padding: 15px 20px;
            margin: 25px 0;
            border-radius: 8px;
            font-weight: bold;
            font-size: 1.05em;
            display: flex;
            align-items: center;
            border-left: 6px solid;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .message.success {
            background: #e6ffe6; /* Light green */
            border-color: #28a745; /* Green */
            color: #1a6d2f; /* Dark green text */
        }

        .message.error-message {
            background: #ffe6e6; /* Light red */
            border-color: #dc3545; /* Red */
            color: #9f3a47; /* Dark red text */
        }

        /* Table Styling */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden; /* Ensures rounded corners on children */
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        th, td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        th {
            background-color: #e9eff3; /* Lighter blue-gray for headers */
            font-weight: bold;
            color: #555;
            text-transform: uppercase;
            font-size: 0.9em;
            letter-spacing: 0.5px;
        }

        tr:nth-child(even) {
            background-color: #f9fbfd; /* Subtle zebra striping */
        }

        tr:hover {
            background-color: #eef5fa; /* Light blue on hover */
        }

        /* Data Display Box */
        .data-display {
            background-color: #eef7ff; /* Very light blue */
            border: 1px solid #b3e0ff;
            border-radius: 8px;
            padding: 20px;
            margin-top: 25px;
            box-shadow: inset 0 1px 5px rgba(0,0,0,0.03);
            font-size: 0.95em;
        }

        .data-display h3 {
            color: #007bbd; /* Slightly darker blue */
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px dashed #cceeff;
            padding-bottom: 10px;
        }

        .data-display p {
            margin: 8px 0;
            color: #444;
        }
        .data-display p strong {
            color: #222;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-group {
                flex-direction: column; /* Stack label and input on small screens */
                align-items: flex-start;
            }
            label {
                flex: none; /* Remove fixed width */
                width: 100%;
                text-align: left;
                margin-bottom: 8px;
            }
            input[type="number"],
            input[type="date"],
            input[type="time"],
            input[type="text"],
            select,
            textarea {
                max-width: 100%; /* Allow inputs to take full width */
            }
            /* Table responsiveness */
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            tr {
                border: 1px solid #ccc;
                margin-bottom: 15px;
                border-radius: 8px;
            }
            td {
                border: none;
                border-bottom: 1px solid #eee;
                position: relative;
                padding-left: 50%; /* Make space for the pseudo-element label */
                text-align: right;
            }
            td:before {
                position: absolute;
                top: 0;
                left: 6px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #666;
            }
            /* Label the data - These are examples, you'll need to adjust based on your actual table columns */
            td:nth-of-type(1):before { content: "Employee:"; }
            td:nth-of-type(2):before { content: "Date:"; }
            td:nth-of-type(3):before { content: "Status:"; }
            td:nth-of-type(4):before { content: "Check-in:"; }
            td:nth-of-type(5):before { content: "Check-out:"; }
            td:nth-of-type(6):before { content: "Notes:"; }
        }

        @media (max-width: 600px) {
            .actions-container {
                flex-direction: column;
                align-items: center;
            }
            .action-btn {
                width: 80%;
            }
            .btn-container {
                flex-direction: column;
                align-items: center;
            }
        }
        /* CSS for section visibility. JavaScript will add/remove the 'active' class. */
        .section {
            display: none; /* Hidden by default */
        }
        .section.active {
            display: block; /* Shown when 'active' class is present */
        }

    </style>
</head>
<body>
    
    <div class="container">
        <h1>Employee Attendance and Salary System</h1>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo strpos($message, 'Error') !== false || strpos($message, 'Failed') !== false ? 'error-message' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="btn-container">
            <div class="dropdown">
                <button class="dropdown-button">Attendance Actions</button>
                <div class="dropdown-content">
                    <a href="#mark-attendance-section">Mark Attendance</a>
                    <a href="#recent-attendance-section">Recent Records</a>
                    <a href="#attendance-report-section">Generate Report</a>
                </div>
            </div>

            <div class="dropdown">
                <button class="dropdown-button">Salary Actions</button>
                <div class="dropdown-content">
                    <a href="#salary-structure-section">Add/Update Structure</a>
                    <a href="#generate-salary-section">Generate Monthly Salary</a>
                    <a href="#recent-salary-section">Recent Payments</a>
                    <a href="#salary-report-section">Generate Salary Report</a>
                </div>
            </div>

            <div class="dropdown">
                <button class="dropdown-button">Employee Data</button>
                <div class="dropdown-content">
                    <a href="#view-employee-data-section">View Employee Details</a>
                </div>
            </div>
        </div>
        <div class="section" id="mark-attendance-section">
            <h2>Mark Attendance</h2>
            <form method="post">
                <input type="hidden" name="action" value="mark_attendance">
                
                <div class="form-group">
                    <label for="employee_id">Employee ID:</label>
                    <input type="number" name="employee_id" required>
                </div>
                
                <div class="form-group">
                    <label for="employee_first_name">First Name:</label>
                    <input type="text" id="employee_first_name" name="employee_first_name" 
                            value="<?php echo htmlspecialchars($employee_details_result['employees_first_name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="employee_last_name">Last Name:</label>
                    <input type="text" id="employee_last_name" name="employee_last_name"
                            value="<?php echo htmlspecialchars($employee_details_result['employees_last_name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="employee_job_title">Job Title:</label>
                    <input type="text" id="employee_job_title" name="employee_job_title" 
                            value="<?php echo htmlspecialchars($employee_details_result['employees_job_title'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="employee_department">Department:</label>
                    <input type="text" id="employee_department" name="employee_department"
                            value="<?php echo htmlspecialchars($employee_details_result['employees_department'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="date">Date:</label>
                    <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="check_in">Check-in Time:</label>
                    <input type="time" name="check_in">
                </div>
                
                <div class="form-group">
                    <label for="check_out">Check-out Time:</label>
                    <input type="time" name="check_out">
                </div>
                
                <div class="form-group">
                    <label for="status">Status:</label>
                    <select name="status" required>
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="half_day">Half Day</option>
                        <option value="leave">Leave</option>
                        <option value="holiday">Holiday</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes:</label>
                    <textarea name="notes" rows="3" placeholder="Optional notes"></textarea>
                </div>
                
                <button type="submit">Mark Attendance</button>
            </form>
        </div>

        <div class="section" id="salary-structure-section">
            <h2>Add/Update Employee Salary Structure</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_salary_structure">
                
                <div class="form-group">
                    <label for="structure_employee_id">Employee ID:</label>
                    <input type="number" name="structure_employee_id" required>
                </div>
                
                <div class="form-group">
                    <label for="basic_salary">Basic Salary:</label>
                    <input type="number" name="basic_salary" step="0.01" required min="0">
                </div>
                
                <div class="form-group">
                    <label for="hra">HRA (House Rent Allowance):</label>
                    <input type="number" name="hra" step="0.01" value="0.00" min="0">
                </div>
                
                <div class="form-group">
                    <label for="da">DA (Dearness Allowance):</label>
                    <input type="number" name="da" step="0.01" value="0.00" min="0">
                </div>
                
                <div class="form-group">
                    <label for="allowances">Other Allowances:</label>
                    <input type="number" name="allowances" step="0.01" value="0.00" min="0">
                </div>
                
                <div class="form-group">
                    <label for="deductions">Deductions (e.g., PF, Loans):</label>
                    <input type="number" name="deductions" step="0.01" value="0.00" min="0">
                </div>
                
                <div class="form-group">
                    <label for="tax">Tax Deduction:</label>
                    <input type="number" name="tax" step="0.01" value="0.00" min="0">
                </div>
                
                <button type="submit">Save Salary Structure</button>
            </form>
        </div>
        
        <div class="section" id="generate-salary-section">
            <h2>Generate Monthly Salary</h2>
            <form method="post">
                <input type="hidden" name="action" value="generate_salary">
                
                <div class="form-group">
                    <label for="salary_employee_id">Employee ID:</label>
                    <input type="number" name="salary_employee_id" required>
                </div>

                <div class="form-group">
                    <label for="salary_month">Month:</label>
                    <select name="salary_month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="salary_year">Year:</label>
                    <input type="number" name="salary_year" required value="<?php echo date('Y'); ?>">
                </div>
                
                <button type="submit">Generate Salary</button>
            </form>
        </div>

        <div class="section" id="view-employee-data-section">
            <h2>View Employee Details (Salary & Attendance Summary)</h2>
            <form method="post" id="employee-data-form">
                <input type="hidden" name="action" value="view_employee_data">
                <div class="form-group">
                    <label for="view_employee_id">Employee ID:</label>
                    <input type="number" name="view_employee_id" id="view_employee_id" required 
                           value="<?php echo isset($_POST['view_employee_id']) ? htmlspecialchars($_POST['view_employee_id']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="view_month">Month:</label>
                    <select name="view_month" id="view_month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" 
                                <?php echo (isset($_POST['view_month']) ? $_POST['view_month'] : date('n')) == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="view_year">Year:</label>
                    <input type="number" name="view_year" id="view_year" required 
                           value="<?php echo isset($_POST['view_year']) ? htmlspecialchars($_POST['view_year']) : date('Y'); ?>">
                </div>
                <div class="btn-container">
                    <button type="submit" class="action-btn">View Employee Data</button>
                    <button type="button" id="clear-results-btn" class="action-btn" style="background-color: #e74c3c;">Clear Results</button>
                </div>
            </form>

            <div class="data-display" id="employee-data-results">
                <?php if (isset($employee_details_result) && $employee_details_result): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3>Employee Profile</h3>
                        <button type="button" class="close-results-btn" style="background: none; border: none; cursor: pointer; font-size: 1.5em;">&times;</button>
                    </div>
                    <p><strong>Employee ID:</strong> <?php echo htmlspecialchars($employee_details_result['employee_id']); ?></p>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($employee_details_result['employees_first_name'] . ' ' . $employee_details_result['employees_last_name']); ?></p>
                    <p><strong>Job Title:</strong> <?php echo htmlspecialchars($employee_details_result['employees_job_title'] ?? 'N/A'); ?></p>
                    <p><strong>Department:</strong> <?php echo htmlspecialchars($employee_details_result['employees_department'] ?? 'N/A'); ?></p>

                    <?php if (isset($salary_details_for_display) && $salary_details_for_display): ?>
                        <h3>Current Salary Structure</h3>
                        <p><strong>Basic Salary:</strong> $<?php echo number_format($salary_details_for_display['basic_salary'], 2); ?></p>
                        <p><strong>HRA:</strong> $<?php echo number_format($salary_details_for_display['hra'], 2); ?></p>
                        <p><strong>DA:</strong> $<?php echo number_format($salary_details_for_display['da'], 2); ?></p>
                        <p><strong>Allowances:</strong> $<?php echo number_format($salary_details_for_display['allowances'], 2); ?></p>
                        <p><strong>Deductions:</strong> $<?php echo number_format($salary_details_for_display['deductions'], 2); ?></p>
                        <p><strong>Tax:</strong> $<?php echo number_format($salary_details_for_display['tax'], 2); ?></p>
                        <p><strong>Effective From:</strong> <?php echo htmlspecialchars($salary_details_for_display['effective_from']); ?></p>
                    <?php else: ?>
                        <p>No current salary structure found for this employee.</p>
                    <?php endif; ?>

                    <?php if (isset($attendance_summary_for_display) && $attendance_summary_for_display): ?>
                        <h3>Attendance Summary (<?php echo date('F', mktime(0, 0, 0, $view_month, 10)) . ' ' . $view_year; ?>)</h3>
                        <p><strong>Present Days:</strong> <?php echo htmlspecialchars($attendance_summary_for_display['present_days']); ?></p>
                        <p><strong>Absent Days:</strong> <?php echo htmlspecialchars($attendance_summary_for_display['absent_days']); ?></p>
                        <p><strong>Half Days:</strong> <?php echo htmlspecialchars($attendance_summary_for_display['half_days']); ?></p>
                        <p><strong>Leave Days:</strong> <?php echo htmlspecialchars($attendance_summary_for_display['leave_days']); ?></p>
                        <p><strong>Holiday Days:</strong> <?php echo htmlspecialchars($attendance_summary_for_display['holiday_days']); ?></p>
                        <p><strong>Total Entries:</strong> <?php echo htmlspecialchars($attendance_summary_for_display['total_entries']); ?></p>
                    <?php else: ?>
                        <p>No attendance summary found for the selected period.</p>
                    <?php endif; ?>

                    <?php if (!empty($employee_attendance_records)): ?>
                        <h3>Detailed Attendance Records (<?php echo date('F', mktime(0, 0, 0, $view_month, 10)) . ' ' . $view_year; ?>)</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employee_attendance_records as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['date']); ?></td>
                                    <td><?php echo htmlspecialchars($record['status']); ?></td>
                                    <td><?php echo htmlspecialchars($record['check_in'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($record['check_out'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($record['notes'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No detailed attendance records found for this employee for the selected month/year.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="section" id="recent-attendance-section">
            <h2>Recent Attendance Records (Global View)</h2>
            <p>This section shows the most recent attendance entries across all employees. For specific employee attendance, use the "View Employee Details" section above or download the "Attendance Report".</p>
            <?php
            // Fetch and display the 10 most recent attendance records
            $query_recent_attendance = "SELECT ea.*, e.employees_first_name, e.employees_last_name 
                                FROM employee_attendance ea
                                JOIN employee e ON ea.employee_id = e.employee_id
                                ORDER BY ea.created_at DESC LIMIT 10";
            $result_recent_attendance = $conn->query($query_recent_attendance);

            if ($result_recent_attendance && $result_recent_attendance->num_rows > 0):
            ?>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($record = $result_recent_attendance->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($record['employees_first_name'] . ' ' . $record['employees_last_name'] . ' (ID: ' . $record['employee_id'] . ')'); ?></td>
                        <td><?php echo htmlspecialchars($record['date']); ?></td>
                        <td><?php echo htmlspecialchars($record['status']); ?></td>
                        <td><?php echo htmlspecialchars($record['check_in'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($record['check_out'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($record['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No recent attendance records found.</p>
            <?php endif; ?>
        </div>

        <div class="section" id="attendance-report-section">
            <h2>Generate Attendance Report (CSV)</h2>
            <form method="post">
                <input type="hidden" name="action" value="download_attendance_report">
                <div class="form-group">
                    <label for="report_employee_id">Employee ID:</label>
                    <input type="number" name="report_employee_id" id="report_employee_id" required>
                </div>
                <div class="form-group">
                    <label for="report_start_date">Start Date:</label>
                    <input type="date" name="report_start_date" id="report_start_date" required value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="form-group">
                    <label for="report_end_date">End Date:</label>
                    <input type="date" name="report_end_date" id="report_end_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <button type="submit">Download Attendance Report</button>
            </form>
        </div>

        <div class="section" id="salary-report-section">
            <h2>Generate Salary Report (CSV)</h2>
            <form method="post">
                <input type="hidden" name="action" value="download_salary_report">
                <div class="form-group">
                    <label for="salary_report_employee_id">Employee ID:</label>
                    <input type="number" name="salary_report_employee_id" id="salary_report_employee_id" required>
                </div>
                <div class="form-group">
                    <label for="salary_report_month">Month:</label>
                    <select name="salary_report_month" id="salary_report_month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="salary_report_year">Year:</label>
                    <input type="number" name="salary_report_year" id="salary_report_year" required value="<?php echo date('Y'); ?>">
                </div>
                <button type="submit">Download Salary Report</button>
            </form>
        </div>

        <div class="section" id="recent-salary-section">
            <h2>Recent Salary Payments (Global View)</h2>
            <p>This section shows the most recent salary payments made across all employees. For specific employee salary details, use the "View Employee Details" section above or download the "Salary Report".</p>
            <?php
            // Fetch and display recent salary payments
            $query_salary_payments = "SELECT sp.*, e.employees_first_name, e.employees_last_name 
                                          FROM salary_payments sp
                                          JOIN employee e ON sp.employee_id = e.employee_id
                                          ORDER BY sp.payment_date DESC, sp.payment_id DESC LIMIT 10";
            $result_salary_payments = $conn->query($query_salary_payments);

            if ($result_salary_payments && $result_salary_payments->num_rows > 0):
            ?>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Month/Year</th>
                        <th>Amount Paid</th>
                        <th>Payment Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($payment = $result_salary_payments->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($payment['employees_first_name'] . ' ' . $payment['employees_last_name'] . ' (ID: ' . $payment['employee_id'] . ')'); ?></td>
                        <td><?php echo htmlspecialchars(date('F', mktime(0, 0, 0, $payment['month'], 10)) . ' ' . $payment['year']); ?></td>
                        <td>$<?php echo htmlspecialchars(number_format($payment['amount_paid'], 2)); ?></td>
                        <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                        <td><?php echo htmlspecialchars($payment['status']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No recent salary payments found.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const allSections = document.querySelectorAll('.section');
            const dropdownButtons = document.querySelectorAll('.dropdown-button');
            const dropdownLinks = document.querySelectorAll('.dropdown-content a');
            const messageDiv = document.querySelector('.message');
            
            const viewEmployeeDataSection = document.getElementById('view-employee-data-section');
            const employeeDataForm = document.getElementById('employee-data-form');
            const employeeDataResults = document.getElementById('employee-data-results');
            const clearResultsBtn = document.getElementById('clear-results-btn');


            // Function to hide all sections and remove 'active' class
            function hideAllSections() {
                allSections.forEach(section => {
                    section.classList.remove('active');
                });
            }

            // Function to set an active section
            function setActiveSection(targetElement) {
                hideAllSections(); // Hide all others first
                if (targetElement) {
                    targetElement.classList.add('active'); // Add active to the target
                }
            }

            // --- Initial page load logic ---
            // Hide all sections on page load to ensure nothing is visible initially by default.
            // Content will be revealed upon dropdown link click.
            hideAllSections();

            // Also, ensure the results div is hidden by default on page load/refresh
            if (employeeDataResults) {
                employeeDataResults.style.display = 'none';
                employeeDataResults.innerHTML = ''; // Clear any potential lingering content
            }
            
            // Handle dropdown button clicks to toggle dropdown content
            dropdownButtons.forEach(button => {
                button.addEventListener('click', function(event) {
                    event.stopPropagation(); // Prevent document click from closing immediately
                    const dropdownContent = this.nextElementSibling;
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-content').forEach(content => {
                        if (content !== dropdownContent) {
                            content.style.display = 'none';
                        }
                    });
                    dropdownContent.style.display = dropdownContent.style.display === 'block' ? 'none' : 'block';
                });
            });

            // Close dropdown if clicked outside
            document.addEventListener('click', function(event) {
                dropdownButtons.forEach(button => {
                    const dropdownContent = button.nextElementSibling;
                    if (dropdownContent && !button.contains(event.target) && !dropdownContent.contains(event.target)) {
                        dropdownContent.style.display = 'none';
                    }
                });
            });

            // Handle dropdown link clicks to show/hide sections
            dropdownLinks.forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault(); // Prevent default jump behavior

                    const targetId = this.getAttribute('href'); // e.g., "#mark-attendance-section"
                    const targetElement = document.querySelector(targetId);

                    if (targetElement) {
                        setActiveSection(targetElement); // Show the target section by adding 'active' class

                        // If navigating to the employee data section, hide its results initially
                        if (targetId === '#view-employee-data-section' && employeeDataResults) {
                            employeeDataResults.style.display = 'none';
                            employeeDataResults.innerHTML = ''; // Clear previous results
                        }

                        // Scroll to the target section
                        window.scrollTo({
                            top: targetElement.offsetTop - 20, // Adjust 20px for padding/margin
                            behavior: 'smooth'
                        });

                        // Close the dropdown after clicking a link
                        this.closest('.dropdown-content').style.display = 'none';
                    }
                });
            });

            // --- Form Submission Handler for "View Employee Data" (AJAX) ---
            if (employeeDataForm) {
                employeeDataForm.addEventListener('submit', function(event) {
                    event.preventDefault(); // Prevent the default form submission (NO PAGE RELOAD!)

                    // Ensure the parent section is active (it should be if user navigated via dropdown)
                    // This is crucial: if this section was hidden, it needs to be visible to show results.
                    viewEmployeeDataSection.classList.add('active');

                    const formData = new FormData(employeeDataForm);
                    const phpEndpoint = window.location.href; // The current PHP file will handle the POST request

                    // Optional: Show a loading indicator
                    if (employeeDataResults) {
                        employeeDataResults.innerHTML = '<p style="text-align: center; padding: 20px;">Loading data...</p>';
                        employeeDataResults.style.display = 'block'; // Show loading message
                    }

                    fetch(phpEndpoint, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.text(); // Expecting HTML back from PHP
                    })
                    .then(html => {
                        // PHP returns the whole page, so we need to extract the results div content
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const fetchedResultsDiv = doc.getElementById('employee-data-results');

                        if (employeeDataResults && fetchedResultsDiv) {
                            employeeDataResults.innerHTML = fetchedResultsDiv.innerHTML; // Populate results with content from the fetched div
                            employeeDataResults.style.display = 'block'; // Show results here!
                        } else if (employeeDataResults) {
                             // If no results div was found in the fetched HTML, clear and display a message
                            employeeDataResults.innerHTML = '<p style="color: red;">No data found for the provided Employee ID, Month, and Year.</p>';
                            employeeDataResults.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching employee data:', error);
                        if (employeeDataResults) {
                            employeeDataResults.innerHTML = '<p style="color: red;">Failed to load data. Please check Employee ID, Month, and Year.</p>';
                            employeeDataResults.style.display = 'block';
                        }
                    });
                });
            }

            // Clear Results button functionality
            if (clearResultsBtn) {
                clearResultsBtn.addEventListener('click', function() {
                    if (employeeDataForm) {
                        employeeDataForm.reset();
                    }
                    if (employeeDataResults) {
                        employeeDataResults.style.display = 'none'; // Hide results
                        employeeDataResults.innerHTML = ''; // Clear content
                    }
                });
            }

            // Close Results button functionality (using event delegation for dynamically loaded content)
            // Using event delegation because the button might be inside content loaded via AJAX
            document.addEventListener('click', function(event) {
                if (event.target.classList.contains('close-results-btn')) {
                    if (employeeDataResults) {
                        employeeDataResults.style.display = 'none';
                        employeeDataResults.innerHTML = '';
                    }
                }
            });
        });
        
    </script>
</body>
</html>