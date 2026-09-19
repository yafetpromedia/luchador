<?php

declare(strict_types=1);

function student_payload(array $src): array
{
    $size = strtoupper(trim((string) ($src['size'] ?? '')));
    $status = (string) ($src['status'] ?? 'pending');
    $payment = (string) ($src['payment_status'] ?? 'unpaid');
    $allowedSizes = uniform_sizes();
    $allowedStatus = ['pending', 'ordered', 'ready', 'collected'];
    $allowedPayment = ['unpaid', 'partial', 'paid'];
    $section = normalize_class_section((string) ($src['section'] ?? ''));

    return [
        'student_name' => trim((string) ($src['student_name'] ?? '')),
        'phone_number' => trim((string) ($src['phone_number'] ?? '')),
        'mother_name' => trim((string) ($src['mother_name'] ?? '')) ?: null,
        'mother_phone' => trim((string) ($src['mother_phone'] ?? '')) ?: null,
        'father_name' => trim((string) ($src['father_name'] ?? '')) ?: null,
        'father_phone' => trim((string) ($src['father_phone'] ?? '')) ?: null,
        'size' => in_array($size, $allowedSizes, true) ? $size : '',
        'student_code' => trim((string) ($src['student_code'] ?? '')) ?: null,
        'grade' => trim((string) ($src['grade'] ?? class_grade())) ?: class_grade(),
        'section' => $section !== '' ? $section : null,
        'quantity' => max(1, (int) ($src['quantity'] ?? 1)),
        'status' => in_array($status, $allowedStatus, true) ? $status : 'pending',
        'payment_status' => in_array($payment, $allowedPayment, true) ? $payment : 'unpaid',
        'notes' => trim((string) ($src['notes'] ?? '')) ?: null,
    ];
}

function validate_student(array $data): ?string
{
    if ($data['student_name'] === '' || $data['size'] === '') {
        return 'Name and uniform size are required.';
    }
    if ($data['phone_number'] === '' && empty($data['mother_phone']) && empty($data['father_phone'])) {
        return 'Add a student phone, mother phone, or father phone.';
    }
    $section = (string) ($data['section'] ?? '');
    if ($section !== '' && !is_class_section($section)) {
        return 'Section must be Grade 12 Natural Science A, Grade 12 Natural Science B, or Grade 12 Social Science.';
    }
    return null;
}

