<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/XlsxVoterImporter.php';

requireLogin();
if (!can_create_election($pdo)) { http_response_code(403); exit('Election creation access is required.'); }

$error = '';
$success = '';
$scan = $_SESSION['eligible_voter_scan'] ?? null;

function prepare_eligible_voters($scan, $selectedField) {
    $rows = $scan['rows'];
    $headers = array_map(function ($value) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$value))); }, $scan['headers']);
    $selectedIndex = array_search(strtolower(trim($selectedField)), $headers, true);
    if ($selectedIndex === false) throw new Exception('Select one of the detected spreadsheet fields.');
    $rows = array_values($rows);
    $nameIndex = array_search('name', $headers, true);
    if ($nameIndex === false) $nameIndex = array_search('full name', $headers, true);
    $hasHeaders = true;
    $dataRows = $rows;
    if ($nameIndex === false) $nameIndex = $selectedIndex;

    // Prefer known organization ID columns only when their values are actually unique.
    $priority = ['company id', 'index number', 'staff id', 'employee number', 'employee id', 'matric number', 'matric no', 'registration number', 'identification number', 'identification', 'id', 'email'];
    $identifierIndex = $selectedIndex;
    $values = array_map(fn($row) => trim((string)($row[$identifierIndex] ?? '')), $dataRows);
    if (in_array('', $values, true)) throw new Exception('The selected verification field contains an empty value.');
    if (count($values) !== count(array_unique($values))) throw new Exception('The selected verification field contains duplicate values.');
    $emailIndex = array_search('email', $headers, true);
    $phoneIndex = array_search('phone', $headers, true);
    if ($emailIndex !== false) {
        $emails = [];
        foreach ($dataRows as $row) {
            $email = trim((string)($row[$emailIndex] ?? ''));
            if ($email !== '') $emails[] = strtolower($email);
        }
        if (count($emails) !== count(array_unique($emails))) {
            throw new Exception('The Email column contains duplicate values. Each eligible voter must have a unique email address.');
        }
    }
    // Retain the organizer's original spreadsheet heading for the election record and voter UI.
    $label = trim((string)$scan['headers'][$identifierIndex]);
    if ($identifierIndex === null) {
        $names = array_map(function ($row) use ($nameIndex) { return trim((string)($row[$nameIndex] ?? '')); }, $dataRows);
        if (count($names) !== count(array_unique($names))) {
            throw new Exception('The name-only spreadsheet contains duplicate names. Add a unique organization ID column for reliable validation.');
        }
    }
    $voters = [];
    foreach ($dataRows as $offset => $row) {
        $name = trim((string)($row[$nameIndex] ?? ''));
        $identifier = $identifierIndex === null ? $name : trim((string)($row[$identifierIndex] ?? ''));
        if ($name === '' || $identifier === '') throw new Exception('Missing ' . ($name === '' ? 'name' : $label) . ' on spreadsheet row ' . ($offset + ($hasHeaders ? 2 : 1)) . '.');
        $email = $emailIndex === false ? '' : trim((string)($row[$emailIndex] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email on spreadsheet row ' . ($offset + ($hasHeaders ? 2 : 1)) . '.');
        $sourceData = [];
        foreach ($scan['headers'] as $columnIndex => $header) {
            $sourceData[$header] = trim((string)($row[$columnIndex] ?? ''));
        }
        $voters[] = [
            'name' => $name,
            'identifier' => $identifier,
            'email' => $email,
            'phone' => $phoneIndex === false ? null : trim((string)($row[$phoneIndex] ?? '')),
            'source_data' => json_encode($sourceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
    }
    return ['label' => $label, 'voters' => $voters];
}

function store_eligible_voters($pdo, $electionId, $import) {
    $insert = $pdo->prepare('INSERT INTO valid_voters (election_id, full_name, email, staff_id, identifier_label, identifier_value, phone, source_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    try {
        foreach ($import['voters'] as $voter) {
            $storedEmail = $voter['email'] ?: ('import+' . substr(hash('sha256', $electionId . '|' . $voter['identifier']), 0, 24) . '@invalid.local');
            // staff_id is retained for compatibility with the legacy schema; identifier_value is authoritative.
            $legacyStaffId = strlen($voter['identifier']) <= 100 ? $voter['identifier'] : hash('sha256', $voter['identifier']);
            $insert->execute([$electionId, $voter['name'], $storedEmail, $legacyStaffId, $import['label'], $voter['identifier'], $voter['phone'] ?: null, $voter['source_data']]);
        }
    } catch (PDOException $exception) {
        throw new Exception('Eligible voters could not be imported. Ensure each selected verification value and email address is unique.', 0, $exception);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed.";
    } else {
        try {
            if (($_POST['action'] ?? '') !== 'create') {
                throw new Exception('Use Scan Excel File to process the voter list before creating the election.');
            }
            $scan = $_SESSION['eligible_voter_scan'] ?? null;
            if (!$scan || empty($scan['selected_field'])) throw new Exception('Scan the eligible-voters Excel file before creating the election.');
            if (!hash_equals($scan['token'] ?? '', $_POST['eligible_voter_scan_token'] ?? '')) {
                throw new Exception('Your eligible-voter scan is no longer valid. Scan the Excel file again before creating the election.');
            }
            $selectedField = trim($_POST['verification_field'] ?? '');
            if ($selectedField === '' || !hash_equals($scan['selected_field'], $selectedField)) {
                throw new Exception('Scan the eligible-voters Excel file before creating the election.');
            }

            $organization_name = trim($_POST['organization_name'] ?? '');
            $title             = trim($_POST['title'] ?? '');
            $reason            = trim($_POST['reason'] ?? '');
            $start_datetime    = $_POST['start_datetime'] ?? '';
            $end_datetime      = $_POST['end_datetime'] ?? '';
            $organization_logo = null;
            $roles             = $_POST['roles'] ?? [];
            $committee         = array_values(array_filter($_POST['committee'] ?? [], fn($email) => is_string($email) && trim($email) !== ''));

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));

            if ($organization_name === '' || $title === '' || $start_datetime === '' || $end_datetime === '') {
                throw new Exception("Please complete all required election details.");
            }

            if ($slug === '') {
                throw new Exception("Election title must contain letters or numbers.");
            }

            $voterImport = prepare_eligible_voters($scan, $selectedField);

            if (!empty($_FILES['organization_logo']['name'])) $organization_logo = save_image_upload($_FILES['organization_logo'], 'logos', 'logo');
            if (!isAdmin() && (count($committee) < 1 || count($committee) > 6)) throw new Exception('Add between 1 and 6 Audit Committee email addresses.');
            $committee = array_map(fn($email) => strtolower(trim($email)), $committee);
            foreach ($committee as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid Audit Committee email address.');
            }
            if (count($committee) !== count(array_unique($committee))) throw new Exception('Each Audit Committee email address must be unique.');

            $clean_roles = [];
            foreach ($roles as $role) {
                $role_name = trim($role['name'] ?? '');
                $role_type = ($role['type'] ?? 'multiple') === 'yesno' ? 'yesno' : 'multiple';
                $candidates = [];
                $proposition_name = null;
                $proposition_description = null;
                $proposition_image_path = null;

                if ($role_type === 'yesno') {
                    $proposition = $role['candidates'][0] ?? [];
                    $proposition_name = trim($proposition['name'] ?? '');
                    $proposition_description = trim($proposition['description'] ?? '');
                    $form_index = $role['form_index'] ?? 0;
                    $tmp_name = $_FILES['roles']['tmp_name'][$form_index]['candidates'][0]['image'] ?? '';
                    $file_name = $_FILES['roles']['name'][$form_index]['candidates'][0]['image'] ?? '';
                    $error_code = $_FILES['roles']['error'][$form_index]['candidates'][0]['image'] ?? UPLOAD_ERR_NO_FILE;

                    if ($proposition_name === '' || $proposition_description === '' || $error_code !== UPLOAD_ERR_OK || !is_uploaded_file($tmp_name)) {
                        throw new Exception('Yes/No positions require a candidate/proposition name, short description, and image.');
                    }
                    $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $image_info = @getimagesize($tmp_name);
                    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) || !$image_info) {
                        throw new Exception('The Yes/No position image must be a valid JPG, PNG, or WebP file.');
                    }
                    $proposition_image_path = save_image_upload(['error'=>$error_code,'tmp_name'=>$tmp_name,'size'=>$_FILES['roles']['size'][$form_index]['candidates'][0]['image'] ?? 0], 'candidates', 'proposition');

                    // The proposition metadata is retained on the position; the two ballot choices
                    // remain Yes and No so voters can approve or reject the named proposition.
                    $candidates[] = ['name' => 'Yes', 'description' => '', 'image_path' => null];
                    $candidates[] = ['name' => 'No', 'description' => '', 'image_path' => null];
                } else {
                    foreach (($role['candidates'] ?? []) as $candidate_index => $candidate) {
                        $candidate_name = trim($candidate['name'] ?? '');
                        $candidate_description = trim($candidate['description'] ?? '');
                        $image_path = null;

                        if (!empty($_FILES['roles']['name'][$role['form_index'] ?? 0]['candidates'][$candidate_index]['image'])) {
                            $tmp_name = $_FILES['roles']['tmp_name'][$role['form_index'] ?? 0]['candidates'][$candidate_index]['image'] ?? '';
                            $file_name = $_FILES['roles']['name'][$role['form_index'] ?? 0]['candidates'][$candidate_index]['image'] ?? '';
                            $error_code = $_FILES['roles']['error'][$role['form_index'] ?? 0]['candidates'][$candidate_index]['image'] ?? UPLOAD_ERR_NO_FILE;

                            if ($error_code === UPLOAD_ERR_OK) $image_path = save_image_upload(['error'=>$error_code,'tmp_name'=>$tmp_name,'size'=>$_FILES['roles']['size'][$role['form_index'] ?? 0]['candidates'][$candidate_index]['image'] ?? 0], 'candidates', 'candidate');
                        }

                        if ($candidate_name !== '') {
                            $candidates[] = [
                                'name' => $candidate_name,
                                'description' => $candidate_description,
                                'image_path' => $image_path
                            ];
                        }
                    }
                }

                if ($role_name !== '' && !empty($candidates)) {
                    $clean_roles[] = [
                        'name' => $role_name,
                        'type' => $role_type,
                        'proposition_name' => $proposition_name,
                        'proposition_description' => $proposition_description,
                        'proposition_image_path' => $proposition_image_path,
                        'candidates' => $candidates
                    ];
                }
            }

            if (empty($clean_roles)) {
                throw new Exception("Add at least one role with at least one candidate.");
            }

            $slug_check = $pdo->prepare("SELECT id FROM elections WHERE slug = ? LIMIT 1");
            $slug_check->execute([$slug]);
            if ($slug_check->fetch()) {
                $slug .= '-' . time();
            }

            $election_code = generate_election_code($pdo);
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO elections
                (slug, code, organization_name, organization_logo, title, reason, start_datetime, end_datetime,
                 voter_id_type, voter_verification_field, voter_id_example, voter_id_format, face_verification, one_vote_per_user, created_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'spreadsheet', ?, NULL, NULL, 0, 1, ?, 'active')");

            $stmt->execute([
                $slug,
                $election_code,
                $organization_name,
                $organization_logo,
                $title,
                $reason,
                $start_datetime,
                $end_datetime,
                $voterImport['label'],
                $_SESSION['user_id']
            ]);

            $election_id = $pdo->lastInsertId();
            store_eligible_voters($pdo, $election_id, $voterImport);
            $election_for_audits = ['id' => $election_id, 'title' => $title, 'end_datetime' => $end_datetime];
            $audit_invitations = [];
            foreach ($committee as $email) {
                $audit_invitations[] = ['email' => $email, 'token' => create_audit_invitation($pdo, $election_for_audits, $email)];
            }
            log_audit_event($pdo, 'voter_list_imported', count($voterImport['voters']) . ' eligible voters imported using ' . $voterImport['label'] . '.', $election_id, $_SESSION['user_id']);

            $role_stmt = $pdo->prepare("INSERT INTO candidate_roles (election_id, role_name, role_type, proposition_name, proposition_description, proposition_image_path) VALUES (?, ?, ?, ?, ?, ?)");
            $candidate_stmt = $pdo->prepare("INSERT INTO candidates (role_id, name, description, image_path, votes) VALUES (?, ?, ?, ?, 0)");

            foreach ($clean_roles as $role) {
                $role_stmt->execute([$election_id, $role['name'], $role['type'], $role['proposition_name'], $role['proposition_description'], $role['proposition_image_path']]);
                $role_id = $pdo->lastInsertId();

                foreach ($role['candidates'] as $candidate) {
                    $candidate_stmt->execute([$role_id, $candidate['name'], $candidate['description'], $candidate['image_path']]);
                }
            }

            $pdo->commit();
            foreach ($audit_invitations as $invitation) {
                if (!send_audit_invitation_email($election_for_audits, $invitation['email'], $invitation['token'])) {
                    error_log('SmartVote audit invitation email failed for election ' . $election_id . '.');
                }
            }
            unset($_SESSION['eligible_voter_scan']);
            $_SESSION['election_creation_success'] = 'Election created successfully. Eligible voters were imported and the verification field “' . $voterImport['label'] . '” was saved.';
            redirect('/admin/election-details.php?id=' . $election_id);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage() === '__SCAN_COMPLETE__' ? '' : "Failed to create election: " . $e->getMessage();
        }
    }
}
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-5xl mx-auto px-6">
        <div class="bg-white rounded-3xl shadow-xl p-10">
            <h1 class="text-4xl font-bold mb-2">Create New Election</h1>
            <div class="flex flex-wrap justify-between gap-4 mb-10"><p class="text-gray-600">Set up your election with candidates and roles</p><a href="ai-create-election.php" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-semibold">Create Election with AI</a></div>

            <?php if ($error): ?>
                <div class="bg-red-100 text-red-700 p-4 rounded-xl mb-6"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="bg-green-100 text-green-700 p-4 rounded-xl mb-6"><?= $success ?></div>
            <?php endif; ?>

            <form id="create-election-form" method="POST" enctype="multipart/form-data" class="space-y-10">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label>Organization Name</label>
                        <input type="text" name="organization_name" required class="w-full mt-2 px-4 py-3 border rounded-xl">
                    </div>
                    <div>
                        <label>Election Title</label>
                        <input type="text" name="title" required placeholder="e.g. Student Council Election" class="w-full mt-2 px-4 py-3 border rounded-xl">
                    </div>
                </div>

                <?php if (!isAdmin()): ?>
                <div class="bg-yellow-50 border border-yellow-300 rounded-2xl p-6">
                    <h2 class="text-xl font-bold">Election creator and Audit Committee</h2>
                    <p class="mt-2">Create the election, add its positions and candidates, then manage its results and reports. Add 1–6 Audit Committee members below. You and those members cannot vote in this election. Your election-only privileges and this committee automatically end 48 hours after the election finishes; reports remain available during that 48-hour period.</p>
                    <div id="committee" class="space-y-3 mt-4"><input name="committee[0]" type="email" required placeholder="Audit Committee member email" class="border rounded-xl p-3 w-full"></div><button type="button" onclick="addCommitteeMember()" class="mt-3 text-blue-700 font-semibold">Add committee member (up to 6)</button>
                </div>
                <?php endif; ?>

                <div>
                    <label>Organization Logo</label>
                    <input type="file" name="organization_logo" accept="image/*" class="w-full mt-2 px-4 py-3 border rounded-xl">
                </div>

                <div>
                    <label>Reason for Voting</label>
                    <textarea name="reason" rows="3" class="w-full mt-2 px-4 py-3 border rounded-xl"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label>Start Date & Time</label>
                        <input type="datetime-local" name="start_datetime" required class="w-full mt-2 px-4 py-3 border rounded-xl">
                    </div>
                    <div>
                        <label>End Date & Time</label>
                        <input type="datetime-local" name="end_datetime" required class="w-full mt-2 px-4 py-3 border rounded-xl">
                    </div>
                </div>

                <div class="pt-8 border-t">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h2 class="text-2xl font-semibold">Roles and Candidates</h2>
                            <p class="text-gray-600 mt-1">Add each position voters will choose from.</p>
                        </div>
                        <button type="button" onclick="addRole()" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-medium">
                            Add Role
                        </button>
                    </div>

                    <div id="roles" class="space-y-6">
                        <div class="border border-gray-200 rounded-2xl p-4" data-role-index="0">
                            <div class="flex justify-between items-center mb-3">
                                <strong>Position 1</strong>
                                <button type="button" onclick="removeRole(this)" class="text-red-600 hover:underline font-semibold hidden">Remove</button>
                            </div>
                            <input type="hidden" name="roles[0][form_index]" value="0">
                            <label>Role Name</label>
                            <input type="text" name="roles[0][name]" required placeholder="e.g. President" class="w-full mt-2 px-4 py-3 border rounded-xl">

                            <div class="mt-4">
                                <label>Position Type</label>
                                <select name="roles[0][type]" onchange="toggleRoleType(this)" class="w-full mt-2 px-4 py-3 border rounded-xl">
                                    <option value="multiple">Multiple candidates</option>
                                    <option value="yesno">Yes / No question</option>
                                </select>
                            </div>

                            <div class="candidates space-y-4 mt-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <input type="text" name="roles[0][candidates][0][name]" required placeholder="Candidate name" class="w-full px-4 py-3 border rounded-xl">
                                    <input type="text" name="roles[0][candidates][0][description]" placeholder="Short description" class="w-full px-4 py-3 border rounded-xl">
                                    <input type="file" name="roles[0][candidates][0][image]" accept="image/*" class="w-full px-4 py-3 border rounded-xl">
                                </div>
                            </div>

                            <button type="button" onclick="addCandidate(this)" class="mt-4 text-blue-600 hover:underline font-semibold">
                                Add Candidate
                            </button>
                        </div>
                    </div>
                </div>

                <div class="pt-6 border-t">
                    <label class="block font-semibold">Eligible Voters Excel File</label>
                    <p class="text-sm text-gray-500 mt-1">Upload an Excel spreadsheet, then scan it to choose the field used to verify eligible voters.</p>
                    <input id="eligible-voters-file" type="file" name="eligible_voters" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="w-full mt-3 px-4 py-3 border rounded-xl">
                    <button type="button" class="mt-3 bg-gray-800 hover:bg-gray-900 text-white font-semibold px-5 py-3 rounded-xl" id="scan-button">Scan Excel File</button>
                    <p id="scan-feedback" class="text-sm mt-3" aria-live="polite"></p>

                    <?php if ($scan): ?>
                        <input type="hidden" name="eligible_voter_scan_token" value="<?= htmlspecialchars($scan['token'] ?? '') ?>">
                        <div id="scan-results" class="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-6">
                            <h2 class="text-xl font-bold">Select the field that will be used to verify eligible voters</h2>
                            <p class="text-blue-900 mt-2">This field will be used to check if a person is allowed to vote in this election.</p>
                            <p class="text-sm text-gray-600 mt-3">Detected in <?= htmlspecialchars($scan['filename']) ?>:</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                                <?php foreach ($scan['headers'] as $header): ?>
                                    <label class="flex items-center gap-3 bg-white border rounded-xl p-3 cursor-pointer">
                                        <input type="radio" name="verification_field" value="<?= htmlspecialchars($header) ?>" required <?= (($scan['selected_field'] ?? '') === $header) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($header) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pt-8 border-t">
                    <button type="submit" name="action" value="create" <?= !empty($scan['selected_field']) ? '' : 'disabled' ?> class="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white font-bold py-5 rounded-2xl text-lg" id="create-election-button">
                        Create & Launch Election
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let roleIndex = 1;
let committeeIndex = 1;
const electionForm = document.getElementById('create-election-form');
const scanButton = document.getElementById('scan-button');
const votersFile = document.getElementById('eligible-voters-file');
const scanFeedback = document.getElementById('scan-feedback');
const createButton = document.getElementById('create-election-button');

