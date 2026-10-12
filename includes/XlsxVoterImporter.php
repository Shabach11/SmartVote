<?php
/** Read only spreadsheet cell values with PhpSpreadsheet; macros are never executed. */
function read_voter_xlsx($path) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PhpSpreadsheet is not installed. Run composer install before importing voters.');
    }
    require_once $autoload;
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    return $spreadsheet->getActiveSheet()->toArray('', true, true, false);
}

function scan_voter_spreadsheet($file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new Exception('Upload an eligible-voters Excel file before scanning.');
    }
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls'], true) || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception('Eligible-voters file must be a valid .xlsx or .xls file no larger than 5 MB.');
    }
    try {
        $rows = read_voter_xlsx($file['tmp_name']);
    } catch (Throwable $exception) {
        throw new Exception('The uploaded file is not a valid Excel spreadsheet.');
    }
    if (!$rows) throw new Exception('The eligible-voters spreadsheet is empty.');

    $headers = [];
    foreach ($rows[0] as $index => $value) {
        $header = trim(preg_replace('/\s+/', ' ', (string)$value));
        if ($header !== '') $headers[$index] = $header;
    }
    if (!$headers) throw new Exception('The spreadsheet has no valid column headers in its first row.');
    $normalizedHeaders = array_map(function ($header) {
        return strtolower(trim(preg_replace('/\s+/', ' ', $header)));
    }, $headers);
    if (count($normalizedHeaders) !== count(array_unique($normalizedHeaders))) {
        throw new Exception('The spreadsheet contains duplicate column headers. Rename them and scan again.');
    }

    $data = [];
    foreach (array_slice($rows, 1) as $row) {
        $cleanRow = [];
        foreach ($headers as $index => $header) $cleanRow[] = trim((string)($row[$index] ?? ''));
        if (count(array_filter($cleanRow, fn($value) => $value !== '')) > 0) $data[] = $cleanRow;
    }
    if (!$data) throw new Exception('The eligible-voters spreadsheet contains no voter rows.');
    return ['headers' => array_values($headers), 'rows' => array_values($data), 'filename' => basename($file['name'])];
}