function save_student(array $src, ?int $id = null, array $files = []): array
{
    $data = student_payload($src);
    $error = validate_student($data);
    if ($error) {
        throw new InvalidArgumentException($error);
    }

    $existing = $id ? student_by_id($id) : null;
    $photo = $existing['photo'] ?? null;
    if (!empty($files['photo']['name'])) {
        $upload = store_upload($files['photo'], 'students');
        if (!$upload['ok']) {
            throw new InvalidArgumentException($upload['error']);
        }
        delete_upload($photo);
        $photo = $upload['path'];
    } elseif (!empty($src['remove_photo'])) {
        delete_upload($photo);
        $photo = null;
    }

    $params = [
        $data['student_name'],
        $data['phone_number'],
        $data['mother_name'],
        $data['mother_phone'],
        $data['father_name'],
        $data['father_phone'],
        $photo,
        $data['size'],
        $data['student_code'],
        $data['grade'],
        $data['section'],
        $data['quantity'],
        $data['status'],
        $data['payment_status'],
        $data['notes'],
    ];

    if ($id) {
        $params[] = $id;
        db()->prepare('UPDATE uniforms SET student_name = ?, phone_number = ?, mother_name = ?, mother_phone = ?, father_name = ?, father_phone = ?, photo = ?, size = ?, student_code = ?, grade = ?, section = ?, quantity = ?, status = ?, payment_status = ?, notes = ? WHERE id = ?')->execute($params);
        $saved = student_by_id($id);
    } else {
        db()->prepare('INSERT INTO uniforms (student_name, phone_number, mother_name, mother_phone, father_name, father_phone, photo, size, student_code, grade, section, quantity, status, payment_status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($params);
        $saved = student_by_id((int) db()->lastInsertId());
    }

    if (!$saved) {
        throw new RuntimeException('Could not save the student record.');
    }
    return $saved;
}

function delete_student_record(int $id): bool
{
    $existing = student_by_id($id);
    if ($existing) {
        delete_upload((string) ($existing['photo'] ?? ''));
    }
    if (table_exists(db(), 'profile_requests')) {
        $pending = db()->prepare('SELECT photo FROM profile_requests WHERE student_id = ?');
        $pending->execute([$id]);
        foreach ($pending->fetchAll() as $row) {
            delete_upload((string) ($row['photo'] ?? ''));
        }
        db()->prepare('DELETE FROM profile_requests WHERE student_id = ?')->execute([$id]);
    }
    if (!function_exists('cleanup_student_payments')) {
        require_once APP_ROOT . '/includes/payments.php';
    }
    cleanup_student_payments($id);
    unlink_student_login($id);
    $stmt = db()->prepare('DELETE FROM uniforms WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function unlink_student_login(int $studentId): void
{
    if ($studentId <= 0 || !column_exists(db(), 'users', 'student_id')) {
        return;
    }
    db()->prepare("UPDATE users SET student_id = NULL, is_active = 0 WHERE student_id = ? AND role = 'student'")->execute([$studentId]);
}

function posted_student_ids(string $key = 'ids'): array
{
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) {
        $raw = preg_split('/[,\s]+/', (string) $raw) ?: [];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function delete_students_by_ids(array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if (count($ids) > 500) {
        throw new InvalidArgumentException('Select 500 students or fewer at a time.');
    }
    $deleted = 0;
    foreach ($ids as $id) {
        if (delete_student_record($id)) {
            $deleted++;
        }
    }
    return $deleted;
}

function student_csv_headers(): array
{
    return array_keys(student_import_columns());
}

function student_import_columns(): array
{
    return [
        'student_name' => 'Full name',
        'phone_number' => 'Student phone',
        'mother_name' => 'Mother name',
        'mother_phone' => 'Mother phone',
        'father_name' => 'Father name',
        'father_phone' => 'Father phone',
        'size' => 'Uniform size',
        'student_code' => 'Student ID',
        'grade' => 'Grade',
        'section' => 'Section',
        'quantity' => 'Quantity',
        'status' => 'Uniform status',
        'payment_status' => 'Payment',
        'notes' => 'Notes',
    ];
}

function student_normalize_import_header(string $header): string
{
    $key = strtolower(trim($header));
    $key = str_replace(['_', '-'], ' ', $key);
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;
    $aliases = [
        'student name' => 'student_name',
        'full name' => 'student_name',
        'name' => 'student_name',
        'phone number' => 'phone_number',
        'student phone' => 'phone_number',
        'phone' => 'phone_number',
        'mobile' => 'phone_number',
        'mother name' => 'mother_name',
        'mother' => 'mother_name',
        'mother phone' => 'mother_phone',
        'father name' => 'father_name',
        'father' => 'father_name',
        'father phone' => 'father_phone',
        'size' => 'size',
        'uniform size' => 'size',
        'uniform' => 'size',
        'student code' => 'student_code',
        'student id' => 'student_code',
        'id' => 'student_code',
        'grade' => 'grade',
        'section' => 'section',
        'quantity' => 'quantity',
        'qty' => 'quantity',
        'status' => 'status',
        'uniform status' => 'status',
        'payment status' => 'payment_status',
        'payment' => 'payment_status',
        'notes' => 'notes',
        'note' => 'notes',
    ];
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    $snake = str_replace(' ', '_', $key);
    return array_key_exists($snake, student_import_columns()) ? $snake : $snake;
}

function student_import_instruction_lines(): array
{
    return [
        'How to import students',
        '1. Fill the Students sheet. Keep the header row. Do not rename the columns.',
        '2. Required: Full name, Uniform size, and at least one phone (student, mother, or father).',
        '3. Uniform size must be one of: ' . implode(', ', uniform_sizes()) . '.',
        '4. Section (optional) must be one of: ' . implode('; ', class_sections()) . '.',
        '5. Uniform status (optional): pending, ordered, ready, collected. Default is pending.',
        '6. Payment (optional): unpaid, partial, paid. Default is unpaid.',
        '7. Format phone columns as Text in Excel so zeros are not dropped.',
        '8. Existing students are never overwritten. Matching is by Student ID, or by full name + student phone.',
        '9. Save as Excel (.xlsx) or CSV, then upload it on the Students import page.',
        '10. Leave unused optional columns blank. Do not invent names just to fill the sheet.',
    ];
}

function send_student_import_csv(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="luchadore-students-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values(student_import_columns()));
    fclose($out);
    exit;
}

function xlsx_cell_ref(int $col, int $row): string
{
    $col++;
    $letters = '';
    while ($col > 0) {
        $col--;
        $letters = chr(65 + ($col % 26)) . $letters;
        $col = intdiv($col, 26);
    }
    return $letters . $row;
}

function xlsx_inline_cell(int $col, int $row, string $value, string $style = '1'): string
{
    $ref = xlsx_cell_ref($col, $row);
    $text = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . $text . '</t></is></c>';
}

function send_student_import_xlsx(): void
{
    if (!class_exists(ZipArchive::class)) {
        send_student_import_csv();
    }
    $labels = array_values(student_import_columns());
    $headerCells = '';
    foreach ($labels as $i => $label) {
        $headerCells .= xlsx_inline_cell($i, 1, $label, '2');
    }
    $phoneCols = '';
    foreach ([1, 3, 5] as $col) {
        $phoneCols .= '<col min="' . ($col + 1) . '" max="' . ($col + 1) . '" width="16" style="1" customWidth="1"/>';
    }
    $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="1" width="22" customWidth="1"/>' . $phoneCols . '</cols>'
        . '<sheetData><row r="1">' . $headerCells . '</row></sheetData>'
        . '</worksheet>';

    $instructionRows = '';
    foreach (student_import_instruction_lines() as $i => $line) {
        $r = $i + 1;
        $style = $i === 0 ? '2' : '0';
        $instructionRows .= '<row r="' . $r . '">' . xlsx_inline_cell(0, $r, $line, $style) . '</row>';
    }
    $sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="1" width="110" customWidth="1"/></cols>'
        . '<sheetData>' . $instructionRows . '</sheetData>'
        . '</worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>'
        . '<sheet name="Students" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Instructions" sheetId="2" r:id="rId2"/>'
        . '</sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs></styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'lchxlsx');
    if ($tmp === false) {
        send_student_import_csv();
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        send_student_import_csv();
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="luchadore-students-template.xlsx"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function xlsx_col_index(string $ref): int
{
    $letters = strtoupper(preg_replace('/[^A-Z]/', '', $ref) ?? '');
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}

function parse_xlsx_shared_strings(string $xml): array
{
    $out = [];
    if ($xml === '' || !preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si', $xml, $matches)) {
        return $out;
    }
    foreach ($matches[1] as $block) {
        if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/si', $block, $texts)) {
            $out[] = html_entity_decode(implode('', $texts[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        } else {
            $out[] = '';
        }
    }
    return $out;
}

function parse_xlsx_sheet_rows(string $xml, array $shared): array
{
    $rows = [];
    if (!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si', $xml, $rowMatches)) {
        return $rows;
    }
    foreach ($rowMatches[1] as $rowXml) {
        $cells = [];
        if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/si', $rowXml, $cellMatches, PREG_SET_ORDER)) {
            foreach ($cellMatches as $cell) {
                $attrs = $cell[1];
                $inner = $cell[2];
                $ref = '';
                if (preg_match('/\br="([^"]+)"/', $attrs, $m)) {
                    $ref = $m[1];
                }
                $type = preg_match('/\bt="([^"]+)"/', $attrs, $m) ? $m[1] : '';
                $value = '';
                if ($type === 'inlineStr' && preg_match('/<t(?:\s[^>]*)?>(.*?)<\/t>/si', $inner, $m)) {
                    $value = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                } elseif ($type === 's' && preg_match('/<v>(.*?)<\/v>/si', $inner, $m)) {
                    $value = $shared[(int) $m[1]] ?? '';
                } elseif (preg_match('/<v>(.*?)<\/v>/si', $inner, $m)) {
                    $value = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                }
                $idx = $ref !== '' ? xlsx_col_index($ref) : count($cells);
                $cells[$idx] = trim($value);
            }
        }
        if (!$cells) {
            continue;
        }
        ksort($cells);
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }
    return $rows;
}

function parse_student_xlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new InvalidArgumentException('Excel import needs the Zip PHP extension. Save the file as CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('The Excel file could not be read.');
    }
    $shared = parse_xlsx_shared_strings((string) $zip->getFromName('xl/sharedStrings.xml'));
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
    $rid = '';
    if (preg_match('/<sheet[^>]*name="Students"[^>]*r:id="([^"]+)"/i', $workbook, $m)
        || preg_match('/<sheet[^>]*r:id="([^"]+)"[^>]*name="Students"/i', $workbook, $m)) {
        $rid = $m[1];
    } elseif (preg_match('/<sheet[^>]*r:id="([^"]+)"/', $workbook, $m)) {
        $rid = $m[1];
    }
    if ($rid !== '' && preg_match('/Id="' . preg_quote($rid, '/') . '"[^>]*Target="([^"]+)"/', $rels, $m)) {
        $target = ltrim(str_replace('\\', '/', $m[1]), '/');
        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }
    $sheetXml = (string) $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === '') {
        throw new InvalidArgumentException('The Excel file has no Students sheet.');
    }
    $grid = parse_xlsx_sheet_rows($sheetXml, $shared);
    if (!$grid) {
        throw new InvalidArgumentException('The Excel file is empty.');
    }
    $headers = array_shift($grid);
    $rows = [];
    $line = 1;
    foreach ($grid as $row) {
        $line++;
        $rows[$line] = $row;
    }
    return process_student_import_rows($headers, $rows);
}

function process_student_import_rows(array $rawHeaders, array $rowsByLine): array
{
    $headers = [];
    foreach ($rawHeaders as $header) {
        $headers[] = student_normalize_import_header((string) $header);
    }
    $required = ['student_name', 'size'];
    foreach ($required as $col) {
        if (!in_array($col, $headers, true)) {
            throw new InvalidArgumentException('The file must include Full name and Uniform size columns.');
        }
    }
    $hasPhoneCol = (bool) array_intersect($headers, ['phone_number', 'mother_phone', 'father_phone']);
    if (!$hasPhoneCol) {
        throw new InvalidArgumentException('The file must include a Student phone, Mother phone, or Father phone column.');
    }

    $valid = [];
    $errors = [];
    $seenCodes = [];
    $seenPhones = [];
    $existing = student_duplicate_index();
    foreach ($rowsByLine as $line => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $assoc = [];
        foreach ($headers as $i => $header) {
            if ($header === '') {
                continue;
            }
            $assoc[$header] = trim((string) ($row[$i] ?? ''));
        }
        $payload = student_payload($assoc);
        $error = validate_student($payload);
        if ($error) {
            $errors[] = ['line' => (int) $line, 'message' => $error, 'name' => $payload['student_name'], 'type' => 'invalid'];
            continue;
        }
        $codeKey = strtolower((string) ($payload['student_code'] ?? ''));
        $phoneKey = strtolower($payload['student_name'] . '|' . $payload['phone_number']);
        if ($codeKey !== '' && (isset($existing['codes'][$codeKey]) || isset($seenCodes[$codeKey]))) {
            $errors[] = ['line' => (int) $line, 'message' => 'Student ID already exists. Existing records were not changed.', 'name' => $payload['student_name'], 'type' => 'duplicate'];
            continue;
        }
        if (isset($existing['phones'][$phoneKey]) || isset($seenPhones[$phoneKey])) {
            $errors[] = ['line' => (int) $line, 'message' => 'A student with this name and phone already exists. Existing records were not changed.', 'name' => $payload['student_name'], 'type' => 'duplicate'];
            continue;
        }
        if ($codeKey !== '') {
            $seenCodes[$codeKey] = true;
        }
        $seenPhones[$phoneKey] = true;
        $payload['_line'] = (int) $line;
        $valid[] = $payload;
        if (count($valid) + count($errors) >= 500) {
            $errors[] = ['line' => (int) $line, 'message' => 'Import stopped at 500 data rows.', 'name' => '', 'type' => 'invalid'];
            break;
        }
    }
    return ['valid' => $valid, 'errors' => $errors];
}

function parse_student_import_file(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return parse_student_xlsx($path);
    }
    if ($ext !== 'csv') {
        throw new InvalidArgumentException('Upload an Excel .xlsx file or a .csv file.');
    }
    return parse_student_csv($path);
}

function student_duplicate_index(): array
{
    $codes = [];
    $phones = [];
    foreach (db()->query('SELECT student_name, phone_number, student_code FROM uniforms')->fetchAll() as $row) {
        $code = strtolower(trim((string) ($row['student_code'] ?? '')));
        if ($code !== '') {
            $codes[$code] = true;
        }
        $phones[strtolower(trim((string) $row['student_name']) . '|' . trim((string) $row['phone_number']))] = true;
    }
    return ['codes' => $codes, 'phones' => $phones];
}

function parse_student_csv(string $path): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new InvalidArgumentException('The CSV file could not be read.');
    }
    $first = fgets($handle);
    if ($first === false) {
        fclose($handle);
        throw new InvalidArgumentException('The CSV file is empty.');
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
    $headers = str_getcsv($first);
    $rows = [];
    $line = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows[$line] = $row;
    }
    fclose($handle);
    return process_student_import_rows($headers, $rows);
}

function import_students(array $rows): int
{
    $count = 0;
    $existing = student_duplicate_index();
    foreach ($rows as $row) {
        $payload = student_payload($row);
        if (validate_student($payload)) {
            continue;
        }
        $codeKey = strtolower((string) ($payload['student_code'] ?? ''));
        $phoneKey = strtolower($payload['student_name'] . '|' . $payload['phone_number']);
        if ($codeKey !== '' && isset($existing['codes'][$codeKey])) {
            continue;
        }
        if (isset($existing['phones'][$phoneKey])) {
            continue;
        }
        save_student($payload);
        if ($codeKey !== '') {
            $existing['codes'][$codeKey] = true;
        }
        $existing['phones'][$phoneKey] = true;
        $count++;
    }
    return $count;
}

function student_photo_url(?array $row): string
{
    $path = trim((string) ($row['photo'] ?? ''));
    return $path !== '' ? url($path) : '';
}

function student_view_payload(array $row): array
{
    $photo = student_photo_url($row);
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['student_name'] ?? ''),
        'initials' => person_initials((string) ($row['student_name'] ?? '')),
        'photo' => $photo,
        'code' => trim((string) ($row['student_code'] ?? '')),
        'student_phone' => trim((string) ($row['phone_number'] ?? '')),
        'mother_name' => trim((string) ($row['mother_name'] ?? '')),
        'mother_phone' => trim((string) ($row['mother_phone'] ?? '')),
        'father_name' => trim((string) ($row['father_name'] ?? '')),
        'father_phone' => trim((string) ($row['father_phone'] ?? '')),
        'grade' => trim((string) ($row['grade'] ?? '')) ?: class_grade(),
        'section' => trim((string) ($row['section'] ?? '')),
        'size' => trim((string) ($row['size'] ?? '')),
        'quantity' => (int) ($row['quantity'] ?? 1),
        'status' => (string) ($row['status'] ?? 'pending'),
        'status_label' => status_label((string) ($row['status'] ?? 'pending')),
        'payment' => (string) ($row['payment_status'] ?? 'unpaid'),
        'payment_label' => status_label((string) ($row['payment_status'] ?? 'unpaid')),
        'notes' => trim((string) ($row['notes'] ?? '')),
        'created' => !empty($row['created_at']) ? format_when((string) $row['created_at']) : '',
        'updated' => !empty($row['updated_at']) ? format_when((string) $row['updated_at']) : '',
        'edit_url' => can('students.edit') ? url('admin/students.php?edit=' . (int) ($row['id'] ?? 0)) : '',
        'can_delete' => can('students.delete'),
    ];
}

