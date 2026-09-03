#!/usr/bin/env python3
"""Scotiabank CSV -> Perfex expense ledger for hq.aroncorp.com.

All Scotia web exports share one shape:
    Filter,Date,Description,Sub-description,[Status,]Type of Transaction,Amount[,Balance]
Card amounts are POSITIVE for debits; bank-account amounts are NEGATIVE for debits.

Gotchas learned 2026-09-03:
  * Scotia's web export caps at 100 rows. A file whose row count is exactly 100 is
    truncated -- pull it month by month instead and check the date range.
  * The card's whole history exports under the CURRENT card number, so the 7135
    files contain 5677-era transactions. Dedupe on (date, description, amount).
  * Interac e-transfers carry no recipient in the CSV. The recipient is only in the
    Interac confirmation email (catch@payments.interac.ca) -- see OVERRIDE below.

Run from the folder holding the CSVs; writes aroncorp_ledger_<window>.json.
Edit WIN_FROM/WIN_TO, the file names, and NEXT (read the counters off the instance)
before each run.
"""
import csv, json, re, sys

WIN_FROM, WIN_TO = '2026-07-01', '2026-07-31'
CARD_FROM = '2026-07-05'          # card was already entered through 2026-07-04

SOURCES = [
    # (source tag, paymentmode, currency, [files], is_card, start)
    ('VisaMomentum', '3', 1, ['Scotia_Momentum_fb_VISA_7135_082726.csv',
                              'Scotia_Momentum_fb_VISA_5677_070626 (1).csv'], True,  CARD_FROM),
    ('CAD0816',      '5', 1, ['Right_Size_Account_for_Business_0816_082726.csv'],   False, WIN_FROM),
    ('USD2712',      '2', 3, ['Basic_Business_USD_2712_082726.csv'],                False, WIN_FROM),
    ('CAD0711',      '1', 1, ['Right_Size_Savings_for_Business_0711_082726.csv'],   False, WIN_FROM),
    ('CAD9414',      '6', 1, ['Right_Size_Savings_for_Business_9414_082726.csv'],   False, WIN_FROM),
    ('CAD9112',      '7', 1, ['Right_Size_Savings_for_Business_9112_082726.csv'],   False, WIN_FROM),
]

# Next free reference counter per source. Read off the instance before every run:
#   GET /expenses?from=<year>-01-01 -> max trailing number per '<tag>-<date>-<nnnn>'.
# The counter restarts each calendar year.
NEXT = {'VisaMomentum': 201, 'CAD0816': 123, 'USD2712': 124,
        'CAD0711': 1, 'CAD9414': 1, 'CAD9112': 1}

# merchant -> (category id, canonical name, confidence)
# Built from the instance's own history: GET /expenses, group expense_name -> most-used category.
NAMEMAP = {
    'amazon':          (8,  'Amazon',                'exact'),
    'staples':         (8,  'Staples',               'exact'),
    'the home depot':  (8,  'The Home Depot',        'exact'),
    'bc ferries':      (2,  'BC Ferries',            'exact'),
    'uber':            (2,  'Uber',                  'exact'),
    'shell':           (1,  'Shell',                 'exact'),
    'koodo mobile':    (5,  'Koodo Mobile',          'exact'),
    'github':          (5,  'GitHub',                'exact'),
    'higgsfield':      (5,  'Higgsfield',            'exact'),
    'google ads':      (13, 'Google Ads',            'exact'),
    'artigiano caffe': (7,  'Artigiano Caffe',       'exact'),
    'fairmont':        (2,  'Fairmont Pacific Rim',  'prefix'),
    'store bambulab':  (12, 'Bambu Lab',             'user'),    # 3D printer -> Equipment, Niko 2026-09-03
    'prime video':     (5,  'Prime Video',           'user'),    # grouped with the subscriptions
}

# (description, sub-description) -> (category, name, confidence), for bank accounts
BANKMAP = {
    ('service charge', 'interac e-transfer fee'): (4, 'Interac E-Transfer Fee', 'exact'),
    ('service charge', ''):                       (4, 'ScotiaBank - Service Charge', 'exact'),
    ('loans', 'rbc loan pymt'):                   (1, 'RBC Vehicle Loan Payment', 'exact'),
    ('debit memo', 'interac e-transfer'):         (5, 'Interac E-Transfer - payment for expenses', 'ASK'),
}