function setScanFeedback(message, isError) {
    scanFeedback.textContent = message;
    scanFeedback.className = 'text-sm mt-3 ' + (isError ? 'text-red-700' : 'text-green-700');
}

function scanTokenInput(token) {
    let input = electionForm.querySelector('input[name="eligible_voter_scan_token"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'eligible_voter_scan_token';
        electionForm.appendChild(input);
    }
    input.value = token;
}

function renderScanResults(scan) {
    const previous = document.getElementById('scan-results');
    if (previous) previous.remove();

    const results = document.createElement('div');
    results.id = 'scan-results';
    results.className = 'mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-6';
    const heading = document.createElement('h2');
    heading.className = 'text-xl font-bold';
    heading.textContent = 'Select the field that will be used to verify eligible voters';
    const instruction = document.createElement('p');
    instruction.className = 'text-blue-900 mt-2';
    instruction.textContent = 'This field will be used to check if a person is allowed to vote in this election.';
    const detected = document.createElement('p');
    detected.className = 'text-sm text-gray-600 mt-3';
    detected.textContent = 'Detected in ' + scan.filename + ':';
    const fields = document.createElement('div');
    fields.className = 'grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4';

    scan.headers.forEach(function (header) {
        const label = document.createElement('label');
        label.className = 'flex items-center gap-3 bg-white border rounded-xl p-3 cursor-pointer';
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'verification_field';
        radio.value = header;
        radio.required = true;
        radio.addEventListener('change', function () { saveSelectedField(header, scan.token); });
        const text = document.createElement('span');
        text.textContent = header;
        label.append(radio, text);
        fields.appendChild(label);
    });
    results.append(heading, instruction, detected, fields);
    scanButton.parentNode.appendChild(results);
}

