<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/XlsxVoterImporter.php';
requireLogin();

function voter_header_key($value) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$value))); }
function voter_cell($row, $index) { return trim((string)($row[$index] ?? '')); }

$electionId = (int)($_GET['id'] ?? $_POST['election_id'] ?? 0);
$check = $pdo->prepare('SELECT id, title FROM elections WHERE id = ? AND created_by = ?');
$check->execute([$electionId, $_SESSION['user_id']]);
$election = $check->fetch();
if (!$election || !can_manage_election($pdo, $electionId)) exit('Election not found or your election-only access has expired.');
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $error = 'Security validation failed.';
    elseif (!isset($_FILES['voters_file']) || $_FILES['voters_file']['error'] !== UPLOAD_ERR_OK) $error = 'Select a valid .xlsx file.';
    elseif ($_FILES['voters_file']['size'] > 5 * 1024 * 1024 || strtolower(pathinfo($_FILES['voters_file']['name'], PATHINFO_EXTENSION)) !== 'xlsx') $error = 'Only .xlsx files up to 5 MB are allowed.';
    else try {
        $rows = read_voter_xlsx($_FILES['voters_file']['tmp_name']);
        $rows = array_values(array_filter($rows, fn($row) => count(array_filter($row, fn($value) => trim((string)$value) !== '')) > 0));
        if (!$rows) throw new RuntimeException('The worksheet contains no voter entries.');

        $first = array_map('voter_header_key', $rows[0]);
        $nameIndex = array_search('name', $first, true);
        if ($nameIndex === false) $nameIndex = array_search('full name', $first, true);
        $hasHeaders = $nameIndex !== false;
        if (!$hasHeaders) { $headers = ['Name']; $nameIndex = 0; $dataRows = $rows; }
        else { $headers = $first; $dataRows = array_slice($rows, 1); }

        $identifierIndex = null;
        foreach (['company id', 'index number', 'staff id', 'employee number', 'employee id', 'matric number', 'matric no', 'registration number', 'identification number', 'identification', 'id', 'email'] as $preferred) {
            $found = array_search($preferred, $headers, true);
            if ($found === false || $found === $nameIndex) continue;
            $values = [];
            foreach (array_slice($rows, 1) as $row) { $value = voter_cell($row, $found); if ($value === '') { $values = []; break; } $values[] = $value; }
            if ($values && count($values) === count(array_unique($values))) { $identifierIndex = $found; break; }
        }
        if ($identifierIndex === null) foreach ($headers as $index => $header) {
            if ($index === $nameIndex || $header === 'phone') continue;
            $values = [];
            foreach (array_slice($rows, 1) as $row) { $value = voter_cell($row, $index); if ($value === '') { $values = []; break; } $values[] = $value; }
            if ($values && count($values) === count(array_unique($values))) { $identifierIndex = $index; break; }
        }
        $identifierLabel = $identifierIndex === null ? 'Name' : ucwords($headers[$identifierIndex]);
        $emailIndex = array_search('email', $headers, true);
        $phoneIndex = array_search('phone', $headers, true);

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM valid_voters WHERE election_id = ?')->execute([$electionId]);
        $insert = $pdo->prepare('INSERT INTO valid_voters (election_id, full_name, email, staff_id, identifier_label, identifier_value, phone) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $count = 0;
        foreach ($dataRows as $number => $row) {
            $name = voter_cell($row, $nameIndex);
            if ($name === '') throw new RuntimeException('A voter name is required on spreadsheet row ' . ($number + ($hasHeaders ? 2 : 1)) . '.');
            $identifier = $identifierIndex === null ? $name : voter_cell($row, $identifierIndex);
            if ($identifier === '') throw new RuntimeException($identifierLabel . ' is required on spreadsheet row ' . ($number + ($hasHeaders ? 2 : 1)) . '.');
            $email = $emailIndex === false ? '' : voter_cell($row, $emailIndex);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email on spreadsheet row ' . ($number + ($hasHeaders ? 2 : 1)) . '.');
            // Older schema versions require unique non-empty email/staff_id values. The synthetic
            // email is never used for login; eligibility is checked from name plus identifier.
            $storedEmail = $email ?: ('import+' . substr(hash('sha256', $electionId . '|' . $identifier), 0, 24) . '@invalid.local');
            $insert->execute([$electionId, $name, $storedEmail, $identifier, $identifierLabel, $identifier, $phoneIndex === false ? null : voter_cell($row, $phoneIndex)]);
            $count++;
        }
        if (!$count) throw new RuntimeException('The worksheet contains no voter entries.');
        $pdo->commit();
        log_audit_event($pdo, 'voter_list_imported', "$count eligible voters imported using $identifierLabel.", $electionId, $_SESSION['user_id']);
        $success = "$count eligible voters imported. Validation uses: $identifierLabel.";
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
}
include __DIR__ . '/../../includes/header.php'; ?>
<div class="min-h-screen bg-gray-50 py-8"><div class="max-w-3xl mx-auto px-6"><div class="bg-white rounded-3xl shadow-xl p-10">
<h1 class="text-3xl font-bold">Import Eligible Voters</h1><p class="text-gray-600 mt-2"><?= htmlspecialchars($election['title']) ?></p>
<?php if ($error): ?><p class="bg-red-100 text-red-700 p-4 rounded-xl mt-6"><?= htmlspecialchars($error) ?></p><?php endif; ?><?php if ($success): ?><p class="bg-green-100 text-green-700 p-4 rounded-xl mt-6"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<p class="bg-blue-50 p-4 rounded-xl mt-6">Upload an .xlsx file containing either a simple list of names, or a <strong>Name</strong> column plus any organization identifier such as Company ID, Index Number, Staff ID, Email, or another column. Uploading a new file replaces the election’s eligible-voter list.</p>
<form method="POST" enctype="multipart/form-data" class="mt-6 space-y-5"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="election_id" value="<?= $electionId ?>"><input type="file" name="voters_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="w-full border rounded-xl p-3"><button class="bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold">Import Eligible Voters</button></form>
</div></div></div><?php include __DIR__ . '/../../includes/footer.php'; ?>
