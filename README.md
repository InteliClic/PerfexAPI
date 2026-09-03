# PerfexAPI — `perfex_crm_api_layer`

A small, token-authenticated JSON API module for [Perfex CRM](https://www.perfexcrm.com/) (3.x), built so Claude (or any script) can keep the books current without anyone clicking through the Perfex UI. Installed on both InteliClic instances; grows as we need it.

## Install / upgrade

1. Zip the `perfex_crm_api_layer/` folder (folder name must be the zip's top level):  
   `zip -rX perfex_crm_api_layer.zip perfex_crm_api_layer`
2. Perfex admin → **Setup → Modules → Upload Module** → Install. Re-uploading a newer zip upgrades in place.
3. Activate. On first activation a token is generated. **Setup → API Layer** shows the token, lets you regenerate it, and sets the staff id used as `addedfrom` for API-created records.

## Auth

Header `X-Api-Token: <token>` (or `Authtoken`). Constant-time compare against option `perfex_crm_api_layer_token`. 401 otherwise.

## Endpoints

Base: `https://<host>/perfex_crm_api_layer/api`

| Method | Path | Notes |
|---|---|---|
| GET | `/meta` | Perfex/PHP version, date format, base currency, staff id |
| GET | `/currencies`, `/expense_categories`, `/payment_modes`, `/taxes` | id → name lookups |
| GET | `/customers?q=` | `userid`, `company`, … |
| GET | `/expenses?from=YYYY-MM-DD&to=&category=&limit=500&offset=0` | |
| PUT | `/expenses` | `{category, amount, date, currency, expense_name?, note?, reference_no?, paymentmode?, clientid?, project_id?, billable?, tax?, tax2?}` → 201 `{id}`; 409 if `reference_no`+`amount` already exists |
| PUT | `/expenses/batch` | `{items:[…]}` → per-item ids; duplicates skipped (existing id returned) |
| GET | `/expenses/<id>` | one row |
| PATCH | `/expenses/<id>` | partial update, any of the create fields |
| DELETE | `/expenses/<id>` | 409 if the expense is attached to an invoice |
| GET | `/invoices?from=&to=&status=&clientid=`, `/invoices/<id>` | |
| PUT | `/invoices` | `{clientid, date, duedate?, currency, number?, newitems:[{description, long_description?, qty, rate, taxname?:[]}], status?, adminnote?, clientnote?}` |
| GET | `/payments?from=&to=` | `invoicepaymentrecords` |
| PUT | `/payments` | `{invoiceid, amount, date, paymentmode, transactionid?, note?, send_email?}`; 409 on duplicate `transactionid` |

Conventions: dates `YYYY-MM-DD` in and out; amounts as numbers; JSON bodies; JSON responses. **Writes use PUT/PATCH/DELETE, never POST** — Perfex's CSRF filter intercepts POST; the other verbs pass. All writes go through Perfex's own models (`expenses_model`, `invoices_model`, `payments_model->process_payment`) so numbering, invoice status and the activity log stay coherent. No raw SQL writes.

### Example

```bash
T=...token...
curl -s -H "X-Api-Token: $T" https://hq.inteliclic.com/perfex_crm_api_layer/api/meta
curl -s -X PUT -H "X-Api-Token: $T" -H 'Content-Type: application/json' \
  -d '{"category":2,"amount":959.49,"date":"2026-03-10","currency":1,"expense_name":"Wise (Central Flow contractors)","note":"Card charge (Wise)","reference_no":"252345678","paymentmode":"2","clientid":115}' \
  https://hq.inteliclic.com/perfex_crm_api_layer/api/expenses
curl -s -X PATCH -H "X-Api-Token: $T" -H 'Content-Type: application/json' -d '{"category":8}' .../api/expenses/2844
curl -s -X DELETE -H "X-Api-Token: $T" .../api/expenses/2844
```

## Instances

| | URL | Module |
|---|---|---|
| InteliClic S.A. | https://hq.inteliclic.com | 1.1.1 — expenses current to 2026-07-31; categories 1–15,17,18 (8 = Office Equipment / Supplies); payment modes 1 CrediCorp wire, 2 Payoneer, 3 PayPal; customer 115 Central Flow |
| Aron Corp | https://hq.aroncorp.com | not yet installed |

## Bringing an instance current

See **RUNBOOK.md** — the generic, step-by-step procedure (install → learn the instance → gather statements → build ledger + review sheet → push → verify → record), written so a fresh Claude session can run it on any instance.

## `tools/` — statement → Perfex pipeline

- `build_ledger.py` — reads a Payoneer transactions CSV and a Credicorp "Detalle de movimientos" XLS, keeps Jan–Jul 2026 outflows, drops transfers/revenue/cancelled lines, categorizes from `namemap_2025.txt` (merchant → category learned from the 2025 books) plus `rules2.py`/`rules3.py`, and writes `ledger_2026_h1.json`. Adjust the date window at the top.
- `push_expenses.js` — paste into the browser console on **Setup → API Layer** (reads the token from the page) to push a ledger JSON through `/expenses/batch` in chunks and print per-month totals. Used because Claude's cloud sandbox cannot reach the host directly; from a machine that can, `curl` works the same.

## Roadmap (add as needed)

- Expense categories / customers CRUD (created via the Perfex UI so far)
- Attachments on expenses (receipt PDFs)
- Reports endpoints (P&L by category/month, expenses by client) for the accountant hand-off
- Same module on hq.aroncorp.com
- Weekly Payoneer/Credicorp pull → batch push (scheduled task; bank logins still need a browser session)

## Notes / gotchas

- `Expenses_model` treats the presence of the `billable` key as true; the API only sends it when set.
- Loading module views via `module_views_path()` failed on 3.4.1; the admin page renders inline with `init_head()/init_tail()`.
- Keep the module's init file non-empty — Perfex rejects the zip ("No valid module is found") if the header block is missing.
- Token was shown in a tool transcript once on 2026-09-02 — regenerate from the admin page when convenient.
