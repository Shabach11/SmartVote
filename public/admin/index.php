<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!(isAdmin() || has_approved_creation_request($pdo, $_SESSION['user_id']) || has_active_subadmin_access($pdo, $_SESSION['user_id']))) { http_response_code(403); exit('Election management access is required.'); }
$is_subadmin = !isAdmin();
$dashboard_images = function ($folder) { $matches = glob(PUBLIC_PATH . '/assets/images/' . $folder . '/*.{jpg,jpeg,png,webp,avif,jfif}', GLOB_BRACE); return $matches ? url('/assets/images/' . $folder . '/' . rawurlencode(basename($matches[0]))) : ''; };
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50">
    <div class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-900">Admin Dashboard</h1>
                <p class="text-gray-600 mt-1">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?> 👋</p>
            </div>
            <a href="../logout.php" 
               class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-lg font-medium">
                Logout
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <a href="ai-create-election.php" 
               class="bg-white p-10 min-h-72 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <i class="bi bi-stars text-5xl text-blue-600 block mb-5"></i>
                <h3 class="text-xl font-semibold mb-2">Create Election with AI</h3>
                <p class="text-gray-600">Get guided through every election setup step</p>
            </a>

            <a href="create-election.php" 
               class="bg-white p-10 min-h-72 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <?php if ($image = $dashboard_images('host_new_election_images')): ?><img src="<?= $image ?>" alt="" class="w-full h-32 object-cover rounded-xl mb-5"><?php else: ?><i class="bi bi-plus-square text-5xl text-blue-600 block mb-5"></i><?php endif; ?>
                <h3 class="text-xl font-semibold mb-2">+ Host New Election</h3>
                <p class="text-gray-600">Create a new voting election</p>
            </a>

            <a href="elections.php" 
               class="bg-white p-10 min-h-72 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <?php if ($image = $dashboard_images('view_all_elections_images')): ?><img src="<?= $image ?>" alt="" class="w-full h-32 object-cover rounded-xl mb-5"><?php else: ?><i class="bi bi-card-checklist text-5xl text-blue-600 block mb-5"></i><?php endif; ?>
                <h3 class="text-xl font-semibold mb-2">View All Elections</h3>
                <p class="text-gray-600">Manage existing elections</p>
            </a>

            <a href="results.php" 
               class="bg-white p-10 min-h-72 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <?php if ($image = $dashboard_images('results_images')): ?><img src="<?= $image ?>" alt="" class="w-full h-32 object-cover rounded-xl mb-5"><?php else: ?><i class="bi bi-bar-chart-line text-5xl text-blue-600 block mb-5"></i><?php endif; ?>
                <h3 class="text-xl font-semibold mb-2">Results</h3>
                <p class="text-gray-600">View real-time voting results</p>
            </a>

            <?php if (isAdmin()): ?>
            <a href="reviews.php" class="bg-white p-10 min-h-72 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <i class="bi bi-chat-square-heart text-5xl text-blue-600 block mb-5"></i>
                <h3 class="text-xl font-semibold mb-2">Review &amp; Feedback</h3>
                <p class="text-gray-600">Read feedback submitted by election participants</p>
            </a>
            <a href="election-requests.php" class="bg-white p-10 min-h-72 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <i class="bi bi-envelope-paper text-5xl text-blue-600 block mb-5"></i>
                <h3 class="text-xl font-semibold mb-2">Election Requests</h3>
                <p class="text-gray-600">Review and manage election creation requests</p>
            </a>
            <?php endif; ?>

            <a href="../review.php" class="bg-white p-8 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <h3 class="text-xl font-semibold mb-2">Drop a Review</h3>
                <p class="text-gray-600">Rate and comment on an election you participated in</p>
            </a>

            <?php if (isAdmin()): ?>
            <a href="manage-admins.php" class="bg-white p-8 rounded-2xl shadow hover:shadow-xl transition border border-gray-100">
                <h3 class="text-xl font-semibold mb-2">Admin Access</h3>
                <p class="text-gray-600">Promote existing voters to admins</p>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($is_subadmin): ?>
            <section class="home-instructions" aria-labelledby="subadmin-guidance">
                <div class="home-instructions-heading">
                    <p class="landing-kicker">Election management guide</p>
                    <h2 id="subadmin-guidance">Your Sub-Admin access</h2>
                    <p>Use these steps to create a secure election and build its independent Audit Team.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <article class="home-instruction-card">
                        <div class="icon-badge"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                        <h3>How to Create an Election</h3>
                        <ol>
                            <li>Upload a valid <strong>.xlsx</strong> file containing eligible voters.</li>
                            <li>The system scans the file and displays its column headers.</li>
                            <li>Select one verification field, such as Employee ID, Index Number, or Email.</li>
                            <li>After creation, SmartVote generates a unique Election Code.</li>
                            <li>Voters must enter that Election Code to join and vote.</li>
                        </ol>
                    </article>
                    <article class="home-instruction-card">
                        <div class="icon-badge green"><i class="bi bi-people"></i></div>
                        <h3>Audit Team</h3>
                        <ul>
                            <li>Add 1–6 Audit Committee email addresses during or after election creation.</li>
                            <li>Each person receives an invitation email, then a confirmation code after accepting.</li>
                            <li>Confirmed people become auditors for this election only and cannot vote in it.</li>
                            <li>Auditor access automatically ends 48 hours after the election finishes.</li>
                        </ul>
                    </article>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