async function saveSelectedField(field, token) {
    const data = new FormData();
    data.append('action', 'select_field');
    data.append('csrf_token', electionForm.querySelector('[name="csrf_token"]').value);
    data.append('scan_token', token);
    data.append('verification_field', field);
    createButton.disabled = true;
    setScanFeedback('Saving verification field...', false);
    try {
        const response = await fetch('scan-voters.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Could not save the selected verification field.');
        createButton.disabled = false;
        setScanFeedback(result.message, false);
    } catch (error) {
        const selected = electionForm.querySelector('input[name="verification_field"]:checked');
        if (selected) selected.checked = false;
        setScanFeedback(error.message, true);
    }
}

scanButton?.addEventListener('click', async function () {
    if (!votersFile.files.length) {
        setScanFeedback('Choose an eligible-voters Excel file before scanning.', true);
        return;
    }
    const data = new FormData();
    data.append('action', 'scan');
    data.append('csrf_token', electionForm.querySelector('[name="csrf_token"]').value);
    data.append('eligible_voters', votersFile.files[0]);
    scanButton.disabled = true;
    scanButton.textContent = 'Scanning Excel...';
    createButton.disabled = true;
    try {
        const response = await fetch('scan-voters.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'The Excel file could not be scanned.');
        scanTokenInput(result.token);
        renderScanResults(result);
        setScanFeedback(result.message, false);
    } catch (error) {
        setScanFeedback(error.message, true);
    } finally {
        scanButton.disabled = false;
        scanButton.textContent = 'Scan Excel File';
    }
});

document.querySelectorAll('input[name="verification_field"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        saveSelectedField(radio.value, electionForm.querySelector('input[name="eligible_voter_scan_token"]')?.value || '');
    });
});
function addCommitteeMember() { const box=document.getElementById('committee'); if (!box || committeeIndex >= 6) return; const input=document.createElement('input'); input.name=`committee[${committeeIndex}]`; input.type='email'; input.required=true; input.placeholder='Audit Committee member email'; input.className='border rounded-xl p-3 w-full'; box.appendChild(input); committeeIndex++; }

