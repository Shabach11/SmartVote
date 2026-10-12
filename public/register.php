<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed.";
    } else {
        $full_name    = trim($_POST['full_name'] ?? '');
        $username     = normalize_username($_POST['username'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $password     = $_POST['password'] ?? '';
        $gender       = strtoupper(trim($_POST['gender'] ?? ''));

        if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
            $error = "All fields are required.";
        } elseif (!in_array($gender, ['M', 'F'], true)) {
            $error = "Please select your gender.";
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) {
            $error = "Username must be 3-80 characters and use letters, numbers, dots, hyphens, or underscores.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE BINARY email = BINARY ? OR BINARY username = BINARY ?");
                $stmt->execute([$email, $username]);
                
                if ($stmt->rowCount() > 0) {
                    $error = "Email already registered.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Public registration always creates a voter. Admin promotion is a privileged
                    // action performed only by an administrator on existing users. This keeps admin
                    // access controlled and separate from public account creation.
                    $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password, role, index_number, gender)
                                         VALUES (?, ?, ?, ?, 'voter', NULL, ?)");
                    
                    if ($stmt->execute([$full_name, $username, $email, $hashed_password, $gender])) {
                        $success = "Account created successfully! You can now login.";
                    }
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = "That email or index number is already registered.";
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        }
    }
}
?>

<?php include '../includes/header.php'; ?>

<div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4">
    <div class="max-w-md w-full bg-white rounded-xl shadow-lg p-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-600">SmartVote</h1>
            <p class="text-gray-600 mt-2">Create your account</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= $success ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="full_name" required 
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" required pattern="[A-Za-z0-9_.-]{3,80}" title="Use letters, numbers, dots, hyphens, or underscores."
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <p class="text-sm text-gray-500 mt-1">The first letter is capitalized automatically when your account is created.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" required 
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" required minlength="6"
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-gray-700">Gender</legend>
                <p id="gender-help" class="text-sm text-gray-500 mt-1">Select one option to continue.</p>
                <div class="gender-toggle-group" aria-describedby="gender-help">
                    <label class="gender-toggle-option">
                        <input type="radio" name="gender" value="M" required<?= (($_POST['gender'] ?? '') === 'M') ? ' checked' : '' ?>>
                        <span class="gender-toggle-code">M</span>
                        <span>Male</span>
                    </label>
                    <label class="gender-toggle-option">
                        <input type="radio" name="gender" value="F"<?= (($_POST['gender'] ?? '') === 'F') ? ' checked' : '' ?>>
                        <span class="gender-toggle-code">F</span>
                        <span>Female</span>
                    </label>
                </div>
            </fieldset>

            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3.5 rounded-lg transition">
                Create Account
            </button>
        </form>

        <p class="text-center mt-6 text-gray-600">
            Already have an account? 
            <a href="login.php" class="text-blue-600 hover:underline">Sign in</a>
        </p>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
