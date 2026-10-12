<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

if (isAdmin()) {
    // Find Election is a management view for administrators; it only lists their own elections.
    redirect('/admin/elections.php');
}

$error = '';
$step = 'lookup';
$election = null;
$candidate_roles = [];
$voter_identifier = '';

function load_candidate_roles_with_candidates($pdo, $election_id) {
    $stmt = $pdo->prepare("SELECT * FROM candidate_roles WHERE election_id = ? ORDER BY id ASC");
    $stmt->execute([$election_id]);
    $roles = $stmt->fetchAll();

    foreach ($roles as &$role) {
        $candidate_stmt = $pdo->prepare("SELECT * FROM candidates WHERE role_id = ? ORDER BY id ASC");
        $candidate_stmt->execute([$role['id']]);
        $role['candidates'] = $candidate_stmt->fetchAll();
    }

    return $roles;
}

function voter_has_voted($pdo, $election_id, $user_id, $identifier) {
    return voter_has_completed_ballot($pdo, $election_id, $user_id, $identifier);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed.";
    } elseif (($_POST['action'] ?? '') === 'search') {
        $organization_name = trim($_POST['organization_name'] ?? '');
        $election_code = strtoupper(trim($_POST['election_code'] ?? ''));

        if ($organization_name === '' || $election_code === '') {
            $error = "Enter both organization name and election code.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM elections
                WHERE code = ? AND organization_name LIKE ?
                ORDER BY id DESC LIMIT 1");
            $stmt->execute([$election_code, '%' . $organization_name . '%']);
            $election = $stmt->fetch();

            if (!$election) {
                log_vote_attempt($pdo, null, '', $_SESSION['email'] ?? '', 'failed_election_search', 'blocked');
                $error = "No election matched that organization and code.";
            } elseif ($election['status'] !== 'active') {
                $error = "This election is not active.";
            } else {
                $_SESSION['found_election_id'] = $election['id'];
                $step = 'election';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'identify') {
        $election_id = (int)($_POST['election_id'] ?? 0);
        $voter_identifier = trim($_POST['voter_identifier'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ? LIMIT 1");
        $stmt->execute([$election_id]);
        $election = $stmt->fetch();

        if (!$election || $election['status'] !== 'active') {
            $error = "Election not found or inactive.";
        } elseif ($voter_identifier === '') {
            $error = 'Please enter your ' . election_identifier_label($pdo, $election_id) . ' to continue.';
            $step = 'election';
        } elseif (user_is_restricted_from_election($pdo, $election, $_SESSION['user_id'], $_SESSION['email'] ?? '')) {
            $error = 'Election creators and Audit Committee members cannot vote in this election.';
            $step = 'election';
        } elseif (!valid_voter_for_election($pdo, $election['id'], $voter_identifier, $_SESSION['email'] ?? '')) {
            log_vote_attempt($pdo, $election['id'], $voter_identifier, $_SESSION['email'] ?? '', 'ineligible_voter_lookup', 'blocked');
            $error = 'You are not on the eligible voters list for this election.';
            $step = 'election';
        } elseif (voter_has_voted($pdo, $election['id'], $_SESSION['user_id'], $voter_identifier)) {
            log_vote_attempt($pdo, $election['id'], $voter_identifier, $_SESSION['email'] ?? '', 'revote_lookup', 'blocked');
            $error = "You have already voted in this election.";
            $step = 'election';
        } else {
            $_SESSION['active_voter_identifiers'][$election['id']] = $voter_identifier;
            $candidate_roles = load_candidate_roles_with_candidates($pdo, $election['id']);
            $step = 'ballot';
        }
    }
}

if ($step === 'lookup' && isset($_GET['election_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['election_id']]);
    $election = $stmt->fetch();
    if ($election) {
        $step = 'election';
    }
}
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<main class="vote-page min-h-screen">
    <div class="vote-shell">
        <?php if ($election): ?>
            <section class="vote-election-ticket" aria-label="Election information">
                <div class="vote-ticket-item">
                    <span class="vote-ticket-label">Opens</span>
                    <strong><?= date('M d, Y H:i', strtotime($election['start_datetime'])) ?></strong>
                </div>
                <div class="vote-ticket-item">
                    <span class="vote-ticket-label">Closes</span>
                    <strong><?= date('M d, Y H:i', strtotime($election['end_datetime'])) ?></strong>
                </div>
                <div class="vote-ticket-item vote-ticket-time">
                    <span class="vote-ticket-label">Time left to vote</span>
                    <strong data-countdown="<?= htmlspecialchars($election['end_datetime']) ?>">Calculating...</strong>
                    <span class="vote-time-track" aria-hidden="true"><i></i></span>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($step === 'lookup'): ?>
            <section class="vote-entry-card bg-white rounded-3xl shadow-xl p-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-900">Find Election</h1>
                    <p class="text-gray-600 mt-2">Enter the organization name and 7-character election code.</p>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-100 text-red-700 p-4 rounded-xl mb-6"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="search">
                    <input type="text" name="organization_name" placeholder="Company or organization name" class="w-full px-4 py-3 border rounded-xl" required autofocus>
                    <input type="text" name="election_code" maxlength="7" placeholder="Election code" class="w-full px-4 py-3 border rounded-xl" required>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl">Find Election</button>
                </form>
            </section>

        <?php elseif ($step === 'election'): ?>
            <section class="vote-entry-card bg-white rounded-3xl shadow-xl p-10">
                <?php if ($error): ?>
                    <div class="bg-red-100 text-red-700 p-4 rounded-xl mb-6"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="flex items-center gap-4 mb-8">
                    <?php if (!empty($election['organization_logo'])): ?>
                        <img src="<?= url('/' . $election['organization_logo']) ?>" alt="" class="org-logo">
                    <?php endif; ?>
                    <div>
                        <h1 class="text-3xl font-bold"><?= htmlspecialchars($election['title']) ?></h1>
                        <p class="text-gray-600"><?= htmlspecialchars($election['organization_name']) ?> &middot; Code <?= htmlspecialchars($election['code']) ?></p>
                    </div>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="identify">
                    <input type="hidden" name="election_id" value="<?= (int)$election['id'] ?>">
                    <?php $identifier_label = election_identifier_label($pdo, $election['id']); ?>
                    <div>
                        <label class="block mb-2" for="voter_identifier">Your <?= htmlspecialchars($identifier_label) ?></label>
                        <p id="verification-instruction" class="bg-blue-50 text-blue-900 p-4 rounded-xl mb-3">Please enter your <?= htmlspecialchars($identifier_label) ?> to continue.</p>
                        <input id="voter_identifier" type="text" name="voter_identifier" value="<?= htmlspecialchars($voter_identifier) ?>" maxlength="190" autocomplete="off" aria-describedby="verification-instruction" placeholder="Enter your <?= htmlspecialchars($identifier_label) ?>" class="w-full px-4 py-3 border rounded-xl" required autofocus>
                        <p class="text-sm text-gray-500 mt-2">Enter it exactly as it appears on the eligible-voter list. This verification is required before you can access the ballot.</p>
                    </div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-xl">Continue to Ballot</button>
                </form>
            </section>

        <?php else: ?>
            <?php $role_count = count($candidate_roles); ?>
            <nav class="vote-steps" aria-label="Voting progress">
                <div class="vote-step is-current"><span>1</span><b>Select</b></div>
                <i aria-hidden="true"></i>
                <div class="vote-step"><span>2</span><b>Review</b></div>
                <i aria-hidden="true"></i>
                <div class="vote-step"><span>3</span><b>Confirm</b></div>
            </nav>

            <section class="vote-ballot-card" aria-labelledby="ballot-title">
                <header class="vote-ballot-header">
                    <div>
                        <p class="vote-eyebrow">Your secure ballot</p>
                        <h1 id="ballot-title"><?= htmlspecialchars($election['title']) ?></h1>
                        <p class="vote-election-meta"><?= htmlspecialchars($election['organization_name']) ?> <span aria-hidden="true">&middot;</span> Election code <strong><?= htmlspecialchars($election['code']) ?></strong></p>
                        <p class="vote-voter">Voting as <strong><?= htmlspecialchars($voter_identifier) ?></strong></p>
                    </div>
                    <span class="vote-otp-badge">OTP required to submit</span>
                </header>

                <aside class="vote-checklist" aria-labelledby="vote-checklist-title">
                    <h2 id="vote-checklist-title">Before you vote</h2>
                    <p class="vote-check is-done"><span aria-hidden="true">✓</span>Your voter identity has been verified.</p>
                    <p class="vote-check is-done"><span aria-hidden="true">✓</span>You are voting with your validated account.</p>
                    <p class="vote-check" data-selection-check><span aria-hidden="true"></span>Select one candidate or response for every position.</p>
                    <p class="vote-check"><span aria-hidden="true"></span>Review and confirm with a one-time code.</p>
                </aside>

                <form method="POST" action="cast.php" class="vote-ballot-form" id="vote-ballot-form">
                    <input type="hidden" name="election_id" value="<?= (int)$election['id'] ?>">
                    <input type="hidden" name="voter_identifier" value="<?= htmlspecialchars($voter_identifier) ?>">
                    <input type="hidden" name="action" value="review">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <?php foreach ($candidate_roles as $role): ?>
                        <fieldset class="vote-role">
                            <legend class="vote-role-heading">
                                <span><?= htmlspecialchars($role['role_name']) ?></span>
                                <small data-role-status>Select 1 option</small>
                            </legend>
                            <?php if (($role['role_type'] ?? 'multiple') === 'yesno' && !empty($role['proposition_name'])): ?>
                                <div class="vote-proposition">
                                    <?php if (!empty($role['proposition_image_path'])): ?>
                                        <img src="<?= url('/' . $role['proposition_image_path']) ?>" alt="" class="candidate-photo mb-4">
                                    <?php endif; ?>
                                    <h3 class="font-bold text-xl"><?= htmlspecialchars($role['proposition_name']) ?></h3>
                                    <p class="text-gray-700 mt-2"><?= htmlspecialchars($role['proposition_description']) ?></p>
                                    <p class="text-sm font-medium mt-3">Choose Yes to approve or No to reject this proposition.</p>
                                </div>
                            <?php endif; ?>
                            <div class="vote-candidate-grid">
                                <?php foreach ($role['candidates'] as $candidate): ?>
                                    <label class="vote-candidate-card">
                                        <input type="radio" name="candidate_<?= (int)$role['id'] ?>" value="<?= (int)$candidate['id'] ?>" required>
                                        <span class="vote-selection-mark" aria-hidden="true">✓</span>
                                        <span class="vote-candidate-content">
                                            <?php if (!empty($candidate['image_path'])): ?>
                                                <img src="<?= url('/' . $candidate['image_path']) ?>" alt="" class="candidate-photo mb-4">
                                            <?php endif; ?>
                                            <h3 class="font-bold text-xl"><?= htmlspecialchars($candidate['name']) ?></h3>
                                            <?php if (!empty($candidate['description'])): ?>
                                                <p class="text-gray-600 mt-2"><?= htmlspecialchars($candidate['description']) ?></p>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>

                    <div class="vote-review-bar" aria-live="polite">
                        <p data-review-status>No selections made yet <span>&middot;</span> Complete all <?= $role_count ?> <?= $role_count === 1 ? 'position' : 'positions' ?> to continue</p>
                        <button type="submit" class="vote-review-button" disabled>Review My Vote <span aria-hidden="true">→</span></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</main>

<script>
(function () {
    const countdown = document.querySelector('[data-countdown]');
    if (!countdown) {
        return;
    }

    function render() {
        const remaining = Math.max(0, new Date(countdown.dataset.countdown).getTime() - Date.now());
        const days = Math.floor(remaining / 86400000);
        const hours = Math.floor((remaining % 86400000) / 3600000);
        const minutes = Math.floor((remaining % 3600000) / 60000);
        countdown.textContent = `${days}d ${hours}h ${minutes}m`;
    }

    render();
    setInterval(render, 60000);
})();

(function () {
    const form = document.getElementById('vote-ballot-form');
    if (!form) return;

    const roles = Array.from(form.querySelectorAll('.vote-role'));
    const button = form.querySelector('.vote-review-button');
    const status = form.querySelector('[data-review-status]');
    const checklist = document.querySelector('[data-selection-check]');

    function updateBallotState() {
        const complete = roles.filter(role => role.querySelector('input[type="radio"]:checked'));
        roles.forEach(role => {
            const selected = role.querySelector('input[type="radio"]:checked');
            role.classList.toggle('is-complete', Boolean(selected));
            const hint = role.querySelector('[data-role-status]');
            if (hint) hint.textContent = selected ? 'Selection made' : 'Select 1 option';
        });
        const isComplete = complete.length === roles.length && roles.length > 0;
        button.disabled = !isComplete;
        if (status) status.innerHTML = isComplete
            ? '<strong>All selections are ready to review.</strong>'
            : `${complete.length} of ${roles.length} positions selected`;
        if (checklist) checklist.classList.toggle('is-done', isComplete);
    }

    form.addEventListener('change', updateBallotState);
    updateBallotState();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
