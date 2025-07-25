<?php
// bank_register.php - Handles bank employee registration with multiple employees

include 'db_connect.php';

$message = '';
$error = '';

// Handle Registration
if (isset($_POST['register'])) {
    // Bank details
    $bank_name = $_POST['reg_bank_name'];
    $bank_id = $_POST['reg_bank_id']; // This will be the branch_id in bank_details
    $bank_password = password_hash($_POST['reg_bank_password'], PASSWORD_DEFAULT);
    
    // Start transaction
    $conn->begin_transaction();
    $registration_success = false;

    try {
        // Check if bank exists, if not create it
        $stmt_check_bank = $conn->prepare("SELECT bank_id FROM bank_details WHERE branch_id = ?");
        $stmt_check_bank->bind_param("i", $bank_id);
        $stmt_check_bank->execute();
        $stmt_check_bank->store_result();

        $new_bank_id = null;
        
        if ($stmt_check_bank->num_rows > 0) {
            // Bank exists, get its ID
            $stmt_check_bank->bind_result($existing_bank_id);
            $stmt_check_bank->fetch();
            $new_bank_id = $existing_bank_id;
            // If the bank exists, you might want to prevent re-registration or update its details.
            // For now, we'll just use the existing bank_id.
            $error = "Bank with this Branch ID already exists. Please log in or use a different Branch ID.";
            throw new Exception($error); // Exit the try block
        } else {
            // Insert new bank details
            $stmt_bank = $conn->prepare("INSERT INTO bank_details (bank_name, branch_id, password) VALUES (?, ?, ?)");
            $stmt_bank->bind_param("sis", $bank_name, $bank_id, $bank_password);
            
            if ($stmt_bank->execute()) {
                $new_bank_id = $stmt_bank->insert_id;
            } else {
                throw new Exception("Error registering bank details: " . $stmt_bank->error);
            }
        }

        // If the bank registration was successful, commit and redirect
        $conn->commit();
        $registration_success = true;

    } catch (Exception $e) {
        $conn->rollback();
        // If error was not set previously (e.g., from existing bank ID), set it now.
        if (empty($error)) { 
            $error = "Registration failed: " . $e->getMessage();
        }
        error_log("Bank registration error: " . $e->getMessage());
    }

    if ($registration_success) {
        header("Location: bank_login?registration_success=true"); // Changed to bank_login.php for clarity
        exit();
    }
}

include 'header.php';
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md"> <h2 class="text-3xl font-bold text-gray-900 mb-6 text-center">Register Bank</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="bank_register" method="POST" class="space-y-4" id="registrationForm"> <div class="grid grid-cols-1 gap-4">
                <div>
                    <label for="reg_bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                    <input type="text" id="reg_bank_name" name="reg_bank_name" required 
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label for="reg_bank_id" class="block text-sm font-medium text-gray-700">Bank ID (Branch ID)</label>
                    <input type="number" id="reg_bank_id" name="reg_bank_id" required 
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
            </div>
            <div>
                <label for="reg_bank_password" class="block text-sm font-medium text-gray-700">Bank Password</label>
                <input type="password" id="reg_bank_password" name="reg_bank_password" required 
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <div class="pt-4">
                <button type="submit" name="register" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Register Bank
                </button>
            </div>
        </form>

        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">Already have an account? <a href="bank_login" class="font-medium text-indigo-600 hover:text-indigo-500">Log in</a></p>
        </div>
    </div>
</main>

<?php
include 'footer.php';
$conn->close();
?>