function addRole() {
    const roles = document.getElementById('roles');
    const index = roleIndex++;
    const wrapper = document.createElement('div');
    wrapper.className = 'border border-gray-200 rounded-2xl p-4';
    wrapper.dataset.roleIndex = index;
    wrapper.innerHTML = `
        <div class="flex justify-between items-center mb-3">
            <strong>Position ${index + 1}</strong>
            <button type="button" onclick="removeRole(this)" class="text-red-600 hover:underline font-semibold">Remove</button>
        </div>
        <input type="hidden" name="roles[${index}][form_index]" value="${index}">
        <label>Role Name</label>
        <input type="text" name="roles[${index}][name]" required placeholder="e.g. Treasurer" class="w-full mt-2 px-4 py-3 border rounded-xl">

        <div class="mt-4">
            <label>Position Type</label>
            <select name="roles[${index}][type]" onchange="toggleRoleType(this)" class="w-full mt-2 px-4 py-3 border rounded-xl">
                <option value="multiple">Multiple candidates</option>
                <option value="yesno">Yes / No question</option>
            </select>
        </div>

        <div class="candidates space-y-4 mt-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <input type="text" name="roles[${index}][candidates][0][name]" required placeholder="Candidate name" class="w-full px-4 py-3 border rounded-xl">
                <input type="text" name="roles[${index}][candidates][0][description]" placeholder="Short description" class="w-full px-4 py-3 border rounded-xl">
                <input type="file" name="roles[${index}][candidates][0][image]" accept="image/*" class="w-full px-4 py-3 border rounded-xl">
            </div>
        </div>

        <button type="button" onclick="addCandidate(this)" class="mt-4 text-blue-600 hover:underline font-semibold">
            Add Candidate
        </button>
    `;
    roles.appendChild(wrapper);
    updateRemoveButtons();
}

