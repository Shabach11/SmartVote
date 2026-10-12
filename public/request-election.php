<?php
require_once __DIR__ . '/../config.php'; requireLogin();
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org = trim($_POST['organization_name'] ?? ''); $reason = trim($_POST['reason'] ?? '');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $error = 'Security validation failed.';
    elseif ($org === '' || $reason === '') $error = 'Organization name and reason are required.';
    elseif (mb_strlen($org) > 190 || mb_strlen($reason) > 4000) $error = 'Please keep the organization name and reason within the allowed length.';
    else {
        $check = $pdo->prepare("SELECT id FROM election_creation_requests WHERE user_id = ? AND status IN ('pending', 'approved') LIMIT 1"); $check->execute([$_SESSION['user_id']]);
        if ($check->fetch()) $error = 'You already have a pending or approved request.';
        else {
            try {
                $logo = save_image_upload($_FILES['organization_logo'] ?? [], 'organization-logos', 'request_logo');
                $add = $pdo->prepare('INSERT INTO election_creation_requests (user_id, full_name, email, organization_name, organization_logo, reason) VALUES (?, ?, ?, ?, ?, ?)');
                $add->execute([$_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['email'], $org, $logo, $reason]);
                $requestId = (int)$pdo->lastInsertId();
                log_audit_event($pdo, 'election_creation_request_submitted', 'Request #' . $requestId . ' submitted for ' . $org . '.', null, $_SESSION['user_id']);
                $success = 'Your request has been submitted.';
            } catch (Exception $exception) { $error = 'Your request could not be submitted: ' . $exception->getMessage(); }
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>
<main class="min-h-screen bg-gray-50 py-8"><div class="max-w-2xl mx-auto px-6"><div class="bg-white rounded-3xl shadow-xl p-8"><h1 class="text-3xl font-bold">Request Election Creation Access</h1><p class="text-gray-600 mt-2">Approved access applies only to the election you create and ends 48 hours after it finishes.</p><?php if ($error): ?><p class="bg-red-100 text-red-700 rounded p-4 mt-5"><?= htmlspecialchars($error) ?></p><?php endif; ?><?php if ($success): ?><p class="bg-green-100 text-green-700 rounded p-4 mt-5"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<form method="POST" enctype="multipart/form-data" class="space-y-5 mt-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><div><label>Personal details</label><input disabled value="<?= htmlspecialchars($_SESSION['full_name'] . ' — ' . $_SESSION['email']) ?>" class="w-full mt-2 p-3 border rounded-xl bg-gray-50"></div><div><label for="organization_name">Organization name</label><input id="organization_name" name="organization_name" maxlength="190" required class="w-full mt-2 p-3 border rounded-xl" value="<?= htmlspecialchars($_POST['organization_name'] ?? '') ?>"></div><div><label for="organization_logo">Organization logo <span class="text-gray-500 font-normal">(optional)</span></label><input id="organization_logo" type="file" name="organization_logo" accept="image/jpeg,image/png,image/webp" class="w-full mt-2 p-3 border rounded-xl"><p class="text-sm text-gray-500 mt-1">JPG, PNG, or WebP, up to 40 MB.</p></div><div><label for="reason">Why do you need to create this election?</label><textarea id="reason" name="reason" required rows="5" maxlength="4000" class="w-full mt-2 p-3 border rounded-xl"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea></div><button class="bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold">Submit request</button></form></div></div></main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
