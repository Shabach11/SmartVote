<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $error = 'Security validation failed.';
    else {
        $userId = (int)($_POST['user_id'] ?? 0); $password = $_POST['confirm_password'] ?? '';
        $current = $pdo->prepare('SELECT password FROM users WHERE id = ?'); $current->execute([$_SESSION['user_id']]);
        if (!password_verify($password, (string)$current->fetchColumn())) $error = 'Password confirmation failed.';
        elseif ($userId === $_SESSION['user_id']) $error = 'You cannot change your own administrator role here.';
        else {
            // Admin promotion is intentionally restricted to this administrator page and applies only
            // to existing users; public registration can never set an administrative role.
            $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ? AND role = 'voter'");
            $stmt->execute([$userId]);
            if ($stmt->rowCount()) { log_audit_event($pdo, 'admin_promoted', 'Existing voter promoted to administrator.', null, $userId); $success = 'User promoted to admin.'; }
            else $error = 'Only an existing voter can be promoted.';
        }
    }
}
$users = $pdo->query("SELECT id, full_name, username, email, role FROM users WHERE role = 'voter' ORDER BY full_name")->fetchAll();
include __DIR__ . '/../../includes/header.php'; ?>
<div class="min-h-screen bg-gray-50 py-8"><div class="max-w-4xl mx-auto px-6"><div class="bg-white rounded-3xl shadow-xl p-10">
<h1 class="text-3xl font-bold">Admin Access</h1><p class="text-gray-600 mt-2">Promote an existing voter only after confirming your own password.</p>
<?php if ($error): ?><p class="bg-red-100 text-red-700 p-4 rounded-xl mt-6"><?= htmlspecialchars($error) ?></p><?php endif; ?><?php if ($success): ?><p class="bg-green-100 text-green-700 p-4 rounded-xl mt-6"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<form method="POST" class="mt-6 space-y-5"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><select name="user_id" class="w-full border p-3 rounded-xl" required><option value="">Select an existing voter</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?> — <?= htmlspecialchars($user['username']) ?></option><?php endforeach; ?></select><input type="password" name="confirm_password" class="w-full border p-3 rounded-xl" placeholder="Confirm your password" required><button class="bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold">Promote to Admin</button></form>
</div></div></div><?php include __DIR__ . '/../../includes/footer.php'; ?>
