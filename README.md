# DVLA MOT Fleet Checker

Bulk-checks UK vehicle registrations against the DVLA Vehicle Enquiry API. Built to replace manual MOT checking for site fleets — catches expired or soon-to-expire MOTs before they become someone's urgent problem.

Originally built as a Google Apps Script for Google Sheets, then ported to Python with a self-hosted PHP web UI.

![Status: expired red, expiring orange, OK green](https://img.shields.io/badge/status-active-brightgreen)

---

## Features

- Bulk-check any number of UK registrations in one run
- Pulls MOT expiry, MOT status, tax status and tax due date from DVLA
- Classifies each vehicle: **EXPIRED**, **EXPIRING SOON**, **OK**, **NEW VEHICLE (NO MOT YET)**
- Sorts results by urgency — expired first
- Colour-coded terminal output
- CSV import/export
- Web UI — paste regs or upload a CSV, results in your browser
- Configurable warning threshold (default: flag anything expiring within 3 months)

---

## Requirements

- Python 3.8+
- A free DVLA Vehicle Enquiry API key — register at [developer-portal.driver-vehicle-licensing.agency.gov.uk](https://developer-portal.driver-vehicle-licensing.agency.gov.uk/)

```
pip3 install -r requirements.txt
```

---

## CLI Usage

```bash
python3 checker.py fleet.csv --key YOUR_API_KEY
```

**Options:**

| Flag | Default | Description |
|------|---------|-------------|
| `--key` | required | DVLA API key |
| `--threshold` | `3` | Months ahead to warn as expiring soon |
| `--sleep` | `0.5` | Seconds between API calls |
| `--format` | `table` | Output format: `table`, `json`, `csv` |
| `--output` | — | Save results to a CSV file |

**Examples:**

```bash
# Basic check
python3 checker.py sample_fleet.csv --key YOUR_KEY

# Warn anything expiring within 6 months, save to CSV
python3 checker.py fleet.csv --key YOUR_KEY --threshold 6 --output results.csv

# JSON output
python3 checker.py fleet.csv --key YOUR_KEY --format json
```

---

## CSV Format

The script auto-detects the header row. Supported column names:

| Column | Accepted headers |
|--------|-----------------|
| Registration | `reg`, `reg.`, `registration`, `registration number`, `vrm` |
| Make | `make` |
| Model | `model` |

If no header row is found it scans every cell for valid UK reg patterns — useful for messy spreadsheet exports.

A sample fleet CSV is included: `sample_fleet.csv`

---

## Web UI Setup

The `web/` folder is a self-contained PHP web UI with a one-page installer.

**Requirements:** PHP 7.4+, PHP cURL, `shell_exec` enabled, Python 3

1. Serve the `web/` folder with PHP:
   ```bash
   php -S localhost:8080 -t web/
   ```
2. Visit `http://localhost:8080/install.php`
3. Paste your DVLA API key and click **Test & Save**
4. Once all checks pass, click **Open the checker**

The installer writes a `config.php` with your API key stored server-side — it's never exposed in the browser.

**Using the web UI:**
- Paste registrations (one per line) into the text box, or upload a CSV
- Pick your expiry warning threshold
- Hit **Check Fleet** — results are sorted by urgency with colour-coded rows
- Try **Sample Fleet** to see it working without any data

---

## API Buckets

| Bucket | Meaning |
|--------|---------|
| `EXPIRED` | MOT expiry date is in the past |
| `EXPIRING SOON` | MOT expires within your threshold (default 3 months) |
| `OK` | MOT is valid and not expiring soon |
| `NEW VEHICLE (NO MOT YET)` | DVLA has no MOT details — likely a new vehicle |
| `DVLA ERROR: VEHICLE NOT FOUND` | Reg not recognised by DVLA |
| `DVLA ERROR: RATE LIMITED` | Too many requests — reduce `--sleep` or try again |

---

## Google Apps Script Version

The original `Code.gs` is included for reference. It runs inside Google Sheets, scans the active sheet for registrations, and writes results to a new spreadsheet with colour-coded rows. Useful if you want to keep everything inside Google Workspace.
