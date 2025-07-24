<?php
require 'db_connect.php';
session_start();

// Check if employee is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['bank_id'])) {
    header("Location: employee_login.php");
    exit();
}

$employee_id = $_SESSION['employee_id'];
$bank_id = $_SESSION['bank_id']; // Get bank_id from session
$report_type = $_POST['reportType'] ?? 'both';
$month = $_POST['month'] ?? date('m');
$year = $_POST['year'] ?? date('Y');
$format = $_POST['format'] ?? 'pdf';

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
    die("Employee not found or doesn't belong to your bank");
}

// Get salary information for the selected period with bank verification
$salary_stmt = $conn->prepare("
    SELECT sp.*, es.* 
    FROM salary_payments sp
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
    SELECT ea.* 
    FROM employee_attendance ea
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

// Generate report based on format
if ($format === 'pdf') {
    require_once 'tcpdf/tcpdf.php';
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($employee['bank_name']);
    $pdf->SetTitle('Employee Report - ' . $employee['employees_first_name'] . ' ' . $employee['employees_last_name']);
    $pdf->SetSubject('Salary and Attendance Report');
    
    // Set margins
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // Add a page
    $pdf->AddPage();
    
    // Bank logo and header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $employee['bank_name'], 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Employee Salary and Attendance Report', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Employee information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Employee Information', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $employee_info = "Name: " . $employee['employees_first_name'] . " " . $employee['employees_last_name'] . "\n";
    $employee_info .= "Employee ID: " . $employee['employee_id'] . "\n";
    $employee_info .= "Role: " . ucfirst($employee['role']) . "\n";
    $employee_info .= "Bank: " . $employee['bank_name'] . "\n";
    $employee_info .= "Report Period: " . date('F Y', mktime(0, 0, 0, $month, 1, $year));
    
    $pdf->MultiCell(0, 10, $employee_info, 0, 'L');
    $pdf->Ln(10);
    
    // Salary information
    if ($report_type === 'salary' || $report_type === 'both') {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Salary Information', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        
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
            
            $pdf->MultiCell(0, 10, $salary_info, 0, 'L');
        } else {
            $pdf->Cell(0, 10, 'No salary information available for selected period.', 0, 1);
        }
        $pdf->Ln(10);
    }
    
    // Attendance information
    if ($report_type === 'attendance' || $report_type === 'both') {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Attendance Summary', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        
        $att_summary = "Total Days: " . $total_days . "\n";
        $att_summary .= "Present Days: " . $present_days . "\n";
        $att_summary .= "Absent Days: " . $absent_days . "\n";
        $att_summary .= "Half Days: " . $half_days . "\n";
        $att_summary .= "Leave Days: " . $leave_days . "\n";
        $att_summary .= "Holiday Days: " . $holiday_days . "\n";
        $att_summary .= "Working Days: " . $working_days;
        
        $pdf->MultiCell(0, 10, $att_summary, 0, 'L');
        $pdf->Ln(10);
        
        // Detailed attendance
        if (count($attendance) > 0) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'Detailed Attendance', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            
            // Table header
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(40, 10, 'Date', 1, 0, 'C', 1);
            $pdf->Cell(30, 10, 'Check In', 1, 0, 'C', 1);
            $pdf->Cell(30, 10, 'Check Out', 1, 0, 'C', 1);
            $pdf->Cell(40, 10, 'Status', 1, 0, 'C', 1);
            $pdf->Cell(50, 10, 'Notes', 1, 1, 'C', 1);
            
            // Table rows
            $pdf->SetFillColor(255, 255, 255);
            foreach ($attendance as $record) {
                $pdf->Cell(40, 10, date('M j, Y', strtotime($record['date'])), 1, 0, 'L', 1);
                $pdf->Cell(30, 10, $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '--', 1, 0, 'C', 1);
                $pdf->Cell(30, 10, $record['check_out'] ? date('h:i A', strtotime($record['check_out'])) : '--', 1, 0, 'C', 1);
                $pdf->Cell(40, 10, ucfirst(str_replace('_', ' ', $record['status'])), 1, 0, 'C', 1);
                $pdf->Cell(50, 10, $record['notes'] ? substr($record['notes'], 0, 20) . (strlen($record['notes']) > 20 ? '...' : '') : '--', 1, 1, 'L', 1);
            }
        } else {
            $pdf->Cell(0, 10, 'No attendance records available for selected period.', 0, 1);
        }
    }
    
    // Output the PDF
    $pdf->Output('employee_report_' . $employee_id . '_' . $month . '_' . $year . '.pdf', 'D');
    
} elseif ($format === 'excel' || $format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=employee_report_' . $employee_id . '_' . $month . '_' . $year . '.' . ($format === 'excel' ? 'xls' : 'csv'));
    
    $output = fopen('php://output', 'w');
    
    // Bank header
    fputcsv($output, [$employee['bank_name']]);
    fputcsv($output, ['Employee Salary and Attendance Report']);
    fputcsv($output, []);
    
    // Employee information
    fputcsv($output, ['Employee Information']);
    fputcsv($output, ['Name:', $employee['employees_first_name'] . ' ' . $employee['employees_last_name']]);
    fputcsv($output, ['Employee ID:', $employee['employee_id']]);
    fputcsv($output, ['Role:', ucfirst($employee['role'])]);
    fputcsv($output, ['Bank:', $employee['bank_name']]);
    fputcsv($output, ['Report Period:', date('F Y', mktime(0, 0, 0, $month, 1, $year))]);
    fputcsv($output, []);
    
    // Salary information
    if ($report_type === 'salary' || $report_type === 'both') {
        fputcsv($output, ['Salary Information']);
        if ($salary) {
            fputcsv($output, ['Basic Salary:', '$' . number_format($salary['basic_salary'], 2)]);
            fputcsv($output, ['HRA:', '$' . number_format($salary['hra'], 2)]);
            fputcsv($output, ['DA:', '$' . number_format($salary['da'], 2)]);
            fputcsv($output, ['Allowances:', '$' . number_format($salary['allowances'], 2)]);
            fputcsv($output, ['Deductions:', '$' . number_format($salary['deductions'], 2)]);
            fputcsv($output, ['Tax:', '$' . number_format($salary['tax'], 2)]);
            fputcsv($output, ['Net Salary:', '$' . number_format($salary['net_salary'], 2)]);
            fputcsv($output, ['Payment Status:', ucfirst($salary['status'])]);
            fputcsv($output, ['Payment Date:', $salary['payment_date'] ? date('M j, Y', strtotime($salary['payment_date'])) : 'Pending']);
        } else {
            fputcsv($output, ['No salary information available for selected period.']);
        }
        fputcsv($output, []);
    }
    
    // Attendance information
    if ($report_type === 'attendance' || $report_type === 'both') {
        fputcsv($output, ['Attendance Summary']);
        fputcsv($output, ['Total Days:', $total_days]);
        fputcsv($output, ['Present Days:', $present_days]);
        fputcsv($output, ['Absent Days:', $absent_days]);
        fputcsv($output, ['Half Days:', $half_days]);
        fputcsv($output, ['Leave Days:', $leave_days]);
        fputcsv($output, ['Holiday Days:', $holiday_days]);
        fputcsv($output, ['Working Days:', $working_days]);
        fputcsv($output, []);
        
        if (count($attendance) > 0) {
            fputcsv($output, ['Detailed Attendance']);
            fputcsv($output, ['Date', 'Check In', 'Check Out', 'Status', 'Notes']);
            
            foreach ($attendance as $record) {
                fputcsv($output, [
                    date('M j, Y', strtotime($record['date'])),
                    $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '--',
                    $record['check_out'] ? date('h:i A', strtotime($record['check_out'])) : '--',
                    ucfirst(str_replace('_', ' ', $record['status'])),
                    $record['notes'] ?: '--'
                ]);
            }
        } else {
            fputcsv($output, ['No attendance records available for selected period.']);
        }
    }
    
    fclose($output);
}