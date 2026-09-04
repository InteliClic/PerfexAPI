#!/usr/bin/env python3
"""Scotiabank CSV -> Perfex expense ledger for hq.aroncorp.com — August 2026 run.

Differences from build_ledger_aroncorp.py (the July version):
  * Name map lives in namemap_aroncorp.txt, built from the INSTANCE'S OWN history
    (GET /expenses, group expense_name -> most-used category). Always rebuild it
    off the instance before a run rather than trusting a stale copy.
  * look() strips '*' before matching, so 'amzn mktp ca*5n8xp48u0' hits 'amzn mktp ca'
    and 'google*workspace aronc' hits 'google workspace aronc'.
  * SKIP list extended with tax refund / goodwill credit / credit adjustment /
    credit card payment, and rows with Status 'pending' are held back.
  * Set the per-source NEXT counters from the instance first:
      GET /expenses?from=<year>-01-01 -> max trailing number per '<tag>-<date>-<nnnn>'.

Gotchas that cost time on 2026-09-03:
  * Scotia's per-account transaction filter defaults to the STATEMENT period, which
    starts mid-month and silently drops the 1st-3rd. Switch it to "All available
    transactions (up to 2 years)" before exporting, every time.
  * Amazon rows arrive as 'amzn mktp ca*<id>' with no clue what was bought. Open the
    order at amazon.ca/gp/your-account/order-details?orderID=<id> — that is how the
    Aug 26 $4,511.30 cluster turned out to be a NAS build (Equipment, not Supplies)
    and how $285.72 of cosmetics on the company card was caught and moved to
    Owner Advances.
"""
import csv, json, re, collections

U = '/mnt/user-data/uploads/Downloads/'
WIN_FROM, WIN_TO = '2026-08-01', '2026-08-31'

NM = {}
for l in open('namemap_aroncorp.txt'):
    l = l.strip()
    if l:
        k, v = l.rsplit('|', 1)
        NM[k] = int(v)

# hq.aroncorp.com categories
CATS = {1: 'Automotive', 2: 'Travel', 3: 'Shipping', 4: 'Fees', 5: 'Services',
        6: 'Entertainment', 7: 'Food & Beverage', 8: 'Office Supplies',
        9: 'Dividends', 10: 'CRA Taxes', 12: 'Equipment', 13: 'Marketing',
        14: 'Owner Advances'}   # 14 created 2026-09-03

# (tag, paymentmode, currency, file, is_card, next reference counter)
SOURCES = [
    ('VisaMomentum', '3', 1, 'ScotiaVisa_7135_2year_to_2026-09-03.csv',      True,  238),
    ('CAD0816',      '5', 1, 'ScotiaChequing_0816_2year_to_2026-09-03.csv',  False, 129),
    ('USD2712',      '2', 3, 'ScotiaUSD_2712_2year_to_2026-09-03.csv',       False, 125),
]

BANKMAP = {
    ('service charge', 'interac e-transfer fee'): (4, 'Interac E-Transfer Fee', 'exact'),
    ('service charge', ''):                       (4, 'ScotiaBank - Service Charge', 'exact'),
    ('loans', 'rbc loan pymt'):                   (1, 'RBC Vehicle Loan Payment', 'exact'),
    ('debit memo', 'interac e-transfer'):         (5, 'Interac E-Transfer - payment for expenses', 'ASK'),
}

# never expenses: card payments, own-account transfers, revenue, refunds-in
SKIP = re.compile(r'^(payment from|customer transfer|incoming wire|bill payment|'
                  r'interest credit|credit memo|transfer|tax refund|goodwill credit|'
                  r'credit adjustment|credit card payment)', re.I)


def clean(s):
    return re.sub(r'\s+', ' ', (s or '')).strip()