function profile_request_fields(array $src): array
{
    return [
        'phone_number' => trim((string) ($src['phone_number'] ?? '')),
        'mother_name' => trim((string) ($src['mother_name'] ?? '')),
        'mother_phone' => trim((string) ($src['mother_phone'] ?? '')),
        'father_name' => trim((string) ($src['father_name'] ?? '')),
        'father_phone' => trim((string) ($src['father_phone'] ?? '')),
    ];
}

function pending_profile_request(int $studentId): ?array
{
    if ($studentId <= 0 || !table_exists(db(), 'profile_requests')) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM profile_requests WHERE student_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pending_profile_requests(): array
{
    if (!table_exists(db(), 'profile_requests')) {
        return [];
    }
    return db()->query(
        "SELECT r.*, s.student_name, s.student_code, s.photo AS current_photo, s.phone_number AS current_phone,
                s.mother_name AS current_mother_name, s.mother_phone AS current_mother_phone,
                s.father_name AS current_father_name, s.father_phone AS current_father_phone
         FROM profile_requests r
         INNER JOIN uniforms s ON s.id = r.student_id
         WHERE r.status = 'pending'
         ORDER BY r.id ASC"
    )->fetchAll();
}

function pending_profile_student_ids(): array
{
    $ids = [];
    foreach (pending_profile_requests() as $row) {
        $ids[(int) $row['student_id']] = true;
    }
    return $ids;
}

function pending_profile_request_count(): int
{
    if (!table_exists(db(), 'profile_requests')) {
        return 0;
    }
    return (int) db()->query("SELECT COUNT(*) FROM profile_requests WHERE status = 'pending'")->fetchColumn();
}

function submit_profile_request(array $student, array $src, array $files, int $userId): array
{
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId <= 0) {
        throw new InvalidArgumentException('Your class record is not linked yet.');
    }
    $data = profile_request_fields($src);
    if ($data['phone_number'] === '' && $data['mother_phone'] === '' && $data['father_phone'] === '') {
        throw new InvalidArgumentException('Add a student phone, mother phone, or father phone.');
    }

    $existing = pending_profile_request($studentId);
    $photo = $existing['photo'] ?? null;
    $removePhoto = !empty($src['remove_photo']);
    if (!empty($files['photo']['name'])) {
        $upload = store_upload($files['photo'], 'profile-requests');
        if (!$upload['ok']) {
            throw new InvalidArgumentException($upload['error']);
        }
        delete_upload((string) $photo);
        $photo = $upload['path'];
        $removePhoto = false;
    } elseif ($removePhoto) {
        delete_upload((string) $photo);
        $photo = null;
    }

    $currentPhoto = trim((string) ($student['photo'] ?? ''));
    $changed = $data['phone_number'] !== trim((string) ($student['phone_number'] ?? ''))
        || $data['mother_name'] !== trim((string) ($student['mother_name'] ?? ''))
        || $data['mother_phone'] !== trim((string) ($student['mother_phone'] ?? ''))
        || $data['father_name'] !== trim((string) ($student['father_name'] ?? ''))
        || $data['father_phone'] !== trim((string) ($student['father_phone'] ?? ''))
        || $photo !== null
        || ($removePhoto && $currentPhoto !== '');
    if (!$changed) {
        throw new InvalidArgumentException('Change a phone, a parent name, or the photo before sending.');
    }

    $params = [
        $data['phone_number'] ?: null,
        $data['mother_name'] ?: null,
        $data['mother_phone'] ?: null,
        $data['father_name'] ?: null,
        $data['father_phone'] ?: null,
        $photo,
        $removePhoto ? 1 : 0,
        $userId,
        $studentId,
    ];
    if ($existing) {
        $params[] = (int) $existing['id'];
        db()->prepare(
            "UPDATE profile_requests
             SET phone_number=?, mother_name=?, mother_phone=?, father_name=?, father_phone=?, photo=?, remove_photo=?, user_id=?, status='pending', note=NULL, reviewed_by=NULL, reviewed_at=NULL
             WHERE id=? AND student_id=?"
        )->execute([
            $params[0], $params[1], $params[2], $params[3], $params[4], $params[5], $params[6], $params[7],
            (int) $existing['id'],
            $studentId,
        ]);
        $id = (int) $existing['id'];
    } else {
        db()->prepare(
            'INSERT INTO profile_requests (phone_number, mother_name, mother_phone, father_name, father_phone, photo, remove_photo, user_id, student_id, status)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $params[0], $params[1], $params[2], $params[3], $params[4], $params[5], $params[6], $params[7], $studentId, 'pending',
        ]);
        $id = (int) db()->lastInsertId();
    }

    $saved = pending_profile_request($studentId);
    if (!$saved) {
        throw new RuntimeException('Could not save the profile request.');
    }
    $saved['id'] = $id;
    return $saved;
}

