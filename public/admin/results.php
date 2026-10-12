<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$allowed = isAdmin() || has_approved_creation_request($pdo, $_SESSION['user_id']) || has_active_subadmin_access($pdo, $_SESSION['user_id']);
if (!$allowed) { http_response_code(403); exit('Election management access is required.'); }
$requestedId = (int)($_GET['id'] ?? 0);
if ($requestedId) { $stmt = isAdmin() ? $pdo->prepare('SELECT * FROM elections WHERE id = ?') : $pdo->prepare('SELECT * FROM elections WHERE id = ? AND created_by = ?'); $stmt->execute(isAdmin() ? [$requestedId] : [$requestedId, $_SESSION['user_id']]); }
else { $stmt = isAdmin() ? $pdo->query('SELECT * FROM elections ORDER BY created_at DESC') : $pdo->prepare('SELECT * FROM elections WHERE created_by = ? ORDER BY created_at DESC'); if (!isAdmin()) $stmt->execute([$_SESSION['user_id']]); }
$elections = $stmt->fetchAll();
?>
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="min-h-screen bg-gray-50 py-8"><div class="max-w-6xl mx-auto px-6">
<div class="mb-8"><p class="landing-kicker">Election outcome</p><h1 class="text-4xl font-bold text-gray-900">Election Results</h1><p class="text-gray-600 mt-2">Official results and turnout are shown once voting has closed.</p></div>
<?php if (!$elections): ?><div class="bg-white rounded-2xl shadow p-8 text-gray-600">No elections found.</div><?php endif; ?>
<?php foreach ($elections as $election): $ended = strtotime($election['end_datetime']) <= time(); $data = $ended ? election_report_data($pdo, $election['id']) : null; if ($ended) store_election_audit_report($pdo, $election); ?>
<section class="results-panel"><div class="results-panel-head"><div><h2><?= htmlspecialchars($election['title']) ?></h2><p><?= htmlspecialchars($election['organization_name']) ?> &middot; Election code <strong><?= htmlspecialchars($election['code'] ?? $election['slug']) ?></strong></p></div><span class="results-status <?= $ended ? 'is-complete' : '' ?>"><?= $ended ? 'Completed' : 'In progress' ?></span></div>
<?php if (!$ended): ?><div class="mt-7 bg-blue-50 rounded-2xl p-5 text-blue-900">Results and turnout will be declared after <?= htmlspecialchars(date('M d, Y H:i', strtotime($election['end_datetime']))) ?>.</div><?php else: ?>
<div class="result-stat-grid"><div><span>Voter turnout</span><strong><?= number_format($data['turnout_percent'], 1) ?>%</strong><small><?= $data['cast_count'] ?> of <?= $data['eligible_count'] ?> eligible voters</small></div><div><span>Votes cast</span><strong><?= $data['cast_count'] ?></strong><small>Verified ballot receipts</small></div><div><span>Positions</span><strong><?= count($data['roles']) ?></strong><small>Results declared below</small></div></div>
<?php foreach ($data['roles'] as $role): ?><section class="candidate-result"><div class="candidate-result-head"><h3><?= htmlspecialchars($role['role_name']) ?></h3><span><?= $role['total_votes'] ?> votes</span></div><?php if (!$role['candidates']): ?><p class="text-gray-500">No candidates were recorded for this position.</p><?php endif; ?><?php foreach ($role['candidates'] as $candidate): $votes = (int)$candidate['votes']; $percent = $role['total_votes'] ? $votes / $role['total_votes'] * 100 : 0; $winner = $votes > 0 && $votes === $role['winner_votes']; ?><div class="candidate-result-row <?= $winner ? 'is-winner' : '' ?>"><div class="candidate-result-name"><strong><?= htmlspecialchars($candidate['name']) ?></strong><?php if ($winner): ?><span><i class="bi bi-trophy-fill"></i> Winner</span><?php endif; ?></div><div class="candidate-bar"><i style="width:<?= min(100, $percent) ?>%"></i></div><b><?= $votes ?> <small>(<?= number_format($percent, 1) ?>%)</small></b></div><?php endforeach; ?></section><?php endforeach; ?>
<div class="results-panel-footer"><a class="app-button app-button-primary" href="tamper-report.php?id=<?= (int)$election['id'] ?>">Open Full Audit Report</a></div><?php endif; ?></section><?php endforeach; ?>
</div></div><?php include __DIR__ . '/../../includes/footer.php'; ?>
