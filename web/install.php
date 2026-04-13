<?php
$checks = [];

$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = ['label' => 'PHP ' . PHP_VERSION, 'ok' => $phpOk, 'note' => $phpOk ? 'Good.' : 'Need PHP 7.4+'];

$curlOk = function_exists('curl_init');
$checks[] = ['label' => 'PHP cURL', 'ok' => $curlOk, 'note' => $curlOk ? 'Enabled.' : 'Enable php-curl extension'];

$shellOk = function_exists('shell_exec');
$checks[] = ['label' => 'shell_exec', 'ok' => $shellOk, 'note' => $shellOk ? 'Enabled.' : 'Enable shell_exec in php.ini'];

$pythonPath = trim(shell_exec('which python3 2>/dev/null') ?: '');
$pythonOk   = !empty($pythonPath);
$checks[] = ['label' => 'Python 3', 'ok' => $pythonOk, 'note' => $pythonOk ? trim(shell_exec('python3 --version 2>&1')) . ' at ' . $pythonPath : 'Install Python 3'];

$scriptPath = realpath(__DIR__ . '/../checker.py');
$scriptOk   = $scriptPath && file_exists($scriptPath);
$checks[] = ['label' => 'checker.py', 'ok' => $scriptOk, 'note' => $scriptOk ? 'Found.' : 'web/ must be inside the dvla_mot_checker/ folder'];

$requestsOk = $pythonOk && trim(shell_exec('python3 -c "import requests; print(\'ok\')" 2>/dev/null') ?? '') === 'ok';
$checks[] = ['label' => 'Python: requests', 'ok' => $requestsOk, 'note' => $requestsOk ? 'Installed.' : 'Run: pip3 install requests'];

$uploadsDir = __DIR__ . '/uploads/';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
$uploadsOk = is_writable($uploadsDir);
$checks[] = ['label' => 'Uploads folder', 'ok' => $uploadsOk, 'note' => $uploadsOk ? 'Writable.' : 'chmod 755 web/uploads'];

// API key test
$apiKey    = trim($_POST['api_key'] ?? '');
$apiKeySet = !empty($apiKey);
$apiKeyOk  = false;
$apiNote   = 'Enter your free DVLA API key below';

if ($apiKeySet && $curlOk) {
    $ch = curl_init('https://driver-vehicle-licensing.api.gov.uk/vehicle-enquiry/v1/vehicles');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['registrationNumber' => 'BD65WYA']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $apiKeyOk = in_array($code, [200, 404]); // 404 = valid key, vehicle just not found
    $apiNote  = $apiKeyOk ? 'API key verified ✓' : "Key test returned HTTP $code — check the key is correct";
}
$checks[] = ['label' => 'DVLA API Key', 'ok' => $apiKeyOk, 'note' => $apiNote];

$allOk = $phpOk && $curlOk && $shellOk && $pythonOk && $scriptOk && $requestsOk && $uploadsOk && $apiKeyOk;

if ($allOk) {
    $cfg = "<?php\ndefine('DVLA_API_KEY', " . var_export($apiKey, true) . ");\ndefine('PYTHON_PATH', " . var_export($pythonPath, true) . ");\ndefine('SCRIPT_PATH', " . var_export($scriptPath, true) . ");\ndefine('UPLOAD_DIR', " . var_export($uploadsDir, true) . ");\n";
    file_put_contents(__DIR__ . '/config.php', $cfg);
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>DVLA MOT Checker — Setup</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="topbar"><div><h1>DVLA MOT Fleet Checker — Setup</h1><div class="sub">One-time setup</div></div></div>
<div class="container" style="max-width:680px">

<div class="card">
  <h2>System Check</h2>
  <?php foreach ($checks as $c): ?>
  <div class="install-step">
    <div class="step-num <?= $c['ok'] ? 'done' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✗' ?></div>
    <div class="step-content"><div class="step-title"><?= htmlspecialchars($c['label']) ?></div><div class="step-desc"><?= htmlspecialchars($c['note']) ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>DVLA API Key</h2>
  <p class="text-muted" style="font-size:13px;margin-bottom:14px">
    Get a free key from <a href="https://developer-portal.driver-vehicle-licensing.agency.gov.uk/" target="_blank">developer-portal.driver-vehicle-licensing.agency.gov.uk</a> — register, create an app, subscribe to the Vehicle Enquiry API (free tier). Takes about 5 minutes.
  </p>
  <form method="POST">
    <label>DVLA API Key</label>
    <div style="display:flex;gap:10px;margin-top:6px">
      <input type="text" name="api_key" value="<?= htmlspecialchars($apiKey) ?>" placeholder="Paste your API key here" style="flex:1">
      <button type="submit" class="btn btn-primary">Test &amp; Save</button>
    </div>
  </form>
  <?php if ($allOk): ?>
  <div class="alert alert-ok" style="margin-top:14px">✓ All set. <a href="index.php">→ Open the checker</a></div>
  <?php elseif ($apiKeySet && !$apiKeyOk): ?>
  <div class="alert alert-error" style="margin-top:14px">API key check failed — make sure you copied it correctly.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>How to use</h2>
  <ol style="padding-left:18px;color:#94a3b8;font-size:13px;line-height:2">
    <li>Complete setup above — get all ticks — click Open the checker</li>
    <li>Paste reg numbers (one per line) or upload a CSV file</li>
    <li>Set how many months ahead to flag as expiring soon (default 3)</li>
    <li>Click Check Fleet — results sort by urgency (expired first)</li>
    <li>Red = expired, orange = expiring soon, green = fine</li>
    <li>CSV columns supported: reg, make, model (headers auto-detected)</li>
  </ol>
</div>

</div></body></html>
