#!/usr/bin/env python3
"""
DVLA MOT Fleet Checker
Bulk-checks UK vehicle registrations against the DVLA Vehicle Enquiry API.

Usage:
  python3 checker.py fleet.csv --key YOUR_API_KEY
  python3 checker.py fleet.csv --key YOUR_API_KEY --threshold 3 --format json
  python3 checker.py fleet.csv --key YOUR_API_KEY --output results.csv
"""

import argparse
import csv
import json
import re
import sys
import time
from datetime import date
from pathlib import Path

try:
    import requests
except ImportError:
    sys.exit("Missing dependency: pip3 install requests")

try:
    from colorama import Fore, Style, init as colorama_init
    colorama_init()
    HAS_COLOR = True
except ImportError:
    HAS_COLOR = False

DVLA_URL    = 'https://driver-vehicle-licensing.api.gov.uk/vehicle-enquiry/v1/vehicles'
UK_REG_RE   = re.compile(r'^[A-Z]{1,3}[0-9]{1,4}[A-Z]{0,3}$')

REG_HEADERS   = ['reg', 'reg.', 'registration', 'registration number', 'vrm']
MAKE_HEADERS  = ['make']
MODEL_HEADERS = ['model']


# ── helpers ──────────────────────────────────────────────────────────────────

def normalize_reg(value):
    if not value:
        return None
    s = re.sub(r'[^A-Z0-9]', '', str(value).upper())
    return s if UK_REG_RE.match(s) else None


def parse_date(v):
    if not v:
        return None
    if isinstance(v, date):
        return v
    m = re.match(r'^(\d{4})-(\d{2})-(\d{2})$', str(v).strip())
    return date(int(m[1]), int(m[2]), int(m[3])) if m else None


