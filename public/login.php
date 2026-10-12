<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed.';
    } else {
        $username = normalize_username($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter the username, email address, and password registered to your account.';
            log_audit_event($pdo, 'invalid_username_login', 'Invalid username login attempt.');
        } else try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE BINARY username = BINARY ? AND BINARY email = BINARY ? LIMIT 1');
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Enter the username, email address, and password registered to your account.';
            } else {
                // Any registered voter can access an election. Eligibility is verified by their
                // account and OTP, rather than requiring a pre-imported voter-list entry.
                login_user($user);
                redirect(isAdmin() ? '/admin/index.php' : '/index.php');
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}
?>

<?php include '../includes/header.php'; ?>

<div class="auth-shell">
    <aside class="auth-brand">
        <div class="flex items-center gap-3"><span class="app-brand-mark">SV</span><strong class="font-display text-xl">SmartVote</strong></div>
        <div><div class="auth-seal mb-6"><i class="bi bi-check2-circle"></i></div><h1 class="text-white auth-title mb-4">Every ballot, verified and counted once.</h1><p style="color:#a9b6cc;max-width:410px">SmartVote protects each account with email verification and keeps a complete audit trail for every election.</p></div>
        <p style="color:#a9b6cc;font-size:.85rem"><i class="bi bi-shield-check"></i> Email verification &nbsp; <i class="bi bi-journal-check"></i> Full audit log</p>
    </aside>
    <main class="auth-form-panel"><div class="auth-card">
        <div class="mb-8"><div class="auth-seal mb-4" style="width:48px;height:48px;border-color:var(--sv-blue);color:var(--sv-blue);font-size:1.1rem"><i class="bi bi-check2"></i></div><h1 class="auth-title mb-1">Sign in to SmartVote</h1><p class="text-gray-600">Sign in to find an election and cast your ballot.</p></div>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" required pattern="[A-Za-z0-9_.-]{3,80}" class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" required autocomplete="email" class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" required class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3.5 rounded-lg transition">Sign In <i class="bi bi-arrow-right ml-1"></i></button>
        </form>

        <div class="mt-5 p-4 rounded-lg" style="background:#e1f5ec;color:#0c7f58;font-size:.82rem"><i class="bi bi-shield-lock mr-2"></i>Your ballot selections are protected and voting activity is audited.</div>
        <p class="text-center mt-6 text-gray-600 text-sm">Don't have an account? <a href="register.php" class="text-blue-600 hover:underline">Register Now</a></p>
    </div></main>
</div>

<?php include '../includes/footer.php'; ?>
