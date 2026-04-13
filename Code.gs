/***********************
 * DVLA MOT CHECKER (FIXED)
 * - Always scans the SAME input sheet (stored by ID)
 * - Extracts ALL regs across the whole sheet
 * - Dedupes regs
 * - Adds Make/Model if columns exist
 * - Runs in batches with triggers until finished
 * - Live progress in results sheet A1
 * - Final sorted output (Expired, Expiring soon, then rest)
 ***********************/

/************** CONFIG **************/
const DVLA_API_KEY = 'PUT_YOUR_KEY_HERE'; // Rotate if previously exposed
const DVLA_URL     = 'https://driver-vehicle-licensing.api.gov.uk/vehicle-enquiry/v1/vehicles';

const MONTHS_THRESHOLD = 3;

// Tune speed/safety
const BATCH_SIZE = 20;        // 15–30 typical safe
const SLEEP_MS   = 600;       // 400–1500 depending on DVLA tolerance
const TRIGGER_MS = 7000;      // time between batches

// Broad UK reg matcher AFTER normalization (keeps older formats too)
const UK_REG_REGEX = /^[A-Z]{1,3}[0-9]{1,4}[A-Z]{0,3}$/;

// Header detection (optional)
const REG_HEADERS   = ['reg', 'reg.', 'registration', 'registration number', 'vrm'];
const MAKE_HEADERS  = ['make'];
const MODEL_HEADERS = ['model'];

// Script Properties keys
const P_QUEUE     = 'DVLA_QUEUE';
const P_INDEX     = 'DVLA_INDEX';
const P_RESULT_ID = 'DVLA_RESULT_ID';
const P_TOTAL     = 'DVLA_TOTAL';
const P_SRC_SSID  = 'DVLA_SOURCE_SPREADSHEET_ID';
const P_SRC_SHEET = 'DVLA_SOURCE_SHEET_ID'; // numeric sheetId (stable)

/************** START / CONTINUE **************/
function checkAllRegsDVLA() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(25000)) return; // prevent overlap if triggers collide

  try {
    const props = PropertiesService.getScriptProperties();

    // First run: initialise
    if (!props.getProperty(P_QUEUE)) {
      initialiseRun_();
      return;
    }

    // Continue: process next batch
    processBatch_();

  } finally {
    lock.releaseLock();
  }
}

/************** RESET (if needed) **************/
function resetDVLA_Run() {
  deleteMyTriggers_();
  PropertiesService.getScriptProperties().deleteAllProperties();
  SpreadsheetApp.getActive().toast('DVLA run reset. Now run checkAllRegsDVLA again.');
}

/************** INITIALISE **************/
function initialiseRun_() {
  deleteMyTriggers_(); // clean start only (safe here)

  const srcSS = SpreadsheetApp.getActiveSpreadsheet();
  const srcSh = srcSS.getActiveSheet();

  const collected = collectAllRegsFromInputSheet_(srcSS.getId(), srcSh.getSheetId());
  if (!collected.items.length) {
    toast_('No registrations found. (Check that regs are actually in the sheet + not images)');
    return;
  }

  // Create results spreadsheet
  const resultSS = SpreadsheetApp.create(
    `MOT Results (${Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd HHmm')})`
  );
  const resultSh = resultSS.getActiveSheet().setName('Results');

  // Row 1 progress + row 2 headers
  resultSh.getRange('A1').setValue(`Progress: 0 / ${collected.items.length} (queued)`);
  resultSh.getRange('A2:K2').setValues([[
    'Reg',
    'Sheet Make',
    'Sheet Model',
    'DVLA Make',
    'Colour',
    'Tax Status',
    'Tax Due',
    'MOT Status',
    'MOT Expiry',
    'Bucket',
    'HTTP'
  ]]);
  resultSh.setFrozenRows(2);

  // Store state
  const props = PropertiesService.getScriptProperties();
  props.setProperties({
    [P_QUEUE]: JSON.stringify(collected.items),    // [{reg, make, model}]
    [P_INDEX]: '0',
    [P_RESULT_ID]: resultSS.getId(),
    [P_TOTAL]: String(collected.items.length),
    [P_SRC_SSID]: srcSS.getId(),
    [P_SRC_SHEET]: String(srcSh.getSheetId())
  });

  // Tell you immediately
  Logger.log('RESULT SHEET URL: ' + resultSS.getUrl());
  SpreadsheetApp.getUi().alert(
    `Found ${collected.items.length} registrations.\n\n` +
    `Results spreadsheet:\n${resultSS.getUrl()}\n\n` +
    `This will continue automatically until all are done.\n\n` +
    `NOTE: If this number should be ~650 and it's much smaller, your regs may be in a different sheet tab or stored as images.`
  );

  // schedule first batch
  createMyTrigger_(TRIGGER_MS);
  toast_(`Queued ${collected.items.length}. Processing...`);
}

