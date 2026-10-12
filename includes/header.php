<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartVote</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=Inter:wght@400;500;600;700&family=Source+Serif+4:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(PUBLIC_PATH . '/assets/css/style.css') ?>">
</head>
<body class="<?= strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false ? 'admin-ui' : '' ?> <?= in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login.php', 'register.php'], true) ? 'auth-ui' : '' ?>">
<?php
$nav_logged_in = function_exists('isLoggedIn') && isLoggedIn();
$nav_is_admin = function_exists('isAdmin') && isAdmin();
$nav_is_manager = $nav_logged_in && (isAdmin() || has_approved_creation_request($pdo, $_SESSION['user_id']) || has_active_subadmin_access($pdo, $_SESSION['user_id']));
$nav_is_auditor = $nav_logged_in && has_active_auditor_access($pdo, $_SESSION['user_id'], $_SESSION['email'] ?? '');
$nav_name = $_SESSION['full_name'] ?? '';
?>

<header class="app-topbar">
    <button type="button" class="app-menu-button" id="app-menu-toggle" aria-label="Toggle navigation" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <a href="<?= url('/index.php') ?>" class="app-brand">
        <span class="app-brand-mark">SV</span>
        <span>SmartVote</span>
    </a>

    <nav class="app-topnav" aria-label="Primary navigation">
        <a href="<?= url('/index.php') ?>">Home</a>
        <?php if ($nav_logged_in): ?>
            <a href="<?= url('/vote/index.php') ?>">Find Election</a>
            <a href="<?= url('/logs.php') ?>">Logs</a>
            <?php if ($nav_is_manager): ?>
                <a href="<?= url('/admin/index.php') ?>">Admin</a>
                <a href="<?= url('/admin/ai-create-election.php') ?>">Create Election with AI</a>
            <?php endif; ?>
            <a href="<?= url('/review.php') ?>">Drop a Review</a>
        <?php endif; ?>
    </nav>

    <div class="app-userbar">
        <?php if ($nav_logged_in): ?>
            <span><?= htmlspecialchars($nav_name) ?></span>
            <a href="<?= url('/logout.php') ?>" class="app-nav-action app-nav-action-danger">Logout</a>
        <?php else: ?>
            <a href="<?= url('/login.php') ?>" class="app-nav-link">Sign In</a>
            <a href="<?= url('/register.php') ?>" class="app-nav-action">Register</a>
        <?php endif; ?>
    </div>
</header>

<aside class="app-sidebar" id="app-sidebar" aria-label="Sidebar navigation">
    <div class="app-sidebar-head">
        <span class="app-brand-mark">SV</span>
        <div>
            <strong>SmartVote</strong>
            <span><?= $nav_logged_in ? htmlspecialchars($nav_name) : 'Secure voting' ?></span>
        </div>
    </div>

    <nav class="app-side-links">
        <a href="<?= url('/index.php') ?>">Home</a>
        <?php if ($nav_logged_in): ?>
            <a href="<?= url('/vote/index.php') ?>">Find Election</a>
            <a href="<?= url('/logs.php') ?>">Login Logs</a>
            <?php if ($nav_is_manager): ?>
                <a href="<?= url('/admin/index.php') ?>">Admin Dashboard</a>
                <a href="<?= url('/admin/create-election.php') ?>">Create Election</a>
                <a href="<?= url('/admin/ai-create-election.php') ?>">Create Election with AI</a>
                <a href="<?= url('/admin/elections.php') ?>">All Elections</a>
                <a href="<?= url('/admin/results.php') ?>">Results</a>
                <?php if (isAdmin()): ?><a href="<?= url('/admin/reviews.php') ?>">Reviews</a><?php endif; ?>
                <a href="<?= url('/admin/elections.php') ?>">Audit Reports</a>
            <?php endif; ?>
            <?php if ($nav_is_auditor): ?><a href="<?= url('/admin/audit-dashboard.php') ?>">Audit Dashboard</a><?php endif; ?>
            <?php if (!$nav_is_admin): ?><a href="<?= url('/request-election.php') ?>">Create an Election</a><?php endif; ?>
            <a href="<?= url('/review.php') ?>">Drop a Review</a>
            <a href="<?= url('/logout.php') ?>">Logout</a>
        <?php else: ?>
            <a href="<?= url('/login.php') ?>">Sign In</a>
            <a href="<?= url('/register.php') ?>">Create Account</a>
        <?php endif; ?>
    </nav>
</aside>

<div class="app-sidebar-backdrop" id="app-sidebar-backdrop"></div>
