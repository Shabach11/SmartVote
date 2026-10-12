<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

if (isAdmin()) {
    log_audit_event($pdo, 'admin_vote_attempt', 'Administrator attempted to submit a ballot.', null, $_SESSION['user_id']);
    http_response_code(403);
    exit('Admins are not allowed to vote in any election. Please use your account.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/vote/index.php');
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    die("Security error");
}

$error = '';
$success = '';
$action = $_POST['action'] ?? 'review';
$election_id = (int)($_POST['election_id'] ?? 0);
$voter_identifier = trim($_POST['voter_identifier'] ?? '');
$selected_ids = [];

foreach ($_POST as $key => $value) {
    if (strpos($key, 'candidate_') === 0 && $value !== '') {
        $selected_ids[(int)str_replace('candidate_', '', $key)] = (int)$value;
    }
}

function load_election_or_fail($pdo, $election_id) {
    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ? LIMIT 1");
    $stmt->execute([$election_id]);
    return $stmt->fetch();
}

function already_voted_for_identifier($pdo, $election_id, $user_id, $identifier) {
    return voter_has_completed_ballot($pdo, $election_id, $user_id, $identifier);
}

function selected_candidates($pdo, $election_id, $selected_ids) {
    if (empty($selected_ids)) {
        return [];
    }

    $rows = [];
    foreach ($selected_ids as $role_id => $candidate_id) {
        $stmt = $pdo->prepare("
            SELECT r.id AS role_id, r.role_name, c.id AS candidate_id, c.name, c.description, c.image_path
            FROM candidate_roles r
            INNER JOIN candidates c ON c.role_id = r.id
            WHERE r.election_id = ? AND r.id = ? AND c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$election_id, $role_id, $candidate_id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Invalid selection detected.");
        }

        $rows[] = $row;
    }

    return $rows;
}

$election = load_election_or_fail($pdo, $election_id);

