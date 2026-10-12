<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireAdmin();

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? '';
        if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
            $error = 'Invalid request action.';
        } else {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            try {
                $pdo->beginTransaction();
                $find = $pdo->prepare('SELECT id, full_name, email, organization_name FROM election_creation_requests WHERE id = ? FOR UPDATE');
                $find->execute([$requestId]);
                $request = $find->fetch();
                if (!$request) throw new Exception('Election request not found.');

                $update = $pdo->prepare("UPDATE election_creation_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
                $update->execute([$newStatus, $_SESSION['user_id'], $requestId]);
                if ($update->rowCount() !== 1) throw new Exception('This request has already been reviewed.');
                $pdo->commit();

                log_audit_event($pdo, 'election_creation_request_' . $newStatus, 'Request #' . $requestId . ' was ' . $newStatus . '.', null, $_SESSION['user_id']);
                if ($newStatus === 'approved') {
                    $subject = 'Your SmartVote election creation request was approved';
                    $message = "Hello {$request['full_name']},\n\nYour request to create an election for {$request['organization_name']} has been approved. You can now sign in and open Admin > Create Election to begin.\n\nRegards,\nSmartVote";
                } else {
                    $subject = 'Your SmartVote election creation request was declined';
                    $message = "Hello {$request['full_name']},\n\nYour request to create an election for {$request['organization_name']} was declined. You do not currently have election creation access.\n\nRegards,\nSmartVote";
                }
                $emailSent = send_smartvote_email($request['email'], $subject, $message);
                $success = 'Request #' . $requestId . ' was ' . $newStatus . '.' . ($emailSent ? ' The applicant has been notified by email.' : ' The decision was saved, but the notification email could not be sent.');
            } catch (Exception $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $exception->getMessage();
            }
        }
    }
}

$requests = $pdo->query('SELECT id, full_name, email, organization_name, organization_logo, reason, status, created_at FROM election_creation_requests ORDER BY created_at DESC, id DESC')->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="min-h-screen bg-gray-50 py-8"><div class="max-w-7xl mx-auto px-6">
    <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4 mb-8"><div><h1 class="text-4xl font-bold text-gray-900">Election Requests</h1><p class="text-gray-600 mt-2">Review and manage election creation requests.</p></div><a href="index.php" class="app-button app-button-light">Admin Dashboard</a></div>
    <?php if ($error): ?><div class="mb-6 rounded-xl bg-red-100 text-red-800 px-5 py-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-6 rounded-xl bg-green-100 text-green-800 px-5 py-4"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full min-w-[1100px] text-left"><thead class="bg-gray-100 text-gray-700 text-sm uppercase"><tr><th class="p-4">Request ID</th><th class="p-4">Full Name</th><th class="p-4">Email</th><th class="p-4">Organization Name</th><th class="p-4">Organization Logo</th><th class="p-4">Reason</th><th class="p-4">Date Submitted</th><th class="p-4">Status</th><th class="p-4">Actions</th></tr></thead><tbody class="divide-y divide-gray-200">
    <?php if (!$requests): ?><tr><td colspan="9" class="p-10 text-center text-gray-500">No election creation requests have been submitted.</td></tr><?php else: foreach ($requests as $request): ?><tr class="align-top">
        <td class="p-4 font-semibold">#<?= (int)$request['id'] ?></td><td class="p-4"><?= htmlspecialchars($request['full_name']) ?></td><td class="p-4"><a class="text-blue-700 hover:underline" href="mailto:<?= htmlspecialchars($request['email']) ?>"><?= htmlspecialchars($request['email']) ?></a></td><td class="p-4"><?= htmlspecialchars($request['organization_name']) ?></td>
        <td class="p-4"><?php if ($request['organization_logo'] && strpos($request['organization_logo'], 'uploads/organization-logos/') === 0): ?><img src="<?= htmlspecialchars(url('/' . $request['organization_logo'])) ?>" alt="<?= htmlspecialchars($request['organization_name']) ?> logo" class="w-14 h-14 object-contain rounded border bg-white"><?php else: ?><span class="text-gray-400">None</span><?php endif; ?></td><td class="p-4 max-w-xs whitespace-pre-line break-words"><?= htmlspecialchars($request['reason']) ?></td><td class="p-4 whitespace-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($request['created_at']))) ?></td>
        <td class="p-4"><span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold <?= $request['status'] === 'pending' ? 'bg-blue-100 text-blue-700' : ($request['status'] === 'approved' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700') ?>"><?= htmlspecialchars(ucfirst($request['status'])) ?></span></td><td class="p-4 whitespace-nowrap"><?php if ($request['status'] === 'pending'): ?><form method="POST" class="inline-flex gap-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button name="action" value="approve" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg font-medium">Approve</button><button name="action" value="reject" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg font-medium" onclick="return confirm('Reject this election creation request?');">Reject</button></form><?php else: ?><span class="text-gray-400">Reviewed</span><?php endif; ?></td>
    </tr><?php endforeach; endif; ?></tbody></table></div>
</div></main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
