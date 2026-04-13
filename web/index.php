<?php
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
require __DIR__ . '/config.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DVLA MOT Fleet Checker</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  @media (max-width: 700px) { .two-col { grid-template-columns: 1fr; } }
  .or-divider { display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: 12px; margin: 16px 0; }
  .or-divider::before, .or-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .bucket-expired  { color: var(--red);    font-weight: 700; }
  .bucket-expiring { color: var(--orange); font-weight: 700; }
  .bucket-ok       { color: var(--green); }
  .bucket-new      { color: var(--blue); }
  .bucket-error    { color: var(--muted); }
</style>
</head>
<body>

<div class="topbar">
  <div><h1>DVLA MOT Fleet Checker</h1><div class="sub">Bulk MOT &amp; tax status check</div></div>
  <div><a href="install.php" class="text-muted" style="font-size:12px">Setup</a></div>
</div>

<div class="container">

  <div class="card">
    <h2>Check Fleet</h2>

    <div class="two-col">
      <div>
        <label>Paste registrations (one per line)</label>
        <textarea id="regs-input" rows="8" placeholder="BD65WYA&#10;LK68HJO&#10;YE18XBF&#10;..."></textarea>
      </div>
      <div>
        <label>Or upload a CSV file</label>
        <div class="drop-zone" id="drop-zone" onclick="document.getElementById('csv-input').click()">
          <div class="icon">📂</div>
          <div id="drop-label">Drop CSV here or click to browse</div>
          <p>Columns: reg, make, model (headers auto-detected)</p>
        </div>
        <input type="file" id="csv-input" accept=".csv,.txt">
      </div>
    </div>

    <div class="form-row" style="margin-top:16px">
      <div style="flex:1;min-width:160px">
        <label>Warn if expiring within (months)</label>
        <select id="threshold">
          <option value="1">1 month</option>
          <option value="2">2 months</option>
          <option value="3" selected>3 months</option>
          <option value="6">6 months</option>
          <option value="12">12 months</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;align-items:flex-end">
        <button class="btn btn-primary" id="check-btn" onclick="runCheck()">Check Fleet</button>
        <button class="btn btn-secondary" onclick="runSample()">Try Sample Fleet</button>
      </div>
    </div>
  </div>

  <div class="spinner" id="spinner">⏳ Checking vehicles against DVLA... this may take a moment for large fleets.</div>

  <div id="results">

    <div class="stat-grid" id="stats"></div>

    <div class="card">
      <h2>Results</h2>
      <table>
        <thead>
          <tr>
            <th>Reg</th>
            <th>Make</th>
            <th>Colour</th>
            <th>MOT Expiry</th>
            <th>MOT Status</th>
            <th>Tax Status</th>
            <th>Tax Due</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="results-body"></tbody>
      </table>
    </div>

  </div>

</div>

<script>
let selectedFile = null;

document.getElementById('csv-input').addEventListener('change', function() {
  if (this.files[0]) {
    selectedFile = this.files[0];
    document.getElementById('drop-label').textContent = '📄 ' + this.files[0].name;
    document.getElementById('regs-input').value = '';
  }
});

const dz = document.getElementById('drop-zone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (f) {
    selectedFile = f;
    document.getElementById('drop-label').textContent = '📄 ' + f.name;
    document.getElementById('regs-input').value = '';
  }
});

function runSample() {
  setLoading(true);
  fetch('process.php?sample=1')
    .then(r => r.json())
    .then(showResults)
    .catch(e => showError(e.message))
    .finally(() => setLoading(false));
}

function runCheck() {
  const regs = document.getElementById('regs-input').value.trim();
  const threshold = document.getElementById('threshold').value;

  if (!regs && !selectedFile) {
    alert('Paste some registrations or upload a CSV first.');
    return;
  }

  setLoading(true);
  const fd = new FormData();
  fd.append('threshold', threshold);

  if (selectedFile) {
    fd.append('csv', selectedFile);
  } else {
    fd.append('regs', regs);
  }

  fetch('process.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(showResults)
    .catch(e => showError(e.message))
    .finally(() => setLoading(false));
}

function setLoading(on) {
  document.getElementById('spinner').classList.toggle('show', on);
  document.getElementById('results').classList.remove('show');
  document.getElementById('check-btn').disabled = on;
}

function showError(msg) {
  document.getElementById('results').classList.add('show');
  document.getElementById('stats').innerHTML = `<div class="alert alert-error">${msg}</div>`;
  document.getElementById('results-body').innerHTML = '';
}

function bucketClass(bucket) {
  const b = (bucket || '').toUpperCase();
  if (b === 'EXPIRED')           return 'bucket-expired';
  if (b.startsWith('EXPIRING'))  return 'bucket-expiring';
  if (b === 'OK')                return 'bucket-ok';
  if (b.startsWith('NEW'))       return 'bucket-new';
  return 'bucket-error';
}

function rowClass(bucket) {
  const b = (bucket || '').toUpperCase();
  if (b === 'EXPIRED')           return 'row-red';
  if (b.startsWith('EXPIRING'))  return 'row-orange';
  if (b === 'OK')                return 'row-green';
  if (b.startsWith('NEW'))       return 'row-blue';
  return 'row-grey';
}

function showResults(data) {
  if (data.error) { showError(data.error); return; }

  const s = data.summary || {};
  const statsEl = document.getElementById('stats');
  statsEl.innerHTML = `
    <div class="stat"><div class="val">${s.total || 0}</div><div class="lbl">Total</div></div>
    <div class="stat"><div class="val text-red">${s.expired || 0}</div><div class="lbl">Expired</div></div>
    <div class="stat"><div class="val text-orange">${s.expiring_soon || 0}</div><div class="lbl">Expiring Soon</div></div>
    <div class="stat"><div class="val text-green">${s.ok || 0}</div><div class="lbl">OK</div></div>
    <div class="stat"><div class="val text-blue">${s.new_vehicle || 0}</div><div class="lbl">New (no MOT)</div></div>
    <div class="stat"><div class="val text-muted">${s.errors || 0}</div><div class="lbl">Errors</div></div>
  `;

  const tbody = document.getElementById('results-body');
  tbody.innerHTML = (data.vehicles || []).map(v => {
    const make  = v.dvla_make || v.sheet_make || '—';
    const model = v.sheet_model || '';
    const makeStr = model ? `${make} ${model}` : make;
    return `<tr class="${rowClass(v.bucket)}">
      <td class="mono"><strong>${v.reg}</strong></td>
      <td class="text-muted">${makeStr}</td>
      <td class="text-muted">${v.colour || '—'}</td>
      <td class="mono">${v.mot_expiry || '—'}</td>
      <td class="text-muted" style="font-size:12px">${v.mot_status || '—'}</td>
      <td class="text-muted">${v.tax_status || '—'}</td>
      <td class="mono text-muted">${v.tax_due || '—'}</td>
      <td><span class="${bucketClass(v.bucket)}">${v.bucket || '—'}</span></td>
    </tr>`;
  }).join('');

  document.getElementById('results').classList.add('show');
}
</script>
</body>
</html>