try {
    if (!$election || $election['status'] !== 'active') {
        throw new Exception("Election not found or inactive.");
    }

    if ($voter_identifier === '') {
        throw new Exception('Please enter your ' . election_identifier_label($pdo, $election_id) . ' to continue.');
    }

    if (user_is_restricted_from_election($pdo, $election, $_SESSION['user_id'], $_SESSION['email'] ?? '')) {
        throw new Exception('Election creators and Audit Committee members cannot vote in this election.');
    }
    if (!valid_voter_for_election($pdo, $election_id, $voter_identifier, $_SESSION['email'] ?? '')) {
        log_vote_attempt($pdo, $election_id, $voter_identifier, $_SESSION['email'] ?? '', 'ineligible_voter_submit', 'blocked');
        throw new Exception('You are not on the eligible voters list for this election.');
    }

    $active_identifier = $_SESSION['active_voter_identifiers'][$election_id] ?? '';
    if (!is_string($active_identifier) || !hash_equals($active_identifier, $voter_identifier)) {
        throw new Exception("Your voting session expired. Please find the election and enter your name again.");
    }

    if (already_voted_for_identifier($pdo, $election_id, $_SESSION['user_id'], $voter_identifier)) {
        log_vote_attempt($pdo, $election_id, $voter_identifier, $_SESSION['email'] ?? '', 'revote_submit', 'blocked');
        throw new Exception("You have already voted in this election.");
    }

    $selections = selected_candidates($pdo, $election_id, $selected_ids);

    $roleStmt = $pdo->prepare('SELECT id, role_name FROM candidate_roles WHERE election_id = ? ORDER BY id');
    $roleStmt->execute([$election_id]);
    $requiredRoles = $roleStmt->fetchAll();
    $missingRoles = [];
    foreach ($requiredRoles as $role) if (!array_key_exists((int)$role['id'], $selected_ids)) $missingRoles[] = $role['role_name'];
    if ($missingRoles) throw new Exception('Select a candidate or response for every position before reviewing your vote. Missing: ' . implode(', ', $missingRoles) . '.');

    if (empty($selections)) {
        throw new Exception("Please select at least one candidate.");
    }

    if ($action === 'send_otp') {
        $_SESSION['pending_vote'] = [
            'election_id' => $election_id,
            'voter_identifier' => $voter_identifier,
            'selected_ids' => $selected_ids,
            'created_at' => time()
        ];

        create_vote_otp($pdo, $_SESSION['email'], $election_id, $voter_identifier);
        $action = 'verify_otp';
    } elseif ($action === 'submit_vote') {
        $pending = $_SESSION['pending_vote'] ?? null;
        $otp = trim($_POST['otp'] ?? '');

        if (!$pending || (int)$pending['election_id'] !== $election_id || $pending['voter_identifier'] !== $voter_identifier) {
            throw new Exception("Your voting session expired. Please review your ballot again.");
        }

        $selected_ids = $pending['selected_ids'];
        $selections = selected_candidates($pdo, $election_id, $selected_ids);

        $otp_stmt = $pdo->prepare("SELECT * FROM otp_codes
            WHERE email = ? AND election_id = ? AND voter_identifier = ? AND code = ? AND used = 0 AND expires_at >= NOW()
            ORDER BY id DESC LIMIT 1");
        $otp_stmt->execute([$_SESSION['email'], $election_id, $voter_identifier, $otp]);
        $otp_row = $otp_stmt->fetch();

        if (!$otp_row) {
            log_vote_attempt($pdo, $election_id, $voter_identifier, $_SESSION['email'] ?? '', 'invalid_otp', 'blocked');
            throw new Exception("Invalid or expired OTP.");
        }

        $pdo->beginTransaction();

        // This insert is the database-level double-vote lock. The unique keys protect against
        // simultaneous requests even if both requests passed the earlier availability check.
        $receipt = $pdo->prepare('INSERT INTO vote_receipts (election_id, user_id, voter_identifier) VALUES (?, ?, ?)');
        $receipt->execute([$election_id, $_SESSION['user_id'], $voter_identifier]);

        foreach ($selections as $selection) {
            $stmt = $pdo->prepare("INSERT INTO votes
                (election_id, user_id, role_id, voter_identifier, email, candidate_id)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $election_id,
                $_SESSION['user_id'],
                $selection['role_id'],
                $voter_identifier,
                $_SESSION['email'],
                $selection['candidate_id']
            ]);

            $update = $pdo->prepare("UPDATE candidates SET votes = votes + 1 WHERE id = ?");
            $update->execute([$selection['candidate_id']]);
        }

        $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?")->execute([$otp_row['id']]);
        log_vote_attempt($pdo, $election_id, $voter_identifier, $_SESSION['email'] ?? '', 'vote_submitted', 'success');
        $pdo->commit();

        unset($_SESSION['pending_vote'], $_SESSION['last_dev_otp']);
        $success = "Your vote has been recorded successfully.";
        $action = 'success';
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        log_vote_attempt($pdo, $election_id, $voter_identifier, $_SESSION['email'] ?? '', 'revote_race_blocked', 'blocked');
        $error = 'You have already voted in this election.';
    } else {
        $error = $e->getMessage();
    }
}