/************** BATCH PROCESS **************/
function processBatch_() {
  // IMPORTANT: do NOT delete triggers at the top of every run
  // Only delete duplicates BEFORE scheduling the next one.
  deleteMyTriggers_();

  const props = PropertiesService.getScriptProperties();
  const queue = JSON.parse(props.getProperty(P_QUEUE) || '[]');
  let index   = Number(props.getProperty(P_INDEX) || '0');
  const total = Number(props.getProperty(P_TOTAL) || queue.length);

  const resultSS = SpreadsheetApp.openById(props.getProperty(P_RESULT_ID));
  const sh = resultSS.getSheetByName('Results');

  const today = startOfDay_(new Date());
  const threshold = new Date(today.getFullYear(), today.getMonth() + MONTHS_THRESHOLD, today.getDate());

  const end = Math.min(index + BATCH_SIZE, queue.length);
  const batch = queue.slice(index, end);

  const rows = [];

  for (let i = 0; i < batch.length; i++) {
    const item = batch[i];
    const reg = item.reg;

    const out = checkOneDVLA_(reg, today, threshold);

    rows.push([
      reg,
      item.make || '',
      item.model || '',
      out.dvlaMake || '',
      out.colour || '',
      out.taxStatus || '',
      out.taxDue || '',
      out.motStatus || '',
      out.motExpiry || '',
      out.bucket || '',
      out.http || ''
    ]);

    if (SLEEP_MS > 0) Utilities.sleep(SLEEP_MS);
  }

  if (rows.length) {
    sh.getRange(sh.getLastRow() + 1, 1, rows.length, rows[0].length).setValues(rows);
  }

  index = end;
  props.setProperty(P_INDEX, String(index));

  // Live progress visible in results sheet
  sh.getRange('A1').setValue(
    `Progress: ${index} / ${total} (last update ${Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'HH:mm:ss')})`
  );

  toast_(`Processed ${index} / ${total}`);

  if (index >= queue.length) {
    finaliseAndSort_(sh);
    sh.getRange('A1').setValue(`Progress: ${total} / ${total} ✅ Finished (sorted)`);
    toast_('✅ All vehicles processed and sorted.');
    PropertiesService.getScriptProperties().deleteAllProperties();
    deleteMyTriggers_();
  } else {
    // keep going until done
    createMyTrigger_(TRIGGER_MS);
  }
}

/************** DVLA CHECK **************/
function checkOneDVLA_(reg, today, threshold) {
  try {
    const resp = UrlFetchApp.fetch(DVLA_URL, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify({ registrationNumber: reg }),
      headers: {
        'x-api-key': DVLA_API_KEY,
        'Accept': 'application/json'
      },
      muteHttpExceptions: true
    });

    const code = resp.getResponseCode();
    const body = resp.getContentText() || '';

    if (code !== 200) {
      return { http: `HTTP ${code}`, bucket: dvlaErrorBucket_(code) };
    }

    const d = safeJson_(body);

    const dvlaMake = str_(d.make);
    const colour   = str_(d.colour);
    const taxStatus = str_(d.taxStatus);
    const taxDue    = formatDateOut_(d.taxDueDate);
    const motStatus = str_(d.motStatus);

    const motExpiryDate = parseDateMaybe_(d.motExpiryDate);
    const classified = classifyMot_(motExpiryDate, motStatus, today, threshold);

    return {
      http: '200',
      dvlaMake,
      colour,
      taxStatus,
      taxDue,
      motStatus: classified.motStatusOut,
      motExpiry: motExpiryDate ? formatDateObj_(motExpiryDate) : formatDateOut_(d.motExpiryDate),
      bucket: classified.bucket
    };

  } catch (e) {
    return { http: 'SCRIPT ERROR', bucket: 'FAILED' };
  }
}

