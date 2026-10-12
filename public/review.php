<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_election_review') {
    $electionId = (int)($_POST['election_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Your session security token is invalid. Please try again.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating from 1 to 5 stars.';
    } elseif ($comment === '' || mb_strlen($comment) > 2000) {
        $error = 'Please enter feedback of up to 2,000 characters.';
    } else {
        $eligible = $pdo->prepare('SELECT e.id FROM elections e INNER JOIN vote_receipts vr ON vr.election_id = e.id WHERE e.id = ? AND vr.user_id = ? AND e.end_datetime <= NOW() LIMIT 1');
        $eligible->execute([$electionId, $_SESSION['user_id']]);
        if (!$eligible->fetch()) {
            $error = 'Reviews are available only to participants after their election has ended.';
        } else {
            try {
                $insert = $pdo->prepare('INSERT INTO election_reviews (user_id, election_id, rating, comment) VALUES (?, ?, ?, ?)');
                $insert->execute([$_SESSION['user_id'], $electionId, $rating, $comment]);
                log_audit_event($pdo, 'election_review_submitted', 'Participant submitted election feedback.', $electionId, $_SESSION['user_id']);
                $success = 'Thank you—your feedback has been submitted.';
            } catch (PDOException $e) {
                $error = $e->getCode() === '23000' ? 'You have already submitted feedback for this election.' : 'Your feedback could not be saved. Please try again.';
            }
        }
    }
}

$statement = $pdo->prepare('SELECT e.id, e.title, e.organization_name, e.end_datetime
    FROM elections e
    INNER JOIN vote_receipts vr ON vr.election_id = e.id AND vr.user_id = ?
    LEFT JOIN election_reviews er ON er.election_id = e.id AND er.user_id = vr.user_id
    WHERE e.end_datetime <= NOW() AND er.id IS NULL
    ORDER BY e.end_datetime DESC');
$statement->execute([$_SESSION['user_id']]);
$elections = $statement->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-5xl mx-auto px-6">
        <div class="mb-8">
            <p class="landing-kicker">Your voice matters</p>
            <h1 class="text-4xl font-bold text-gray-900">Drop a Review</h1>
            <p class="text-gray-600 mt-2">Rate and comment on an election you participated in after it has ended.</p>
        </div>
        <?php if ($success): ?><div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-green-800"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-red-800"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!$elections): ?>
            <section class="bg-white rounded-2xl shadow border border-gray-100 p-10 text-center"><i class="bi bi-chat-square-heart text-4xl text-blue-600"></i><h2 class="text-xl font-semibold mt-4">No reviews available yet</h2><p class="text-gray-600 mt-2">You can leave a review after an election you voted in has ended.</p></section>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($elections as $election): ?>
                    <article class="review-card"><h2><?= htmlspecialchars($election['title']) ?></h2><p class="review-election-meta"><?= htmlspecialchars($election['organization_name']) ?> &middot; Ended <?= htmlspecialchars(date('M d, Y', strtotime($election['end_datetime']))) ?></p>
                        <form method="POST" class="mt-5"><input type="hidden" name="action" value="submit_election_review"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="election_id" value="<?= (int)$election['id'] ?>">
                            <fieldset class="star-rating"><legend>Rate this election <span aria-hidden="true">(1 to 5 stars)</span></legend><div class="star-rating-options"><?php for ($star = 5; $star >= 1; $star--): ?><input id="rating-<?= (int)$election['id'] ?>-<?= $star ?>" type="radio" name="rating" value="<?= $star ?>" required><label for="rating-<?= (int)$election['id'] ?>-<?= $star ?>" title="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>"><span class="sr-only"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></span><i class="bi bi-star-fill" aria-hidden="true"></i></label><?php endfor; ?></div></fieldset>
                            <label class="review-comment-label" for="comment-<?= (int)$election['id'] ?>">Your feedback</label><textarea id="comment-<?= (int)$election['id'] ?>" name="comment" rows="4" maxlength="2000" required placeholder="Tell us about your voting experience..."></textarea><button type="submit" class="app-button app-button-primary mt-4">Submit Review</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
