<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
$is_manager = isAdmin() || has_approved_creation_request($pdo, $_SESSION['user_id']) || has_active_subadmin_access($pdo, $_SESSION['user_id']);
if (!$is_manager) { http_response_code(403); exit('Election management access is required.'); }
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-7xl mx-auto px-6">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900">All Elections</h1>
            <a href="create-election.php" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-semibold flex items-center gap-2">
                <span>+</span> Host New Election
            </a>
        </div>

        <?php
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE created_by = ? ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $elections = $stmt->fetchAll();
        ?>

        <?php if (empty($elections)): ?>
            <div class="bg-white rounded-2xl shadow p-12 text-center">
                <p class="text-gray-500 text-xl">No elections created yet.</p>
                <a href="create-election.php" class="text-blue-600 hover:underline mt-4 inline-block">Create your first election →</a>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-hidden">
                <table class="w-full">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-4 text-left">Election Title</th>
                            <th class="px-6 py-4 text-left">Code</th>
                            <th class="px-6 py-4 text-left">Organization</th>
                            <th class="px-6 py-4 text-left">Start Date</th>
                            <th class="px-6 py-4 text-left">End Date</th>
                            <th class="px-6 py-4 text-left">Status</th>
                            <th class="px-6 py-4 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($elections as $election): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-5 font-medium"><?= htmlspecialchars($election['title']) ?></td>
                            <td class="px-6 py-5 text-gray-600 font-bold"><?= htmlspecialchars($election['code'] ?? $election['slug']) ?></td>
                            <td class="px-6 py-5 text-gray-600"><?= htmlspecialchars($election['organization_name']) ?></td>
                            <td class="px-6 py-5 text-gray-600"><?= date('M d, Y H:i', strtotime($election['start_datetime'])) ?></td>
                            <td class="px-6 py-5 text-gray-600"><?= date('M d, Y H:i', strtotime($election['end_datetime'])) ?></td>
                            <td class="px-6 py-5">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full 
                                    <?= $election['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= strtoupper($election['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-5 text-center">
                                <a href="election-details.php?id=<?= $election['id'] ?>" class="text-blue-600 hover:underline mr-4">Details</a>
                                <a href="results.php?id=<?= $election['id'] ?>" 
                                   class="text-blue-600 hover:underline mr-4">Results</a>
                                <a href="tamper-report.php?id=<?= $election['id'] ?>" class="text-red-600 hover:underline">Audit Report</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
