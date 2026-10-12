<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$electionId = (int)($_GET['id'] ?? $_POST['election_id'] ?? 0);
$owner = $pdo->prepare('SELECT id, title FROM elections WHERE id = ? AND created_by = ?'); $owner->execute([$electionId, $_SESSION['user_id']]); $election = $owner->fetch();
if (!$election || !can_manage_election($pdo, $electionId)) exit('Election not found or your election-only access has expired.');
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $error = 'Security validation failed.';
    elseif ($_POST['action'] === 'remove') {
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE c FROM candidates c INNER JOIN candidate_roles r ON r.id=c.role_id WHERE c.id=? AND r.election_id=? AND c.votes=0');
        $stmt->execute([$candidateId, $electionId]);
        if ($stmt->rowCount()) { log_audit_event($pdo, 'candidate_removed', 'Candidate removed before receiving votes.', $electionId, $_SESSION['user_id']); $success = 'Candidate removed.'; }
        else $error = 'Candidates with recorded votes cannot be removed.';
    } elseif ($_POST['action'] === 'add') {
        $roleId = (int)($_POST['role_id'] ?? 0); $name = trim($_POST['name'] ?? ''); $details = trim($_POST['details'] ?? ''); $image = null;
        if ($name === '') $error = 'Candidate name is required.';
        elseif (!empty($_FILES['image']['name'])) {
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 40 * 1024 * 1024) $error = 'Image upload failed or exceeds 40 MB.';
            else {
                $info = @getimagesize($_FILES['image']['tmp_name']); $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!$info || !isset($allowed[$info['mime']])) $error = 'Candidate image must be a valid JPG, PNG, or WebP file.';
                else { $dir = __DIR__ . '/../../uploads/candidates'; if (!is_dir($dir)) mkdir($dir, 0755, true); $file = 'candidate_' . bin2hex(random_bytes(16)) . '.' . $allowed[$info['mime']]; if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $file)) $error = 'Could not store candidate image.'; else $image = 'uploads/candidates/' . $file; }
            }
        }
        if (!$error) { $valid = $pdo->prepare('SELECT id FROM candidate_roles WHERE id=? AND election_id=?'); $valid->execute([$roleId, $electionId]); if (!$valid->fetch()) $error='Invalid position.'; else { $add=$pdo->prepare('INSERT INTO candidates (role_id,name,description,image_path,votes) VALUES (?,?,?,?,0)'); $add->execute([$roleId,$name,$details ?: null,$image]); log_audit_event($pdo,'candidate_added','Candidate added.', $electionId,$_SESSION['user_id']); $success='Candidate added.'; } }
    }
}
$roles = $pdo->prepare('SELECT * FROM candidate_roles WHERE election_id=? ORDER BY id'); $roles->execute([$electionId]); $roles = $roles->fetchAll();
foreach ($roles as &$role) { $c=$pdo->prepare('SELECT * FROM candidates WHERE role_id=? ORDER BY name'); $c->execute([$role['id']]); $role['candidates']=$c->fetchAll(); }
include __DIR__ . '/../../includes/header.php'; ?>
<div class="min-h-screen bg-gray-50 py-8"><div class="max-w-5xl mx-auto px-6"><div class="bg-white rounded-3xl shadow-xl p-10"><h1 class="text-3xl font-bold">Manage Candidates</h1><p class="text-gray-600"><?= htmlspecialchars($election['title']) ?></p>
<?php if($error): ?><p class="bg-red-100 text-red-700 p-4 rounded-xl mt-5"><?= htmlspecialchars($error) ?></p><?php endif; ?><?php if($success): ?><p class="bg-green-100 text-green-700 p-4 rounded-xl mt-5"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php foreach($roles as $role): ?><section class="mt-8 border-t pt-6"><h2 class="text-xl font-bold"><?= htmlspecialchars($role['role_name']) ?></h2><?php foreach($role['candidates'] as $candidate): ?><div class="flex justify-between p-3 bg-gray-50 rounded-xl mt-3"><span><?= htmlspecialchars($candidate['name']) ?> (<?= (int)$candidate['votes'] ?> votes)</span><form method="POST"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="election_id" value="<?= $electionId ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="candidate_id" value="<?= (int)$candidate['id'] ?>"><button class="text-red-600" <?= $candidate['votes'] ? 'disabled' : '' ?>>Remove</button></form></div><?php endforeach; ?><form method="POST" enctype="multipart/form-data" class="grid md:grid-cols-4 gap-3 mt-4"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="election_id" value="<?= $electionId ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>"><input name="name" required placeholder="Candidate name" class="border rounded p-2"><input name="details" placeholder="Optional details" class="border rounded p-2"><input name="image" type="file" accept="image/jpeg,image/png,image/webp" class="border rounded p-2"><button class="bg-blue-600 text-white rounded p-2">Add candidate</button></form></section><?php endforeach; ?>
</div></div></div><?php include __DIR__ . '/../../includes/footer.php'; ?>