/************** COLLECT REGS + MAKE + MODEL (LOCKED TO INPUT SHEET) **************/
function collectAllRegsFromInputSheet_(spreadsheetId, sheetId) {
  const ss = SpreadsheetApp.openById(spreadsheetId);
  const sh = ss.getSheets().find(s => s.getSheetId() === Number(sheetId));
  if (!sh) return { items: [] };

  const lastRow = sh.getLastRow();
  const lastCol = sh.getLastColumn();
  if (!lastRow || !lastCol) return { items: [] };

  const data = sh.getRange(1, 1, lastRow, lastCol).getValues();

  // Try to find a header row within first 60 rows that contains a REG header
  const scanRows = Math.min(60, data.length);
  let headerRow = -1;
  let regCol = -1, makeCol = -1, modelCol = -1;

  for (let r = 0; r < scanRows; r++) {
    const row = data[r].map(v => String(v || '').toLowerCase().trim());
    const rc = findHeaderIndex_(row, REG_HEADERS);
    if (rc !== -1) {
      headerRow = r;
      regCol = rc;
      makeCol = findHeaderIndex_(row, MAKE_HEADERS);
      modelCol = findHeaderIndex_(row, MODEL_HEADERS);
      break;
    }
  }

  // Collect everything
  const map = new Map();

  if (headerRow !== -1 && regCol !== -1) {
    // Use reg column (best)
    for (let r = headerRow + 1; r < data.length; r++) {
      const reg = normalizeReg_(data[r][regCol]);
      if (!reg) continue;

      const make = (makeCol !== -1) ? str_(data[r][makeCol]) : '';
      const model = (modelCol !== -1) ? str_(data[r][modelCol]) : '';

      if (!map.has(reg)) map.set(reg, { reg, make, model });
      else {
        const cur = map.get(reg);
        if (!cur.make && make) cur.make = make;
        if (!cur.model && model) cur.model = model;
      }
    }
  } else {
    // Fallback: scan all cells for regs (still gets ALL regs)
    for (let r = 0; r < data.length; r++) {
      for (let c = 0; c < data[r].length; c++) {
        const reg = normalizeReg_(data[r][c]);
        if (!reg) continue;
        if (!map.has(reg)) map.set(reg, { reg, make: '', model: '' });
      }
    }
  }

  return { items: Array.from(map.values()) };
}

/************** FINAL SORT + COLOUR **************/
function finaliseAndSort_(sh) {
  const lastRow = sh.getLastRow();
  if (lastRow <= 2) return;

  const range = sh.getRange(3, 1, lastRow - 2, 11);
  const values = range.getValues();

  values.sort((a, b) => {
    const ra = bucketRank_(a[9]);
    const rb = bucketRank_(b[9]);
    if (ra !== rb) return ra - rb;

    const da = parseDateMaybe_(a[8]);
    const db = parseDateMaybe_(b[8]);
    const ta = da ? +da : Number.POSITIVE_INFINITY;
    const tb = db ? +db : Number.POSITIVE_INFINITY;
    if (ta !== tb) return ta - tb;

    return String(a[0]).localeCompare(String(b[0]));
  });

  range.setValues(values);

  // Colour rows by urgency
  const bgs = values.map(row => {
    const r = bucketRank_(row[9]);
    let bg = '#E7F4E4';      // OK green
    if (r === 0 || r === 1) bg = '#FDE2E1'; // urgent red
    else if (r === 9) bg = '#FFF4CE';      // errors yellow
    return new Array(11).fill(bg);
  });
  range.setBackgrounds(bgs);

  sh.autoResizeColumns(1, 11);
}

