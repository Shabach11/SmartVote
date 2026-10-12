<?php
require_once __DIR__ . '/../config.php';

$token = trim($_REQUEST['token'] ?? '');
$error = '';
$success = '';
$invitation = null;

function find_audit_invitation($pdo, $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare("SELECT m.*, e.title, e.organization_name, e.end_datetime
        FROM election_committee_members m
        INNER JOIN elections e ON e.id = m.election_id
        WHERE m.invitation_token_hash = ? AND m.expires_at >= NOW() LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch();
}

$invitation = find_audit_invitation($pdo, $token);
if (!$invitation) {
    $error = 'This Audit Committee invitation is invalid or has expired.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'accept') {
        if ($invitation['status'] === 'active') {
            $success = 'This invitation has already been confirmed. Sign in to view the election audit information.';
        } elseif (!in_array($invitation['status'], ['pending', 'accepted'], true)) {
            $error = 'This invitation is no longer available.';
        } else {
            $code = (string)random_int(100000, 999999);
            $update = $pdo->prepare("UPDATE election_committee_members
                SET status = 'accepted', accepted_at = COALESCE(accepted_at, NOW()), confirmation_code_hash = ?,
                    confirmation_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                WHERE id = ?");
            $update->execute([hash('sha256', $code), $invitation['id']]);
            $sent = send_smartvote_email(
                $invitation['email'],
                'Your SmartVote Audit Committee confirmation code',
                "Your confirmation code for \"{$invitation['title']}\" is {$code}. It expires in 10 minutes.\n\n"
                . 'Return to the invitation page and enter this code to activate your auditor access.'
            );
            if ($sent) {
                $success = 'A confirmation code has been sent to your email address.';
            } else {
                error_log('SmartVote audit confirmation email failed for invitation ' . $invitation['id'] . '.');
                $error = 'We could not send the confirmation code. Please try accepting the invitation again.';
            }
            $invitation = find_audit_invitation($pdo, $token);
        }
    } elseif (($_POST['action'] ?? '') === 'confirm') {
        $code = trim($_POST['confirmation_code'] ?? '');
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the six-digit confirmation code.';
        } else {
            $user = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $user->execute([$invitation['email']]);
            $userId = $user->fetchColumn() ?: null;
            $update = $pdo->prepare("UPDATE election_committee_members
                SET status = 'active', user_id = ?, confirmed_at = NOW(), confirmation_code_hash = NULL,
                    confirmation_expires_at = NULL
                WHERE id = ? AND status = 'accepted' AND confirmation_expires_at >= NOW()
                    AND confirmation_code_hash = ?");
            $update->execute([$userId, $invitation['id'], hash('sha256', $code)]);
            if ($update->rowCount()) {
                $success = 'Your Audit Committee access is active. Sign in with this email address to view this election\'s audit information.';
                $invitation = find_audit_invitation($pdo, $token);
            } else {
                $error = 'That confirmation code is invalid or has expired.';
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<main class="min-h-screen bg-gray-50 py-12 px-4">
    <div class="max-w-md mx-auto bg-white rounded-2xl shadow p-8">
        <div class="icon-badge green"><i class="bi bi-shield-check"></i></div>
        <h1 class="text-3xl font-bold">Audit Committee invitation</h1>
        <?php if ($invitation): ?>
            <p class="text-gray-600 mt-2">You were invited to audit <strong><?= htmlspecialchars($invitation['title']) ?></strong> for <?= htmlspecialchars($invitation['organization_name']) ?>.</p>
        <?php endif; ?>
        <?php if ($error): ?><p class="bg-red-100 text-red-700 rounded-lg p-4 mt-6"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($success): ?><p class="bg-green-100 text-green-700 rounded-lg p-4 mt-6"><?= htmlspecialchars($success) ?></p><?php endif; ?>

        <?php if ($invitation && $invitation['status'] === 'pending'): ?>
            <form method="post" class="mt-6"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="action" value="accept"><button class="w-full bg-blue-600 text-white font-semibold py-3 rounded-lg">Accept invitation</button></form>
        <?php elseif ($invitation && $invitation['status'] === 'accepted'): ?>
            <form method="post" class="mt-6 space-y-4"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="action" value="confirm"><div><label class="block text-sm font-medium text-gray-700">Confirmation code</label><input name="confirmation_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required class="w-full mt-2 px-4 py-3 border rounded-lg" placeholder="6-digit code"></div><button class="w-full bg-blue-600 text-white font-semibold py-3 rounded-lg">Confirm access</button></form>
            <form method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="action" value="accept"><button class="text-blue-600 text-sm font-semibold hover:underline">Resend confirmation code</button></form>
        <?php elseif ($invitation && $invitation['status'] === 'active'): ?>
            <a href="<?= url('/login.php') ?>" class="inline-block mt-6 text-blue-600 font-semibold hover:underline">Sign in to SmartVote</a>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