def add_months(d, months):
    m = d.month - 1 + months
    return date(d.year + m // 12, m % 12 + 1, d.day)


def classify(mot_expiry, mot_status, today, threshold_date):
    no_details = 'no details held by dvla' in (mot_status or '').lower()
    if not mot_expiry and no_details:
        return 'NEW VEHICLE (NO MOT YET)'
    if not mot_expiry:
        return 'EXPIRED'
    if mot_expiry < today:
        return 'EXPIRED'
    if mot_expiry <= threshold_date:
        return 'EXPIRING SOON'
    return 'OK'


def bucket_rank(bucket):
    b = (bucket or '').upper()
    if b == 'EXPIRED':            return 0
    if b.startswith('EXPIRING'):  return 1
    if b == 'OK':                 return 2
    if b.startswith('NEW VEHICLE'): return 3
    if 'ERROR' in b or 'FAILED' in b: return 9
    return 5


# ── DVLA API call ─────────────────────────────────────────────────────────────

def check_one(reg, api_key, today, threshold_date):
    error_buckets = {
        401: 'DVLA ERROR: API KEY INVALID',
        403: 'DVLA ERROR: BLOCKED',
        404: 'DVLA ERROR: VEHICLE NOT FOUND',
        429: 'DVLA ERROR: RATE LIMITED',
    }
    try:
        r = requests.post(
            DVLA_URL,
            json={'registrationNumber': reg},
            headers={'x-api-key': api_key, 'Accept': 'application/json'},
            timeout=10,
        )
        code = r.status_code
        if code != 200:
            return {'http': str(code), 'bucket': error_buckets.get(code, f'DVLA ERROR: HTTP {code}')}

        d = r.json()
        mot_expiry  = parse_date(d.get('motExpiryDate'))
        mot_status  = str(d.get('motStatus', '')).strip()
        bucket      = classify(mot_expiry, mot_status, today, threshold_date)

        return {
            'http':        '200',
            'dvla_make':   str(d.get('make',      '')).strip(),
            'colour':      str(d.get('colour',    '')).strip(),
            'tax_status':  str(d.get('taxStatus', '')).strip(),
            'tax_due':     str(d.get('taxDueDate', '')).strip(),
            'mot_status':  mot_status,
            'mot_expiry':  str(mot_expiry) if mot_expiry else '',
            'bucket':      bucket,
        }
    except Exception as e:
        return {'http': 'ERROR', 'bucket': 'FAILED', 'error': str(e)}


# ── CSV reader ────────────────────────────────────────────────────────────────

def read_csv(path):
    items, seen = [], set()

    with open(path, newline='', encoding='utf-8-sig') as f:
        rows = list(csv.reader(f))

    if not rows:
        return items

    header_row = reg_col = make_col = model_col = -1

    for i, row in enumerate(rows[:60]):
        lower = [c.lower().strip() for c in row]
        for j, cell in enumerate(lower):
            if cell in REG_HEADERS:
                header_row, reg_col = i, j
                for k, h in enumerate(lower):
                    if h in MAKE_HEADERS:  make_col  = k
                    if h in MODEL_HEADERS: model_col = k
                break
        if header_row != -1:
            break

    if header_row != -1 and reg_col != -1:
        for row in rows[header_row + 1:]:
            if reg_col >= len(row):
                continue
            reg = normalize_reg(row[reg_col])
            if not reg or reg in seen:
                continue
            seen.add(reg)
            make  = row[make_col].strip()  if make_col  != -1 and make_col  < len(row) else ''
            model = row[model_col].strip() if model_col != -1 and model_col < len(row) else ''
            items.append({'reg': reg, 'make': make, 'model': model})
    else:
        for row in rows:
            for cell in row:
                reg = normalize_reg(cell)
                if reg and reg not in seen:
                    seen.add(reg)
                    items.append({'reg': reg, 'make': '', 'model': ''})

    return items


# ── output ────────────────────────────────────────────────────────────────────

def row_color(bucket):
    if not HAS_COLOR:
        return '', ''
    b = (bucket or '').upper()
    if b == 'EXPIRED':           return Fore.RED,    Style.RESET_ALL
    if b.startswith('EXPIRING'): return Fore.YELLOW, Style.RESET_ALL
    if b == 'OK':                return Fore.GREEN,  Style.RESET_ALL
    return Fore.CYAN, Style.RESET_ALL


# ── main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='DVLA MOT fleet checker')
    parser.add_argument('input',        help='CSV file with vehicle registrations')
    parser.add_argument('--key',        required=True, help='DVLA Vehicle Enquiry API key')
    parser.add_argument('--threshold',  type=int, default=3, help='Months ahead to warn (default 3)')
    parser.add_argument('--sleep',      type=float, default=0.5, help='Seconds between API calls (default 0.5)')
    parser.add_argument('--format',     choices=['table', 'json', 'csv'], default='table')
    parser.add_argument('--output',     help='Save results to this CSV file')
    args = parser.parse_args()

    items = read_csv(args.input)
    if not items:
        out = {'error': 'No valid UK registrations found in file'}
        if args.format == 'json':
            print(json.dumps(out))
        else:
            print(out['error'])
        sys.exit(1)

    today          = date.today()
    threshold_date = add_months(today, args.threshold)
    results        = []

    for i, item in enumerate(items):
        if args.format == 'table':
            print(f'\rChecking {i+1}/{len(items)}: {item["reg"]}   ', end='', flush=True)

        result = check_one(item['reg'], args.key, today, threshold_date)
        result.update({
            'reg':         item['reg'],
            'sheet_make':  item['make'],
            'sheet_model': item['model'],
        })
        results.append(result)

        if i < len(items) - 1:
            time.sleep(args.sleep)

    results.sort(key=lambda r: (
        bucket_rank(r.get('bucket', '')),
        r.get('mot_expiry') or 'z',
        r.get('reg', ''),
    ))

    # ── JSON output ──
    if args.format == 'json':
        summary = {
            'total':         len(results),
            'expired':       sum(1 for r in results if r.get('bucket') == 'EXPIRED'),
            'expiring_soon': sum(1 for r in results if (r.get('bucket') or '').startswith('EXPIRING')),
            'ok':            sum(1 for r in results if r.get('bucket') == 'OK'),
            'new_vehicle':   sum(1 for r in results if (r.get('bucket') or '').startswith('NEW VEHICLE')),
            'errors':        sum(1 for r in results if bucket_rank(r.get('bucket', '')) == 9),
        }
        print(json.dumps({'summary': summary, 'vehicles': results}))
        return

    # ── CSV stdout ──
    if args.format == 'csv':
        fields = ['reg', 'sheet_make', 'sheet_model', 'dvla_make', 'colour',
                  'tax_status', 'tax_due', 'mot_status', 'mot_expiry', 'bucket', 'http']
        w = csv.DictWriter(sys.stdout, fieldnames=fields, extrasaction='ignore')
        w.writeheader()
        w.writerows(results)
        return

    # ── table output ──
    print('\n')
    fmt = '{:<12} {:<16} {:<12} {:<12} {:<24} {}'
    print(fmt.format('REG', 'MAKE', 'MOT EXPIRY', 'TAX STATUS', 'BUCKET', 'MOT STATUS'))
    print('─' * 100)
    for r in results:
        c, reset = row_color(r.get('bucket', ''))
        make = (r.get('dvla_make') or r.get('sheet_make') or '')[:15]
        print(c + fmt.format(
            r.get('reg', ''),
            make,
            r.get('mot_expiry') or 'N/A',
            r.get('tax_status', '')[:11],
            r.get('bucket', '')[:23],
            r.get('mot_status', ''),
        ) + reset)

    expired  = sum(1 for r in results if r.get('bucket') == 'EXPIRED')
    expiring = sum(1 for r in results if (r.get('bucket') or '').startswith('EXPIRING'))
    ok       = sum(1 for r in results if r.get('bucket') == 'OK')
    errors   = sum(1 for r in results if bucket_rank(r.get('bucket', '')) == 9)
    print()
    print(f'Total: {len(results)}  |  Expired: {expired}  |  Expiring soon: {expiring}  |  OK: {ok}  |  Errors: {errors}')

    # ── optional CSV save ──
    if args.output:
        fields = ['reg', 'sheet_make', 'sheet_model', 'dvla_make', 'colour',
                  'tax_status', 'tax_due', 'mot_status', 'mot_expiry', 'bucket', 'http']
        with open(args.output, 'w', newline='') as f:
            w = csv.DictWriter(f, fieldnames=fields, extrasaction='ignore')
            w.writeheader()
            w.writerows(results)
        print(f'\nSaved to {args.output}')


if __name__ == '__main__':
    main()