# (source, date, amount) -> (category, name, confidence)
# Interac recipients are not in the CSV; look them up in Gmail:
#   from:catch@payments.interac.ca after:<d-1> before:<d+2>
OVERRIDE = {
    ('CAD0816', '2026-07-15', 3680.25): (5, 'Empire CPA',        'email'),
    ('CAD0816', '2026-07-23',  315.00): (1, 'Mill Bay Auto Spa', 'email'),
}

# Never expenses: card payments, transfers between own accounts, revenue, interest.
SKIP_DESC = re.compile(
    r'^(payment from|customer transfer|incoming wire|bill payment|interest credit|credit memo|transfer)', re.I)

items, refunds, skipped = [], [], []


def clean(s):
    return re.sub(r'\s+', ' ', (s or '')).strip()


for tag, pm, cur, files, is_card, start in SOURCES:
    rows, seen = [], set()
    for f in files:
        try:
            fh = open(f, encoding='utf-8-sig')
        except FileNotFoundError:
            print('!! missing', f, file=sys.stderr)
            continue
        n = 0
        for r in csv.DictReader(fh):
            n += 1
            d = clean(r.get('Date'))
            if not (start <= d <= WIN_TO):
                continue
            key = (d, clean(r.get('Description')), clean(r.get('Amount')))
            if key in seen:          # same card, two exports
                continue
            seen.add(key)
            rows.append(r)
        if n == 100:
            print('!! %s has exactly 100 rows -- Scotia truncated it, re-pull month by month' % f,
                  file=sys.stderr)

    for r in sorted(rows, key=lambda x: (clean(x.get('Date')), clean(x.get('Description')))):
        d    = clean(r.get('Date'))
        desc = clean(r.get('Description')).lower()
        sub  = clean(r.get('Sub-description')).lower()
        amt  = float(clean(r.get('Amount')).replace(',', ''))
        typ  = clean(r.get('Type of Transaction')).lower()

        if SKIP_DESC.match(desc):
            skipped.append([tag, d, desc, sub, amt])
            continue

        if is_card:
            if typ == 'credit' or amt < 0:      # refund -- list it, don't enter it
                refunds.append([tag, d, desc, sub, amt])
                continue
            value = round(amt, 2)
            hit = NAMEMAP.get(desc) or next((v for k, v in NAMEMAP.items() if desc.startswith(k)), None)
        else:
            if amt > 0:                          # deposit / credit
                skipped.append([tag, d, desc, sub, amt])
                continue
            value = round(-amt, 2)
            hit = OVERRIDE.get((tag, d, value)) or BANKMAP.get((desc, sub)) or BANKMAP.get((desc, ''))

        cat, name, conf = hit if hit else (0, desc.title(), 'ASK')
        items.append({
            'category': cat, 'amount': value, 'date': d, 'currency': cur,
            'expense_name': name, 'note': clean(r.get('Sub-description')),
            'reference_no': '%s-%s-%04d' % (tag, d, NEXT[tag]),
            'paymentmode': pm, '_source': tag, '_confidence': conf,
            '_raw': desc + (' / ' + sub if sub else ''),
        })
        NEXT[tag] += 1

out = 'aroncorp_ledger_%s.json' % WIN_FROM[:7].replace('-', '_')
json.dump(items, open(out, 'w'), indent=1)

tot = {}
for it in items:
    tot[it['_source']] = round(tot.get(it['_source'], 0) + it['amount'], 2)
print('ITEMS', len(items), tot, '->', out)

print('\nASK rows (resolve these before pushing):')
for it in items:
    if it['_confidence'] == 'ASK':
        print('  %s  %-13s %10.2f  cat%-3s %-45s %s'
              % (it['date'], it['_source'], it['amount'], it['category'], it['expense_name'], it['_raw']))

print('\nRefunds (tell the user, do not enter):')
for r in refunds:
    print('  ', r)

print('\nSkipped -- transfers / revenue / interest:', len(skipped))
for r in skipped:
    print('  ', r)