def look(desc):
    d = re.sub(r'\*.*$', '', desc).strip()
    desc = re.sub(r'\s+', ' ', re.sub(r'\*', ' ', desc)).strip()
    for cand in (desc, d):
        if cand in NM:
            return NM[cand], 'exact'
    for k in sorted(NM, key=len, reverse=True):
        if desc.startswith(k) or d.startswith(k):
            return NM[k], 'prefix'
    for k in sorted(NM, key=len, reverse=True):
        if len(k) >= 5 and k in desc:
            return NM[k], 'contains'
    return None, 'ASK'


items, refunds, skipped = [], [], []
NEXT = {}

for tag, pm, cur, f, is_card, start in SOURCES:
    NEXT[tag] = start
    rows, seen = [], set()
    for r in csv.DictReader(open(U + f, encoding='utf-8-sig')):
        d = clean(r.get('Date'))
        if not (WIN_FROM <= d <= WIN_TO):
            continue
        k = (d, clean(r.get('Description')), clean(r.get('Amount')))
        if k in seen:          # the card's whole history exports under its current number
            continue
        seen.add(k)
        rows.append(r)

    for r in sorted(rows, key=lambda x: (clean(x.get('Date')), clean(x.get('Description')))):
        d    = clean(r.get('Date'))
        desc = clean(r.get('Description')).lower()
        sub  = clean(r.get('Sub-description')).lower()
        amt  = float(clean(r.get('Amount')).replace(',', ''))
        typ  = clean(r.get('Type of Transaction')).lower()
        st   = clean(r.get('Status')).lower()

        if st == 'pending':
            skipped.append([tag, d, desc, sub, amt, 'pending - not posted yet']); continue
        if SKIP.match(desc):
            skipped.append([tag, d, desc, sub, amt, 'transfer/revenue/credit']); continue

        if is_card:
            if typ == 'credit' or amt < 0:      # refund - surface it, do not enter it
                refunds.append([tag, d, desc, sub, amt]); continue
            value = round(amt, 2)
            cat, conf = look(desc); name = None
        else:
            if amt > 0:
                skipped.append([tag, d, desc, sub, amt, 'deposit']); continue
            value = round(-amt, 2)
            hit = BANKMAP.get((desc, sub)) or BANKMAP.get((desc, ''))
            if hit:
                cat, name, conf = hit
            else:
                cat, conf = look(desc); name = None

        if name is None:
            base = re.sub(r'\s+\d{3}-\d{3}-\d{4}.*$', '', re.sub(r'\*\S+', '', desc))
            name = ' '.join(w.capitalize() if not w.isupper() else w
                            for w in base.strip().split(' '))[:60]

        if re.search(r'amzn mktp|amazon\.ca', desc):
            name = 'Amazon'

        items.append(dict(category=cat or 0, amount=value, date=d, currency=cur,
                          expense_name=name, note=clean(r.get('Sub-description'))[:180],
                          reference_no='%s-%s-%04d' % (tag, d, NEXT[tag]), paymentmode=pm,
                          _source=tag, _conf=conf,
                          _raw=desc + (' / ' + sub if sub else '')))
        NEXT[tag] += 1

json.dump(items, open('aroncorp_ledger_2026_08.json', 'w'), indent=1)

by = collections.defaultdict(lambda: [0, 0.0])
for i in items:
    by[i['_source']][0] += 1
    by[i['_source']][1] += i['amount']
print('ITEMS', len(items))
for k in by:
    print('  ', k, by[k][0], '%.2f' % by[k][1])

print('\nASK - resolve before pushing (%d):' % sum(1 for i in items if i['_conf'] == 'ASK'))
for i in items:
    if i['_conf'] == 'ASK':
        print('  %s %-13s %9.2f  %-34s %s'
              % (i['date'], i['_source'], i['amount'], i['expense_name'][:34], i['_raw'][:52]))

print('\nRefunds / credits on the card (%d) - tell the user, do not enter:' % len(refunds))
for r in refunds:
    print('  ', r[1], r[2][:40], r[4])

print('\nSkipped - transfers / revenue / pending (%d):' % len(skipped))
for s in skipped:
    print('  ', s[1], s[0], s[2][:38], s[4], '|', s[5])
