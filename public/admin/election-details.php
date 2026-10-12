<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$election_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ? AND created_by = ? LIMIT 1");
$stmt->execute([$election_id, $_SESSION['user_id']]);
$election = $stmt->fetch();

if (!$election || !can_manage_election($pdo, $election_id)) {
    die('Election not found.');
}
$success = $_SESSION['election_creation_success'] ?? '';
unset($_SESSION['election_creation_success']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'invite_auditors') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $emails = preg_split('/[\s,;]+/', trim($_POST['auditor_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $emails = array_values(array_unique(array_map(fn($email) => strtolower(trim($email)), $emails)));
        if (count($emails) < 1 || count($emails) > 6) {
            $error = 'Enter between 1 and 6 unique Audit Committee email addresses.';
        } elseif (array_filter($emails, fn($email) => !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'Enter valid Audit Committee email addresses.';
        } else {
            try {
                $memberCount = $pdo->prepare("SELECT COUNT(*) FROM election_committee_members
                    WHERE election_id = ? AND status IN ('pending', 'accepted', 'active')");
                $memberCount->execute([$election_id]);
                if ((int)$memberCount->fetchColumn() + count($emails) > 6) {
                    throw new Exception('An election can have no more than 6 Audit Committee members.');
                }
                $pdo->beginTransaction();
                $invitations = [];
                foreach ($emails as $email) {
                    $invitations[] = ['email' => $email, 'token' => create_audit_invitation($pdo, $election, $email)];
                }
                $pdo->commit();
                foreach ($invitations as $invitation) {
                    if (!send_audit_invitation_email($election, $invitation['email'], $invitation['token'])) {
                        error_log('SmartVote audit invitation email failed for election ' . $election_id . '.');
                    }
                }
                $success = count($invitations) . ' Audit Committee invitation' . (count($invitations) === 1 ? ' was' : 's were') . ' sent.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

$committeeStmt = $pdo->prepare('SELECT email, status, accepted_at, confirmed_at, expires_at FROM election_committee_members WHERE election_id = ? ORDER BY created_at DESC');
$committeeStmt->execute([$election_id]);
$committeeMembers = $committeeStmt->fetchAll();
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-5xl mx-auto px-6">
        <div class="bg-white rounded-3xl shadow-xl p-10">
            <?php if ($success): ?>
                <div class="bg-green-100 text-green-800 p-4 rounded-xl mb-6"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-red-100 text-red-700 p-4 rounded-xl mb-6"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <div class="flex justify-between items-start mb-8">
                <div class="flex items-center gap-4">
                    <?php if (!empty($election['organization_logo'])): ?>
                        <img src="<?= url('/' . $election['organization_logo']) ?>" alt="" class="org-logo">
                    <?php endif; ?>
                    <div>
                        <h1 class="text-3xl font-bold"><?= htmlspecialchars($election['title']) ?></h1>
                        <p class="text-gray-600"><?= htmlspecialchars($election['organization_name']) ?></p>
                    </div>
                </div>
                <span class="bg-green-100 text-green-700 px-4 py-2 rounded-full text-sm font-medium"><?= htmlspecialchars(strtoupper($election['status'])) ?></span>
            </div>

            <div class="election-code-box mb-8">
                <span>Election Code</span>
                <strong><?= htmlspecialchars($election['code'] ?? $election['slug']) ?></strong>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-gray-50 p-4 rounded-2xl">
                    <p class="text-sm text-gray-500">Voter Validation</p>
                    <strong><?= htmlspecialchars($election['voter_verification_field'] ?: 'Eligible-voter spreadsheet') ?></strong>
                </div>
                <div class="bg-gray-50 p-4 rounded-2xl">
                    <p class="text-sm text-gray-500">Starts</p>
                    <strong><?= date('M d, Y H:i', strtotime($election['start_datetime'])) ?></strong>
                </div>
                <div class="bg-gray-50 p-4 rounded-2xl">
                    <p class="text-sm text-gray-500">Ends</p>
                    <strong><?= date('M d, Y H:i', strtotime($election['end_datetime'])) ?></strong>
                </div>
            </div>

            <section class="border-t pt-8 mb-8" aria-labelledby="audit-team-title">
                <h2 id="audit-team-title" class="text-2xl font-bold">Audit Team</h2>
                <p class="text-gray-600 mt-2">Invite 1–6 people at a time by email. They become election-specific auditors only after accepting their invitation and confirming their emailed code.</p>
                <form method="post" class="mt-5">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="invite_auditors">
                    <label class="block text-sm font-medium text-gray-700">Email addresses</label>
                    <textarea name="auditor_emails" required rows="3" class="w-full mt-2 px-4 py-3 border rounded-xl" placeholder="auditor@example.com&#10;another@example.com"></textarea>
                    <p class="text-sm text-gray-500 mt-1">Separate addresses with commas, spaces, or new lines.</p>
                    <button class="mt-4 bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-lg font-semibold">Send invitations</button>
                </form>
                <?php if ($committeeMembers): ?>
                    <div class="mt-6 overflow-x-auto"><table class="w-full"><thead class="bg-gray-100"><tr><th class="px-4 py-3 text-left">Email</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Access ends</th></tr></thead><tbody class="divide-y">
                        <?php foreach ($committeeMembers as $member): ?><tr><td class="px-4 py-3"><?= htmlspecialchars($member['email']) ?></td><td class="px-4 py-3 font-semibold"><?= htmlspecialchars(ucfirst($member['status'])) ?></td><td class="px-4 py-3 text-gray-600"><?= $member['expires_at'] ? htmlspecialchars(date('M d, Y H:i', strtotime($member['expires_at']))) : '—' ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

            <div class="flex gap-4">
                <a href="<?= url('/admin/results.php?id=' . $election['id']) ?>" class="app-button app-button-primary">View Results</a>
                <a href="<?= url('/admin/manage-candidates.php?id=' . $election['id']) ?>" class="app-button app-button-light">Manage Candidates</a>
                <a href="<?= url('/admin/tamper-report.php?id=' . $election['id']) ?>" class="app-button app-button-light">Audit Report</a>
                <a href="<?= url('/admin/elections.php') ?>" class="app-button app-button-light">All Elections</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