/************** BUCKETS **************/
function classifyMot_(motExpiryDate, motStatus, today, threshold) {
  const noDetails = /no details held by dvla/i.test(motStatus || '');

  if (!motExpiryDate && noDetails) {
    return { bucket: 'NEW VEHICLE (NO MOT YET)', motStatusOut: 'New vehicle – no MOT yet' };
  }
  if (!motExpiryDate) {
    return { bucket: 'EXPIRED', motStatusOut: motStatus || 'No MOT expiry returned' };
  }

  const due = startOfDay_(motExpiryDate);

  if (due < today) return { bucket: 'EXPIRED', motStatusOut: motStatus };
  if (due <= threshold) return { bucket: `EXPIRING WITHIN ${MONTHS_THRESHOLD} MONTHS`, motStatusOut: motStatus };
  return { bucket: 'OK', motStatusOut: motStatus };
}

function bucketRank_(bucket) {
  const b = String(bucket || '').toUpperCase();
  if (b === 'EXPIRED') return 0;
  if (b.indexOf('EXPIRING') === 0) return 1;
  if (b === 'OK') return 2;
  if (b.indexOf('NEW VEHICLE') === 0) return 3;
  if (b.indexOf('DVLA ERROR') === 0 || b.indexOf('FAILED') === 0) return 9;
  return 5;
}

function dvlaErrorBucket_(code) {
  if (code === 401) return 'DVLA ERROR: API KEY INVALID/REVOKED';
  if (code === 403) return 'DVLA ERROR: BLOCKED (IP/ACCESS)';
  if (code === 404) return 'DVLA ERROR: VEHICLE NOT FOUND';
  if (code === 429) return 'DVLA ERROR: RATE LIMITED';
  return `DVLA ERROR: HTTP ${code}`;
}

/************** TRIGGERS **************/
function createMyTrigger_(afterMs) {
  ScriptApp.newTrigger('checkAllRegsDVLA')
    .timeBased()
    .after(afterMs)
    .create();
}

function deleteMyTriggers_() {
  ScriptApp.getProjectTriggers().forEach(t => {
    if (t.getHandlerFunction && t.getHandlerFunction() === 'checkAllRegsDVLA') {
      ScriptApp.deleteTrigger(t);
    }
  });
}

/************** UTIL **************/
function findHeaderIndex_(rowLower, acceptedHeaders) {
  for (let i = 0; i < rowLower.length; i++) {
    if (acceptedHeaders.includes(rowLower[i])) return i;
  }
  return -1;
}

function normalizeReg_(v) {
  if (v === null || v === undefined) return null;
  const s = String(v).toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (!s) return null;
  return UK_REG_REGEX.test(s) ? s : null;
}

function safeJson_(text) {
  try { return JSON.parse(text); } catch (e) { return {}; }
}

function str_(v) {
  return (v === null || v === undefined) ? '' : String(v).trim();
}

function parseDateMaybe_(v) {
  if (!v) return null;
  if (Object.prototype.toString.call(v) === '[object Date]') return isNaN(v) ? null : v;

  const s = String(v).trim();
  if (!s) return null;

  const iso = /^(\d{4})-(\d{2})-(\d{2})$/;
  const m = iso.exec(s);
  if (m) return new Date(+m[1], +m[2] - 1, +m[3]);

  const t = Date.parse(s);
  return isNaN(t) ? null : new Date(t);
}

function formatDateOut_(v) {
  const d = parseDateMaybe_(v);
  return d ? Utilities.formatDate(d, Session.getScriptTimeZone(), 'yyyy-MM-dd') : '';
}

function formatDateObj_(d) {
  return Utilities.formatDate(d, Session.getScriptTimeZone(), 'yyyy-MM-dd');
}

function startOfDay_(d) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

function toast_(msg) {
  SpreadsheetApp.getActive().toast(msg);
}
