<?php
header('Content-Type: application/json');
if (!file_exists(__DIR__ . '/config.php')) { echo json_encode(['error' => 'Run install.php first']); exit; }
require __DIR__ . '/config.php';

$sample = isset($_GET['sample']);

if ($sample) {
    $csvPath = realpath(__DIR__ . '/../sample_fleet.csv');
    if (!$csvPath || !file_exists($csvPath)) {
        echo json_encode(['error' => 'Sample file not found']); exit;
    }
    $tmpFile = $csvPath;
    $cleanup = false;
} elseif (!empty($_FILES['csv']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'])) {
        echo json_encode(['error' => 'Only .csv files supported']); exit;
    }
    if ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
        echo json_encode(['error' => 'File too large (max 2MB)']); exit;
    }
    $tmpFile = UPLOAD_DIR . 'fleet_' . bin2hex(random_bytes(8)) . '.csv';
    move_uploaded_file($_FILES['csv']['tmp_name'], $tmpFile);
    $cleanup = true;
} elseif (!empty($_POST['regs'])) {
    // Pasted regs — write to temp CSV
    $lines = preg_split('/[\r\n,]+/', trim($_POST['regs']));
    $lines = array_filter(array_map('trim', $lines));
    if (empty($lines)) {
        echo json_encode(['error' => 'No registrations provided']); exit;
    }
    $tmpFile = UPLOAD_DIR . 'fleet_' . bin2hex(random_bytes(8)) . '.csv';
    $fp = fopen($tmpFile, 'w');
    fputcsv($fp, ['reg']);
    foreach ($lines as $line) fputcsv($fp, [$line]);
    fclose($fp);
    $cleanup = true;
} else {
    echo json_encode(['error' => 'No input provided']); exit;
}

$threshold = max(1, min(12, (int)($_POST['threshold'] ?? $_GET['threshold'] ?? 3)));
$sleep     = 0.3; // faster in web context

$escapedFile = escapeshellarg($tmpFile);
$escapedKey  = escapeshellarg(DVLA_API_KEY);

$cmd = PYTHON_PATH . ' ' . escapeshellarg(SCRIPT_PATH)
     . ' ' . $escapedFile
     . ' --key ' . $escapedKey
     . ' --threshold ' . (int)$threshold
     . ' --sleep ' . $sleep
     . ' --format json 2>&1';

$output = shell_exec($cmd);

if ($cleanup && file_exists($tmpFile)) unlink($tmpFile);

$data = json_decode($output, true);
if (!$data) {
    echo json_encode(['error' => 'Script error: ' . substr($output, 0, 300)]); exit;
}

echo json_encode($data);
