<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
$stmt = $pdo->prepare("SELECT e.*, m.expires_at, r.report_body
    FROM election_committee_members m
    INNER JOIN elections e ON e.id = m.election_id
    LEFT JOIN election_reports r ON r.election_id = e.id
    WHERE m.status = 'active' AND m.expires_at >= NOW()
      AND (m.user_id = ? OR LOWER(m.email) = LOWER(?))
    ORDER BY e.end_datetime DESC");
$stmt->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '']);
$elections = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<main class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-6xl mx-auto px-6">
        <div class="mb-8"><h1 class="text-4xl font-bold">Audit Dashboard</h1><p class="text-gray-600 mt-2">Your access is limited to the elections below and ends 48 hours after each election finishes.</p></div>
        <?php if (!$elections): ?>
            <div class="bg-white rounded-2xl shadow p-8 text-gray-600">You do not have any active Audit Committee assignments.</div>
        <?php endif; ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($elections as $election): ?>
                <article class="bg-white rounded-2xl shadow p-7">
                    <span class="pill pill-live">Audit access active</span>
                    <h2 class="text-2xl font-bold mt-4"><?= htmlspecialchars($election['title']) ?></h2>
                    <p class="text-gray-600 mt-1"><?= htmlspecialchars($election['organization_name']) ?> &middot; Code <?= htmlspecialchars($election['code'] ?? $election['slug']) ?></p>
                    <p class="text-sm text-gray-500 mt-4">Access ends <?= htmlspecialchars(date('M d, Y H:i', strtotime($election['expires_at']))) ?></p>
                    <a href="<?= url('/admin/tamper-report.php?id=' . (int)$election['id']) ?>" class="inline-block mt-5 text-blue-600 font-semibold hover:underline">Open full audit report</a>
                    <?php if (!empty($election['report_body'])): ?><details class="mt-5 pt-5 border-t"><summary class="cursor-pointer font-semibold">Stored election report</summary><div class="report-box mt-3 text-sm"><?= htmlspecialchars($election['report_body']) ?></div></details><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