function removeRole(button) {
    button.closest('[data-role-index]').remove();
    updateRemoveButtons();
}

function updateRemoveButtons() {
    const roles = document.querySelectorAll('#roles [data-role-index]');
    roles.forEach((role, index) => {
        const remove = role.querySelector('button[onclick^="removeRole"]');
        const title = role.querySelector('strong');
        if (title) {
            title.textContent = `Position ${index + 1}`;
        }
        if (remove) {
            remove.classList.toggle('hidden', roles.length === 1);
        }
    });
}

function addCandidate(button) {
    const role = button.closest('[data-role-index]');
    const roleId = role.dataset.roleIndex;
    const candidates = role.querySelector('.candidates');
    const candidateId = candidates.children.length;
    const row = document.createElement('div');
    row.className = 'grid grid-cols-1 md:grid-cols-3 gap-4';
    row.innerHTML = `
        <input type="text" name="roles[${roleId}][candidates][${candidateId}][name]" required placeholder="Candidate name" class="w-full px-4 py-3 border rounded-xl">
        <input type="text" name="roles[${roleId}][candidates][${candidateId}][description]" placeholder="Short description" class="w-full px-4 py-3 border rounded-xl">
        <input type="file" name="roles[${roleId}][candidates][${candidateId}][image]" accept="image/*" class="w-full px-4 py-3 border rounded-xl">
    `;
    candidates.appendChild(row);
}

function toggleRoleType(select) {
    const role = select.closest('[data-role-index]');
    const candidates = role.querySelector('.candidates');
    const addButton = role.querySelector('button[onclick^="addCandidate"]');
    // Yes/No positions still need their proposition name, description, and image. Keep the
    // first candidate row visible and make every field required for that position type.
    candidates.classList.remove('hidden');
    candidates.querySelectorAll('input[name*="[name]"]').forEach(input => input.required = true);
    candidates.querySelectorAll('input[name*="[description]"], input[type="file"]').forEach(input => input.required = select.value === 'yesno');
    addButton.classList.toggle('hidden', select.value === 'yesno');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