function cancel_profile_request(int $studentId, int $userId): void
{
    $existing = pending_profile_request($studentId);
    if (!$existing) {
        throw new InvalidArgumentException('There is no profile update waiting.');
    }
    if ((int) ($existing['user_id'] ?? 0) !== $userId && !can('students.edit')) {
        throw new InvalidArgumentException('You can only withdraw your own update.');
    }
    delete_upload((string) ($existing['photo'] ?? ''));
    db()->prepare("UPDATE profile_requests SET status = 'cancelled', reviewed_at = NOW() WHERE id = ?")->execute([(int) $existing['id']]);
}

function approve_profile_request(int $requestId): array
{
    $stmt = db()->prepare("SELECT * FROM profile_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new InvalidArgumentException('That profile update is no longer waiting.');
    }
    $student = student_by_id((int) $request['student_id']);
    if (!$student) {
        throw new InvalidArgumentException('Student record not found.');
    }

    $photo = $student['photo'] ?? null;
    if (!empty($request['remove_photo'])) {
        delete_upload((string) $photo);
        $photo = null;
        delete_upload((string) ($request['photo'] ?? ''));
    } elseif (!empty($request['photo'])) {
        if ((string) $photo !== (string) $request['photo']) {
            delete_upload((string) $photo);
        }
        $photo = $request['photo'];
    }

    db()->prepare(
        'UPDATE uniforms SET phone_number = ?, mother_name = ?, mother_phone = ?, father_name = ?, father_phone = ?, photo = ? WHERE id = ?'
    )->execute([
        $request['phone_number'] ?: null,
        $request['mother_name'] ?: null,
        $request['mother_phone'] ?: null,
        $request['father_name'] ?: null,
        $request['father_phone'] ?: null,
        $photo,
        (int) $student['id'],
    ]);

    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    db()->prepare("UPDATE profile_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), note = NULL WHERE id = ?")
        ->execute([$reviewer, $requestId]);

    $saved = student_by_id((int) $student['id']);
    if (!$saved) {
        throw new RuntimeException('Could not update the student record.');
    }
    return $saved;
}

function reject_profile_request(int $requestId, string $note = ''): void
{
    $stmt = db()->prepare("SELECT * FROM profile_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new InvalidArgumentException('That profile update is no longer waiting.');
    }
    delete_upload((string) ($request['photo'] ?? ''));
    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    $note = excerpt($note, 180);
    db()->prepare("UPDATE profile_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), note = ?, photo = NULL WHERE id = ?")
        ->execute([$reviewer, $note !== '' ? $note : null, $requestId]);
}
