<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$logged_in = isLoggedIn();
$is_admin = isAdmin();
$is_normal_user = $logged_in
    && !$is_admin
    && !has_approved_creation_request($pdo, $_SESSION['user_id'])
    && !has_active_subadmin_access($pdo, $_SESSION['user_id']);
$home_image_dir = __DIR__ . '/assets/images/home-page_images';
$home_image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
$home_images = is_dir($home_image_dir) ? array_values(array_filter(glob($home_image_dir . '/*') ?: [], function ($image) use ($home_image_extensions) {
    return is_file($image) && in_array(strtolower(pathinfo($image, PATHINFO_EXTENSION)), $home_image_extensions, true);
})) : [];
$review_error = '';
$review_success = '';

if ($logged_in && !isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_election_review') {
    $election_id = (int)($_POST['election_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $review_error = 'Your session security token is invalid. Please try again.';
    } elseif ($rating < 1 || $rating > 5) {
        $review_error = 'Please select a rating from 1 to 5 stars.';
    } elseif ($comment === '' || mb_strlen($comment) > 2000) {
        $review_error = 'Please enter feedback of up to 2,000 characters.';
    } else {
        $eligibleReview = $pdo->prepare('SELECT e.id FROM elections e INNER JOIN vote_receipts vr ON vr.election_id = e.id WHERE e.id = ? AND vr.user_id = ? AND e.end_datetime <= NOW() LIMIT 1');
        $eligibleReview->execute([$election_id, $_SESSION['user_id']]);
        if (!$eligibleReview->fetch()) {
            $review_error = 'Reviews are available only to participants after their election has ended.';
        } else {
            try {
                $insertReview = $pdo->prepare('INSERT INTO election_reviews (user_id, election_id, rating, comment) VALUES (?, ?, ?, ?)');
                $insertReview->execute([$_SESSION['user_id'], $election_id, $rating, $comment]);
                log_audit_event($pdo, 'election_review_submitted', 'Participant submitted election feedback.', $election_id, $_SESSION['user_id']);
                $review_success = 'Thank you—your feedback has been submitted.';
            } catch (PDOException $e) {
                $review_error = $e->getCode() === '23000' ? 'You have already submitted feedback for this election.' : 'Your feedback could not be saved. Please try again.';
            }
        }
    }
}

$review_elections = [];
if ($logged_in && !isAdmin()) {
    $reviewStmt = $pdo->prepare('SELECT e.id, e.title, e.organization_name, e.end_datetime
        FROM elections e
        INNER JOIN vote_receipts vr ON vr.election_id = e.id AND vr.user_id = ?
        LEFT JOIN election_reviews er ON er.election_id = e.id AND er.user_id = vr.user_id
        WHERE e.end_datetime <= NOW() AND er.id IS NULL
        ORDER BY e.end_datetime DESC');
    $reviewStmt->execute([$_SESSION['user_id']]);
    $review_elections = $reviewStmt->fetchAll();
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50">
    <section class="landing-hero">
        <div class="landing-hero-copy">
            <p class="landing-kicker">Secure &bull; Transparent &bull; Tamper-Proof Elections</p>
            <h1>Smart voting for organizations.</h1>
            <p class="landing-lead">Create your account, find your election, review your ballot, and submit it once.</p>
            <p class="landing-note">
                Your ballot is protected with email verification, one-vote safeguards, and a complete election audit trail.
            </p>

            <div class="landing-actions">
                <?php if ($logged_in): ?>
                    <a href="<?= url('/vote/index.php') ?>" class="app-button app-button-primary">Find Election</a>
                    <?php if ($is_admin): ?>
                        <a href="<?= url('/admin/create-election.php') ?>" class="app-button app-button-light">Create Election</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="<?= url('/login.php') ?>" class="app-button app-button-primary">Sign In</a>
                    <a href="<?= url('/register.php') ?>" class="app-button app-button-light">Register</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="home-carousel" data-carousel aria-label="SmartVote highlights">
            <?php if ($home_images): ?>
                <div class="home-carousel-track">
                    <?php foreach ($home_images as $index => $image): ?>
                        <?php $image_name = basename($image); ?>
                        <img src="<?= url('/assets/images/home-page_images/' . rawurlencode($image_name)) ?>" alt="SmartVote election experience" class="home-carousel-slide<?= $index === 0 ? ' is-active' : '' ?>"<?= $index === 0 ? '' : ' aria-hidden="true"' ?>>
                    <?php endforeach; ?>
                </div>
                <?php if (count($home_images) > 1): ?>
                    <div class="home-carousel-dots" role="tablist" aria-label="Choose slide">
                        <?php foreach ($home_images as $index => $image): ?>
                            <button type="button" class="home-carousel-dot<?= $index === 0 ? ' is-active' : '' ?>" data-carousel-dot="<?= $index ?>" aria-label="Show slide <?= $index + 1 ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="home-carousel-empty"><i class="bi bi-images"></i><span>Election images will appear here.</span></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="max-w-7xl mx-auto px-6 py-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-8 rounded-2xl shadow border border-gray-100">
                <h3 class="text-xl font-semibold mb-2">1. Find your election</h3>
                <p class="text-gray-600">Create an account or sign in, then select Find Election and enter the organization name and election code shared by your organizer.</p>
            </div>

            <div class="bg-white p-8 rounded-2xl shadow border border-gray-100">
                <h3 class="text-xl font-semibold mb-2">2. Confirm your details</h3>
                <p class="text-gray-600">Enter your full name or means of identification, open the ballot, and take a moment to review every position and candidate before continuing.</p>
            </div>

            <div class="bg-white p-8 rounded-2xl shadow border border-gray-100">
                <h3 class="text-xl font-semibold mb-2">3. Cast and confirm</h3>
                <p class="text-gray-600">Submit your choices, verify the email code, and receive confirmation. Your vote is recorded once and included in the election audit trail.</p>
            </div>

            <?php if ($logged_in && !$is_admin): ?>
            <a href="<?= url('/review.php') ?>" class="bg-white p-8 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <h3 class="text-xl font-semibold mb-2">Drop a Review</h3>
                <p class="text-gray-600">Rate and comment on an election you participated in.</p>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($logged_in && !isAdmin()): ?>
            <?php if ($review_success): ?><div class="mt-8 rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-green-800"><?= htmlspecialchars($review_success) ?></div><?php endif; ?>
            <?php if ($review_error): ?><div class="mt-8 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-red-800"><?= htmlspecialchars($review_error) ?></div><?php endif; ?>
            <?php if ($review_elections): ?>
                <section class="review-panel" aria-labelledby="review-heading">
                    <div class="review-panel-heading">
                        <p class="landing-kicker">Your voice matters</p>
                        <h2 id="review-heading">Review your election experience</h2>
                        <p>You participated in the election<?= count($review_elections) > 1 ? 's' : '' ?> below. Please share a rating and a short comment to help improve SmartVote.</p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($review_elections as $review_election): ?>
                            <article class="review-card">
                                <h3><?= htmlspecialchars($review_election['title']) ?></h3>
                                <p class="review-election-meta"><?= htmlspecialchars($review_election['organization_name']) ?> &middot; Ended <?= htmlspecialchars(date('M d, Y', strtotime($review_election['end_datetime']))) ?></p>
                                <form method="POST" class="mt-5">
                                    <input type="hidden" name="action" value="submit_election_review">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="election_id" value="<?= (int)$review_election['id'] ?>">
                                    <fieldset class="star-rating">
                                        <legend>Rate this election <span aria-hidden="true">(1 to 5 stars)</span></legend>
                                        <div class="star-rating-options">
                                            <?php for ($star = 5; $star >= 1; $star--): ?>
                                                <input id="rating-<?= (int)$review_election['id'] ?>-<?= $star ?>" type="radio" name="rating" value="<?= $star ?>" required>
                                                <label for="rating-<?= (int)$review_election['id'] ?>-<?= $star ?>" title="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>"><span class="sr-only"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></span><i class="bi bi-star-fill" aria-hidden="true"></i></label>
                                            <?php endfor; ?>
                                        </div>
                                    </fieldset>
                                    <label class="review-comment-label" for="comment-<?= (int)$review_election['id'] ?>">Your feedback</label>
                                    <textarea id="comment-<?= (int)$review_election['id'] ?>" name="comment" rows="4" maxlength="2000" required placeholder="Tell us about your voting experience..."></textarea>
                                    <button type="submit" class="app-button app-button-primary mt-4">Submit Review</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($is_normal_user): ?>
            <section class="home-instructions" aria-labelledby="election-access-guidance">
                <div class="home-instructions-heading">
                    <p class="landing-kicker">Election organizers</p>
                    <h2 id="election-access-guidance">Create elections for your organization</h2>
                    <p>Follow these steps to request access and understand how long it remains available.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <article class="home-instruction-card">
                        <div class="icon-badge"><i class="bi bi-send-check"></i></div>
                        <h3>How to Request to Create an Election</h3>
                        <ol>
                            <li>Click <strong>Create an Election</strong> in the toolbar.</li>
                            <li>Complete the request form with your details and organization information.</li>
                            <li>Submit your request for review by an Administrator.</li>
                            <li>You will receive an Approved or Declined response within 24&ndash;48 hours.</li>
                        </ol>
                    </article>

                    <article class="home-instruction-card">
                        <div class="icon-badge green"><i class="bi bi-calendar2-check"></i></div>
                        <h3>What Happens After Approval</h3>
                        <ul>
                            <li>You receive temporary permission to create elections.</li>
                            <li>The Create Election page becomes available to you.</li>
                            <li>You can create elections for your organization.</li>
                            <li>Your access expires 48 hours after your election ends.</li>
                        </ul>
                    </article>
                </div>
            </section>
        <?php endif; ?>
    </section>
</div>

<script>
document.querySelectorAll('[data-carousel]').forEach((carousel) => {
    const slides = [...carousel.querySelectorAll('.home-carousel-slide')];
    const dots = [...carousel.querySelectorAll('[data-carousel-dot]')];
    if (slides.length < 2) return;
    let activeIndex = 0;
    let timer;
    const showSlide = (index) => {
        activeIndex = (index + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => { const active = slideIndex === activeIndex; slide.classList.toggle('is-active', active); slide.setAttribute('aria-hidden', String(!active)); });
        dots.forEach((dot, dotIndex) => { const active = dotIndex === activeIndex; dot.classList.toggle('is-active', active); dot.setAttribute('aria-selected', String(active)); });
    };
    const start = () => { timer = window.setInterval(() => showSlide(activeIndex + 1), 5000); };
    const stop = () => window.clearInterval(timer);
    dots.forEach((dot) => dot.addEventListener('click', () => { stop(); showSlide(Number(dot.dataset.carouselDot)); start(); }));
    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    start();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
