<?php
// Start output buffering at the very beginning to prevent "headers already sent" errors.
ob_start();

require 'db_connect.php';
session_start();

// Check if employee is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['bank_id'])) {
    header("Location: employee_login.php");
    exit();
}

$employee_id = $_SESSION['employee_id'];
$bank_id = $_SESSION['bank_id'];
$report_type = $_POST['reportType'] ?? 'both';
$month = $_POST['month'] ?? date('m');
$year = $_POST['year'] ?? date('Y');

// Use a try-catch block to handle potential errors gracefully
try {
    // Get employee details with bank verification
    $stmt = $conn->prepare("
        SELECT e.*, b.bank_name 
        FROM employee e 
        JOIN bank_details b ON e.bank_id = b.bank_id 
        WHERE e.employee_id = ? AND e.bank_id = ?
    ");
    $stmt->bind_param("ii", $employee_id, $bank_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$employee) {
        throw new Exception("Employee not found or doesn't belong to your bank");
    }

    // Get salary information for the selected period with bank verification
    $salary_stmt = $conn->prepare("
        SELECT sp.*, es.* FROM salary_payments sp
        JOIN employee_salary es ON sp.salary_id = es.salary_id
        JOIN employee e ON sp.employee_id = e.employee_id
        WHERE sp.employee_id = ? AND sp.month = ? AND sp.year = ? AND e.bank_id = ?
    ");
    $salary_stmt->bind_param("iiii", $employee_id, $month, $year, $bank_id);
    $salary_stmt->execute();
    $salary = $salary_stmt->get_result()->fetch_assoc();
    $salary_stmt->close();

    // Get attendance for the selected period with bank verification
    $attendance_stmt = $conn->prepare("
        SELECT ea.* FROM employee_attendance ea
        JOIN employee e ON ea.employee_id = e.employee_id
        WHERE ea.employee_id = ? AND MONTH(ea.date) = ? AND YEAR(ea.date) = ? AND e.bank_id = ?
        ORDER BY ea.date
    ");
    $attendance_stmt->bind_param("iiii", $employee_id, $month, $year, $bank_id);
    $attendance_stmt->execute();
    $attendance = $attendance_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $attendance_stmt->close();

    // Calculate attendance summary
    $present_days = 0;
    $absent_days = 0;
    $half_days = 0;
    $leave_days = 0;
    $holiday_days = 0;

    foreach ($attendance as $record) {
        switch ($record['status']) {
            case 'present': $present_days++; break;
            case 'absent': $absent_days++; break;
            case 'half_day': $half_days++; break;
            case 'leave': $leave_days++; break;
            case 'holiday': $holiday_days++; break;
        }
    }

    $total_days = count($attendance);
    $working_days = $present_days + $half_days * 0.5;

    // Generate PDF report using FPDF
    if (!file_exists('fpdf/fpdf.php')) {
        throw new Exception("FPDF library not found in fpdf/ directory");
    }
    require_once('fpdf/fpdf.php');

    // Create new PDF document
    $pdf = new FPDF();
    $pdf->AddPage();

    // Set document information
    $pdf->SetTitle('Employee Report - ' . $employee['employees_first_name'] . ' ' . $employee['employees_last_name']);
    $pdf->SetAuthor($employee['bank_name']);

    // Bank header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $employee['bank_name'], 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Employee Salary and Attendance Report', 0, 1, 'C');
    $pdf->Ln(10);

    // Employee information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Employee Information', 0, 1);
    $pdf->SetFont('Arial', '', 10);

    $employee_info = "Name: " . $employee['employees_first_name'] . " " . $employee['employees_last_name'] . "\n";
    $employee_info .= "Employee ID: " . $employee['employee_id'] . "\n";
    $employee_info .= "Role: " . ucfirst($employee['role']) . "\n";
    $employee_info .= "Bank: " . $employee['bank_name'] . "\n";
    $employee_info .= "Report Period: " . date('F Y', mktime(0, 0, 0, $month, 1, $year));

    $pdf->MultiCell(0, 10, $employee_info);
    $pdf->Ln(10);

    // Salary information
    if ($report_type === 'salary' || $report_type === 'both') {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Salary Information', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        
        if ($salary) {
            $salary_info = "Basic Salary: $" . number_format($salary['basic_salary'], 2) . "\n";
            $salary_info .= "HRA: $" . number_format($salary['hra'], 2) . "\n";
            $salary_info .= "DA: $" . number_format($salary['da'], 2) . "\n";
            $salary_info .= "Allowances: $" . number_format($salary['allowances'], 2) . "\n";
            $salary_info .= "Deductions: $" . number_format($salary['deductions'], 2) . "\n";
            $salary_info .= "Tax: $" . number_format($salary['tax'], 2) . "\n";
            $salary_info .= "Net Salary: $" . number_format($salary['net_salary'], 2) . "\n";
            $salary_info .= "Payment Status: " . ucfirst($salary['status']) . "\n";
            $salary_info .= "Payment Date: " . ($salary['payment_date'] ? date('M j, Y', strtotime($salary['payment_date'])) : 'Pending');
            
            $pdf->MultiCell(0, 10, $salary_info);
        } else {
            $pdf->Cell(0, 10, 'No salary information available for selected period.', 0, 1);
        }
        $pdf->Ln(10);
    }

    // Attendance information
    if ($report_type === 'attendance' || $report_type === 'both') {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Attendance Summary', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        
        $att_summary = "Total Days: " . $total_days . "\n";
        $att_summary .= "Present Days: " . $present_days . "\n";
        $att_summary .= "Absent Days: " . $absent_days . "\n";
        $att_summary .= "Half Days: " . $half_days . "\n";
        $att_summary .= "Leave Days: " . $leave_days . "\n";
        $att_summary .= "Holiday Days: " . $holiday_days . "\n";
        $att_summary .= "Working Days: " . $working_days;
        
        $pdf->MultiCell(0, 10, $att_summary);
        $pdf->Ln(10);
        
        // Detailed attendance
        if (count($attendance) > 0) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'Detailed Attendance', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            
            // Table header
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(40, 10, 'Date', 1, 0, 'C', true);
            $pdf->Cell(30, 10, 'Check In', 1, 0, 'C', true);
            $pdf->Cell(30, 10, 'Check Out', 1, 0, 'C', true);
            $pdf->Cell(40, 10, 'Status', 1, 0, 'C', true);
            $pdf->Cell(50, 10, 'Notes', 1, 1, 'C', true);
            
            // Table rows
            $pdf->SetFillColor(255, 255, 255);
            foreach ($attendance as $record) {
                $pdf->Cell(40, 10, date('M j, Y', strtotime($record['date'])), 1, 0, 'L', true);
                $pdf->Cell(30, 10, $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '--', 1, 0, 'C', true);
                $pdf->Cell(30, 10, $record['check_out'] ? date('h:i A', strtotime($record['check_out'])) : '--', 1, 0, 'C', true);
                $pdf->Cell(40, 10, ucfirst(str_replace('_', ' ', $record['status'])), 1, 0, 'C', true);
                $pdf->Cell(50, 10, $record['notes'] ? substr($record['notes'], 0, 20) . (strlen($record['notes']) > 20 ? '...' : '') : '--', 1, 1, 'L', true);
            }
        } else {
            $pdf->Cell(0, 10, 'No attendance records available for selected period.', 0, 1);
        }
    }

    // Output the PDF after all content has been created
    $pdf->Output('employee_report_' . $employee_id . '_' . $month . '_' . $year . '.pdf', 'D');
    ob_end_flush();

} catch (Exception $e) {
    // If a fatal error occurs, clean the output buffer and display an error message
    ob_end_clean();
    die("Error generating report: " . $e->getMessage());
}
?>