<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$stmt = $pdo->prepare('SELECT logged_in_at, ip_address FROM login_activity_logs WHERE user_id = ? ORDER BY logged_in_at DESC, id DESC');
$stmt->execute([$_SESSION['user_id']]);
$logs = $stmt->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<main class="min-h-screen bg-gray-50 py-8"><div class="max-w-4xl mx-auto px-6"><div class="bg-white rounded-3xl shadow-xl p-8">
<h1 class="text-3xl font-bold">Login Activity</h1><p class="text-gray-600 mt-2">Total logins: <strong><?= count($logs) ?></strong></p>
<?php if (!$logs): ?><p class="mt-6 text-gray-600">No login activity has been recorded yet.</p><?php else: ?><div class="mt-6 overflow-x-auto"><table class="w-full"><thead class="bg-gray-100"><tr><th class="text-left p-3">Date</th><th class="text-left p-3">Time</th><th class="text-left p-3">IP address</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr class="border-t"><td class="p-3"><?= date('F j, Y', strtotime($log['logged_in_at'])) ?></td><td class="p-3"><?= date('H:i', strtotime($log['logged_in_at'])) ?></td><td class="p-3"><?= htmlspecialchars($log['ip_address'] ?: 'Unavailable') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></div></main><?php include __DIR__ . '/../includes/footer.php'; ?>
