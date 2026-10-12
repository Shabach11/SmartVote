<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

function report_lines($data) {
    $e = $data['election'];
    $lines = [
        ['title', 'SMARTVOTE — FULL AUDIT REPORT'],
        ['text', $e['title'] . ' | Election code: ' . ($e['code'] ?? $e['slug'])],
        ['text', 'Organization: ' . $e['organization_name']],
        ['heading', 'Election summary'],
        ['text', 'Eligible voters: ' . $data['eligible_count']], ['text', 'Votes cast: ' . $data['cast_count']],
        ['text', 'Voter turnout: ' . number_format($data['turnout_percent'], 1) . '%'],
        ['text', 'Double-voting attempts: ' . $data['double_votes']], ['text', 'Blocked attempts: ' . $data['blocked_attempts']],
        ['heading', 'Gender distribution of participants']
    ];
    foreach (['M' => 'Male', 'F' => 'Female', 'Unknown' => 'Not recorded'] as $key => $label) {
        $count = $data['gender'][$key]; $percent = $data['cast_count'] ? $count / $data['cast_count'] * 100 : 0;
        $lines[] = ['text', $label . ': ' . $count . ' (' . number_format($percent, 1) . '%)'];
    }
    $lines[] = ['heading', 'Voting time analysis'];
    $lines[] = ['text', 'Voting time range: ' . ($data['first_vote'] ? date('M d, Y H:i', strtotime($data['first_vote'])) . ' – ' . date('M d, Y H:i', strtotime($data['last_vote'])) : 'No completed ballots')];
    $lines[] = ['text', 'Most active voting hour: ' . ($data['most_active'] ? date('M d, Y H:i', strtotime($data['most_active']['hour_start'])) . ' (' . $data['most_active']['votes'] . ' votes)' : 'No completed ballots')];
    $lines[] = ['text', 'Votes by hour:'];
    foreach ($data['hourly_votes'] as $hour) $lines[] = ['text', '  ' . date('M d, Y H:i', strtotime($hour['hour_start'])) . ': ' . $hour['votes'] . ' vote(s)'];
    $lines[] = ['heading', 'Candidate results'];
    foreach ($data['roles'] as $role) { $lines[] = ['subheading', $role['role_name']]; foreach ($role['candidates'] as $candidate) { $pct = $role['total_votes'] ? $candidate['votes'] / $role['total_votes'] * 100 : 0; $winner = (int)$candidate['votes'] > 0 && (int)$candidate['votes'] === $role['winner_votes'] ? ' — Winner' : ''; $lines[] = ['text', $candidate['name'] . ': ' . $candidate['votes'] . ' (' . number_format($pct, 1) . '%)' . $winner]; } }
    $lines[] = ['heading', 'Double voting & suspicious activity'];
    $lines[] = ['text', 'Blocked-event records: ' . count($data['blocked_events'])];
    if (!$data['blocked_events']) $lines[] = ['text', 'No blocked attempts were logged.'];
    foreach ($data['blocked_events'] as $event) $lines[] = ['text', sprintf('%s | %s | %s | %s | %s', date('M d, Y H:i', strtotime($event['attempt_time'])), anonymize_audit_reference($event['email'] ?? '') ?: 'no-email', anonymize_audit_reference($event['voter_identifier'] ?? '') ?: 'no-id', $event['ip_address'] ?: 'no-ip', $event['action_attempted'])];
    return $lines;
}
function pdf_safe($text) { $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text); $text = $converted === false ? $text : $converted; return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text); }
function send_simple_pdf($lines, $filename) {
    $pages = []; $page = []; $lineCount = 0;
    foreach ($lines as [$type, $line]) { foreach (wordwrap($line, 96, "\n", true) ? explode("\n", wordwrap($line, 96, "\n", true)) : [''] as $wrapped) { if ($lineCount >= 48) { $pages[] = $page; $page = []; $lineCount = 0; } $page[] = [$type, $wrapped]; $lineCount++; } $lineCount++; }
    if ($page) $pages[] = $page; $objects = []; $pageIds = []; $fontId = 3 + count($pages) * 2;
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>'; $kids = [];
    foreach ($pages as $i => $pageLines) { $pageId = 3 + $i * 2; $contentId = $pageId + 1; $pageIds[] = $pageId . ' 0 R'; $stream = "BT\n/F1 10 Tf\n50 790 Td\n"; foreach ($pageLines as [$type, $line]) { $size = $type === 'title' ? 17 : ($type === 'heading' ? 13 : ($type === 'subheading' ? 11 : 10)); $stream .= '/F1 ' . $size . " Tf\n(" . pdf_safe($line) . ") Tj\n0 -15 Td\n"; } $stream .= "ET"; $objects[$pageId - 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 $fontId 0 R >> >> /Contents $contentId 0 R >>"; $objects[$contentId - 1] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream"; }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . count($pages) . ' >>'; $objects[$fontId - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'; ksort($objects); $pdf = "%PDF-1.4\n"; $offsets = [0]; foreach ($objects as $id => $object) { $offsets[$id + 1] = strlen($pdf); $pdf .= ($id + 1) . " 0 obj\n$object\nendobj\n"; } $xref = strlen($pdf); $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n"; for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n"; $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $filename . '.pdf"'); header('Content-Length: ' . strlen($pdf)); echo $pdf;
}
function send_docx($lines, $filename) {
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('DOCX export requires the PHP ZIP extension.'); }
    $escape = fn($value) => htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); $body = '';
    foreach ($lines as [$type, $line]) { $size = $type === 'title' ? '36' : ($type === 'heading' ? '28' : ($type === 'subheading' ? '23' : '20')); $bold = $type !== 'text' ? '<w:b/>' : ''; $body .= '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr><w:r><w:rPr>' . $bold . '<w:sz w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . $escape($line) . '</w:t></w:r></w:p>'; }
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080"/></w:sectPr></w:body></w:document>';
    $tmp = tempnam(sys_get_temp_dir(), 'smartvote_report_'); $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::OVERWRITE); $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'); $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'); $zip->addFromString('word/document.xml', $document); $zip->close(); header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'); header('Content-Disposition: attachment; filename="' . $filename . '.docx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); unlink($tmp);
}

$id = (int)($_GET['id'] ?? 0); $data = election_report_data($pdo, $id); $format = strtolower($_GET['format'] ?? '');
if (!$data) { http_response_code(404); exit('Election not found.'); } $election = $data['election']; $owner = (int)$election['created_by'] === (int)$_SESSION['user_id'];
if (!(isAdmin() || $owner || is_active_auditor_for_election($pdo, $id, $_SESSION['user_id'], $_SESSION['email'] ?? ''))) { http_response_code(403); exit('You do not have access to this report.'); }
$filename = 'SmartVote_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $election['code'] ?? ('Election_' . $id)) . '_Audit_Report';
if ($format === 'pdf') send_simple_pdf(report_lines($data), $filename); elseif ($format === 'docx') send_docx(report_lines($data), $filename); else { http_response_code(400); exit('Choose PDF or DOCX format.'); }