$results = [];
if ($election) {
    $roles_stmt = $pdo->prepare("SELECT * FROM candidate_roles WHERE election_id = ? ORDER BY id ASC");
    $roles_stmt->execute([$election_id]);
    foreach ($roles_stmt->fetchAll() as $role) {
        $cand_stmt = $pdo->prepare("SELECT * FROM candidates WHERE role_id = ? ORDER BY votes DESC, name ASC");
        $cand_stmt->execute([$role['id']]);
        $role['candidates'] = $cand_stmt->fetchAll();
        $results[] = $role;
    }
}
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50 py-12">
    <div class="max-w-4xl mx-auto px-6">
        <div class="bg-white rounded-3xl shadow-xl p-10">
            <?php if ($error): ?>
                <h1 class="text-3xl font-bold text-red-600 mb-4">Unable to Submit Vote</h1>
                <p class="text-gray-600 mb-8"><?= htmlspecialchars($error) ?></p>
                <a href="<?= url('/vote/index.php') ?>" class="inline-block bg-blue-600 text-white px-8 py-3 rounded-xl font-semibold">Back to Voting</a>
            <?php elseif ($action === 'verify_otp'): ?>
                <h1 class="text-3xl font-bold mb-3">Enter OTP</h1>
                <p class="text-gray-600 mb-6">A 6-digit code was sent to <?= htmlspecialchars($_SESSION['email']) ?>.</p>
                <?php if (!empty($_SESSION['last_dev_otp'])): ?>
                    <div class="bg-gray-100 text-gray-700 p-4 rounded-xl mb-6">
                        Local mail is not configured. Development OTP: <strong><?= htmlspecialchars($_SESSION['last_dev_otp']) ?></strong>
                    </div>
                <?php endif; ?>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="election_id" value="<?= $election_id ?>">
                    <input type="hidden" name="voter_identifier" value="<?= htmlspecialchars($voter_identifier) ?>">
                    <input type="hidden" name="action" value="submit_vote">
                    <?php foreach ($selected_ids as $role_id => $candidate_id): ?>
                        <input type="hidden" name="candidate_<?= (int)$role_id ?>" value="<?= (int)$candidate_id ?>">
                    <?php endforeach; ?>
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" required class="w-full px-6 py-5 text-lg border rounded-2xl" placeholder="6-digit OTP">
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-5 rounded-2xl text-xl">Verify and Submit Vote</button>
                </form>
            <?php elseif ($action === 'success'): ?>
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-green-600 mb-3">Vote Submitted</h1>
                        <p class="text-gray-600"><?= htmlspecialchars($success) ?></p>
                    </div>
                    <div class="date-card">
                        <span>Time left</span>
                        <strong data-countdown="<?= htmlspecialchars($election['end_datetime']) ?>">Calculating...</strong>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="date-card">
                        <span>Election starts</span>
                        <strong><?= date('M d, Y H:i', strtotime($election['start_datetime'])) ?></strong>
                    </div>
                    <div class="date-card">
                        <span>Election ends</span>
                        <strong><?= date('M d, Y H:i', strtotime($election['end_datetime'])) ?></strong>
                    </div>
                </div>
                <p class="bg-blue-50 text-blue-800 p-4 rounded-xl">Your ballot is recorded. Results will be declared after the election ends.</p>
                <a href="<?= url('/index.php') ?>" class="inline-block bg-blue-600 text-white px-8 py-3 rounded-xl font-semibold">Back to Home</a>
            <?php else: ?>
                <h1 class="text-3xl font-bold mb-3">Review Your Vote</h1>
                <p class="text-gray-600 mb-8">Check your selections before OTP verification.</p>
                <?php foreach ($selections as $selection): ?>
                    <div class="flex items-center gap-4 bg-gray-50 rounded-2xl p-4 mb-4">
                        <?php if (!empty($selection['image_path'])): ?>
                            <img src="<?= url('/' . $selection['image_path']) ?>" alt="" class="candidate-photo-small">
                        <?php endif; ?>
                        <div>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($selection['role_name']) ?></p>
                            <h2 class="text-xl font-bold"><?= htmlspecialchars($selection['name']) ?></h2>
                        </div>
                    </div>
                <?php endforeach; ?>
                <form method="POST" class="mt-8">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="election_id" value="<?= $election_id ?>">
                    <input type="hidden" name="voter_identifier" value="<?= htmlspecialchars($voter_identifier) ?>">
                    <input type="hidden" name="action" value="send_otp">
                    <?php foreach ($selected_ids as $role_id => $candidate_id): ?>
                        <input type="hidden" name="candidate_<?= (int)$role_id ?>" value="<?= (int)$candidate_id ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-5 rounded-2xl text-xl">Confirm and Send OTP</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const countdown = document.querySelector('[data-countdown]');
    if (!countdown) {
        return;
    }

    function render() {
        const end = new Date(countdown.dataset.countdown).getTime();
        const remaining = Math.max(0, end - Date.now());
        const days = Math.floor(remaining / 86400000);
        const hours = Math.floor((remaining % 86400000) / 3600000);
        const minutes = Math.floor((remaining % 3600000) / 60000);
        countdown.textContent = `${days}d ${hours}h ${minutes}m`;
    }

    render();
    setInterval(render, 60000);
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
