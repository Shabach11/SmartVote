<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Administrator access is required.'); }

$reviews = $pdo->query('SELECT er.user_id, er.rating, er.comment, er.submitted_at, e.id AS election_id, e.title, e.organization_name, u.full_name, u.email
    FROM election_reviews er
    INNER JOIN elections e ON e.id = er.election_id
    LEFT JOIN users u ON u.id = er.user_id
    ORDER BY er.submitted_at DESC')->fetchAll();
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-7xl mx-auto px-6">
        <div class="flex justify-between items-center mb-8">
            <div><h1 class="text-4xl font-bold text-gray-900">Review &amp; Feedback</h1><p class="text-gray-600 mt-2">Feedback submitted by voters after their elections ended.</p></div>
            <a href="index.php" class="app-button app-button-light">Admin Dashboard</a>
        </div>

        <?php if (!$reviews): ?>
            <div class="bg-white rounded-2xl shadow p-12 text-center"><i class="bi bi-chat-square-text text-4xl text-blue-600"></i><p class="text-gray-500 text-xl mt-4">No election reviews have been submitted yet.</p></div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($reviews as $review): ?>
                    <article class="bg-white rounded-2xl shadow p-7 border border-gray-100">
                        <div class="flex justify-between items-start gap-4"><div><h2 class="text-xl font-semibold"><?= htmlspecialchars($review['title']) ?></h2><p class="text-gray-600 mt-1"><?= htmlspecialchars($review['organization_name']) ?></p></div><span class="text-amber-500 font-semibold whitespace-nowrap" aria-label="<?= (int)$review['rating'] ?> out of 5 stars"><?php for ($star = 1; $star <= 5; $star++): ?><i class="bi <?= $star <= (int)$review['rating'] ? 'bi-star-fill' : 'bi-star' ?>" aria-hidden="true"></i><?php endfor; ?></span></div>
                        <p class="mt-5 text-gray-700 whitespace-pre-line"><?= htmlspecialchars($review['comment']) ?></p>
                        <div class="mt-6 pt-4 border-t text-sm text-gray-500"><p><strong class="text-gray-700">User #<?= (int)$review['user_id'] ?></strong><?= !empty($review['full_name']) ? ' &middot; ' . htmlspecialchars($review['full_name']) : '' ?><?= !empty($review['email']) ? ' (' . htmlspecialchars($review['email']) . ')' : '' ?></p><p class="mt-1">Submitted <?= htmlspecialchars(date('M d, Y H:i', strtotime($review['submitted_at']))) ?></p></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
