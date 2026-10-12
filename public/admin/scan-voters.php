<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/XlsxVoterImporter.php';

header('Content-Type: application/json; charset=utf-8');

function scan_response($status, $payload) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

requireLogin();
if (!can_create_election($pdo)) scan_response(403, ['ok' => false, 'error' => 'Election creation access is required.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    scan_response(403, ['ok' => false, 'error' => 'Security validation failed. Refresh the page and try again.']);
}

try {
    $action = $_POST['action'] ?? 'scan';
    if ($action === 'scan') {
        unset($_SESSION['eligible_voter_scan']);
        $scan = scan_voter_spreadsheet($_FILES['eligible_voters'] ?? []);
        $scan['token'] = bin2hex(random_bytes(32));
        $scan['selected_field'] = null;
        $_SESSION['eligible_voter_scan'] = $scan;

        scan_response(200, [
            'ok' => true,
            'message' => 'Excel file scanned successfully. Select the field voters must enter.',
            'filename' => $scan['filename'],
            'headers' => $scan['headers'],
            'token' => $scan['token']
        ]);
    }

    if ($action === 'select_field') {
        $scan = $_SESSION['eligible_voter_scan'] ?? null;
        $field = trim($_POST['verification_field'] ?? '');
        if (!$scan || !hash_equals($scan['token'] ?? '', $_POST['scan_token'] ?? '')) {
            throw new Exception('Scan the eligible-voters Excel file before creating the election.');
        }
        if ($field === '' || !in_array($field, $scan['headers'], true)) {
            throw new Exception('Select one of the detected spreadsheet fields.');
        }

        $_SESSION['eligible_voter_scan']['selected_field'] = $field;
        scan_response(200, ['ok' => true, 'message' => $field . ' selected as the voter verification field.']);
    }

    throw new Exception('Invalid scan action.');
} catch (Throwable $exception) {
    scan_response(422, ['ok' => false, 'error' => $exception->getMessage()]);
}
