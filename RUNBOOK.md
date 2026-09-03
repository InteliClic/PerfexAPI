# Runbook — install the API on a Perfex instance and bring its expenses current

This is the generic procedure Claude follows for any Perfex CRM 3.x instance. It was first run on hq.inteliclic.com (2026-09-02/03) and again on hq.aroncorp.com (2026-09-03). To start a new session: *"Install PerfexAPI on <instance URL> and bring the expenses current through <date>, following RUNBOOK.md in InteliClic/PerfexAPI."*

Everything below is driven through **Claude in Chrome** (the user's logged-in Perfex admin session). Claude's cloud sandbox cannot reach the Perfex hosts directly, so all API calls are `fetch()` from the Perfex admin page; the token is read from the admin page DOM and never pasted into chat.

## 0. Preconditions

- The user is logged in to the Perfex admin in Chrome (Claude in Chrome extension connected). If the tab lands on the login screen with saved credentials filled in, **ask the user to click Login** — Claude does not authenticate on their behalf.
- Claude has the module zip: build it from this repo with `zip -rX perfex_crm_api_layer.zip perfex_crm_api_layer` (the zip's top-level folder must be `perfex_crm_api_layer/`, and `perfex_crm_api_layer.php` must be non-empty or Perfex answers "No valid module is found"). Write the zip somewhere `file_upload` accepts — the session's outputs folder or a connected folder.
- Known instances and their state live in `README.md` → *Instances* (and in life-ops `docs/accounting.md`).

## 1. Install / upgrade the module (5 minutes)

1. Navigate to `<instance>/admin/modules`.
2. `find` the "Upload Module" file input and the **Install** button; `file_upload` the zip to the input; click Install. (Do it as *one* navigation → upload → click; re-submitting the form on a stale page returns "Page expired" and silently does nothing.)
3. On the module list, click **Activate** on "Perfex CRM API Layer" (first install only; upgrades stay active). Confirm the version string in the list matches the zip.
4. Open `<instance>/admin/perfex_crm_api_layer/admin` (Setup → API Layer). It shows the base URL, the token, the endpoint list, and the staff id used as `addedfrom`. Set the staff id to the owner's staff account if it isn't 1.
5. Smoke test from that page:
   ```js
   const tok=[...document.querySelectorAll('code')].map(c=>c.innerText).find(c=>/^[0-9a-f]{40,}$/.test(c));
   await (await fetch('/perfex_crm_api_layer/api/meta',{headers:{'X-Api-Token':tok}})).json()
   ```
   Expect `{ok:true, perfex_version, php_version, date_format, base_currency, staff_id}`. Keep the token on `window` for the rest of the session so it is fetched once and never printed.

## 2. Learn the instance (10 minutes)

From the admin page, pull and record in `README.md` → *Instances* (and life-ops):

- `GET /expense_categories`, `/payment_modes`, `/currencies`, `/customers` — the id maps automation needs. Note that currency ids are per-instance: on AronCorp, CAD = 1 and USD = 3.
- `GET /expenses?from=<last year>-01-01&limit=2000` grouped by month → **where the expenses stop** (the catch-up start date). **Page it with `offset`** — the endpoint caps at 500 rows whatever `limit` says, and a truncated read makes the books look like they stop months earlier than they do.
- The **`reference_no` convention**, which is the dedupe key and differs per instance: InteliClic uses the bank's own transaction id; AronCorp uses `<SourceTag>-<YYYY-MM-DD>-<nnnn>` with a per-source counter that restarts each calendar year. On a counter-style instance, read the current max per source before generating new references.
- `GET /invoices` and `GET /payments` for the same window → whether revenue is already current (on InteliClic it was; usually only expenses lag).
- Build the **merchant → category map** from last year's expenses: `expense_name.lower()` → most-used category. Save as `tools/namemap_<instance>_<year>.txt` (`name|category_id` per line). This is what makes the next catch-up 80% automatic.
- Note the conventions you see: how names are cleaned, what goes in `note`, what `reference_no` holds, which payment mode maps to which source.

Missing categories or customers: create them in the Perfex UI (Setup → Finance → Expense Categories; Customers → New) — or from the admin page with a CSRF-signed POST:
```js
const fd=new URLSearchParams(); fd.append(csrfData.token_name,csrfData.hash); fd.append('name','Food & Beverage'); fd.append('description','…');
await (await fetch('/admin/expenses/category',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})).text()
```
(`id` in the same POST renames an existing category.) Customer: POST `/admin/clients/client` with `company`, `default_currency`, optional `country/city/state`. **Ask the user before adding categories** — the standing rule is "use the categories that already exist; add only what's missing" (InteliClic added Business Development, Owner Advances, Food & Beverage; renamed Supplies → Office Equipment / Supplies).

## 3. Gather the statements

Ask the user for exports covering the gap, saved to Downloads (Claude stages them from there). **Check Downloads first** — the exports are often already sitting there from a previous pull; request folder access and look before asking the user to go pull statements again.

| Source type | What to ask for | Parser |
|---|---|---|
| Payoneer | Transactions → CSV export (`report_<date>.csv`: Date, Description, Amount, Currency, Status, Transaction ID) | `build_ledger.py` (dates like `27 Aug, 2026`, amounts with thousands separators) |
| Credicorp checking | Banca en línea → "Detalle de movimientos" → XLS (JasperReports; columns at fixed offsets: Fecha 1, Hora 3, Transacción 6, Concepto 11, Referencia 16, Retiros 18, Depósitos 23, Saldo 25) | `build_ledger.py` |
| Credicorp Visa | Monthly PDF statement emailed from estadodecuenta@credicorpbank.com (password-protected) — user saves them to Downloads | not yet automated |
| Scotiabank (AronCorp) | Online banking → each account → download CSV. Six accounts: Visa Momentum, chequing 0816, USD chequing 2712, savings 0711 / 9414 / 9112 | `build_ledger_aroncorp.py` (one shape for all six: card amounts positive for debits, bank-account amounts negative) |
| Other Canadian bank / cards | CSV export per month | add a parser (same shape: date, description, amount, reference) |
| PayPal | **Do not pull** when PayPal is funded by the card — it duplicates the card lines; the card charge already includes fees |

**Scotia export traps:** the web export silently caps at **100 rows** — a file with exactly 100 data rows is truncated, so re-pull it month by month. And a card's whole history exports under its *current* number, so the file named for the new card holds the old card's transactions too; dedupe on (date, description, amount) when combining exports.

Rules baked into the pipeline: skip cancelled/pending; skip deposits and "Payment from <client>" (revenue, already invoiced); withdrawals/transfers between own accounts are not expenses (book only the fee, e.g. the $15 above a round-thousand Payoneer withdrawal) — on a card this is the "payment from" credit, and on the funding account the matching "customer transfer dr.", so both sides drop out; a debit followed by a same-reference "RECHAZO ACH" credit was never paid; positive card charges are refunds → list them for the user, don't enter them; interest credits on savings accounts are income, not expenses.

## 4. Build the ledger and the review sheet

```bash
python3 tools/build_ledger.py            # InteliClic — edit the date window + input paths at the top
python3 tools/build_ledger_aroncorp.py   # AronCorp — edit the window, file names and NEXT counters
```
Categorization order: exact match in the name map → prefix match → `rules2.py` (vendor patterns) → `rules3.py` (client-level overrides, e.g. all Wise → Contractors/Central Flow) → everything else flagged `ASK`. Then write the review workbook (see the `openpyxl` block in the session history / rebuild from `ledger_2026_h1.json` shape): one row per expense, Category ID with a dropdown, Client ID, Confidence (exact/prefix/rule/convention/GUESS/ASK), Reference, Include Y/N; plus Summary (by category and month), Not-entered list, Notes. Commit it to the user's Downloads.

Ask the user only about the `ASK` rows, in one message, with amounts and dates. Typical answers to expect and store in the name map: unfamiliar PayPal descriptors (e.g. `PAYPAL *JLTEEL` = JLCPCB), bank ACH "invoice" lines (accountant, lawyer), one-word merchants.

**Resolve what you can before asking.** A bank line that names no payee is usually answerable from the user's own mail, and an answer beats a question:

- Interac e-transfers show only "Interac E-Transfer" in the CSV — the recipient is in the confirmation mail, `from:catch@payments.interac.ca` around that date. (This is how the Jul 2026 $3,680.25 → Empire CPA and $315.00 → Mill Bay Auto Spa were identified.)
- A bare amount often matches a receipt or invoice mail; searching Gmail for the amount as a literal string (`"3,680.25" OR "3680.25"`) finds it.
- Then check whether the payee already exists in the books under any spelling before inventing a category — `Empire Cpa` and `Mill Bay Auto Spa` were both already there, on the card rather than the bank account.

**Personal spend on the company card:** book it as what it is (owner advances / shareholder account) or leave it out; do not relabel it as a business category. Client meals → Business Development; all food incl. groceries → Food & Beverage (user's 2026 decision).

## 5. Push and verify

From the admin page: create a hidden `<input type=file>` on the page, `file_upload` the items JSON into it (avoids pasting 100 KB into the console), then:
```js
const items=JSON.parse(await document.getElementById('ledgerfile').files[0].text());
// items: [{category, amount, date:'YYYY-MM-DD', currency, expense_name, note, reference_no, paymentmode:'2', clientid}]
for (let i=0;i<items.length;i+=50) { /* PUT /expenses/batch {items: slice} — stop on first non-ok */ }
```
`tools/push_expenses.js` has the full function. Batch skips rows whose `reference_no`+`amount` already exist, so re-running is safe. For a small batch (under ~50 rows) it is simpler to assign the array to `window.__items` in one call and push it in the next.

Verify — and verify against the *source*, not only against your own ledger, or you only prove the push matched the file you wrote:

- `GET /expenses?from=&to=` (paged) → count, total, per-month and per-category sums must equal the review sheet.
- Then re-derive the same counts and totals straight from the statement CSVs, per account, and check they match what is now in Perfex. A parser bug that drops rows survives the first check and dies on this one.
- `billable` must be 0 everywhere; no dates outside the window; no duplicate `reference_no`.

Corrections: `PATCH /expenses/<id>`; removals: `DELETE /expenses/<id>` (refuses invoiced expenses).

## 6. Record

- `README.md` → *Instances*: version, categories, payment modes, "expenses current to".
- life-ops `docs/accounting.md`: what was pushed, totals, decisions, what's still missing.
- Commit any new/changed name maps, parsers and rules to this repo.

## Gotchas collected so far

- Writes must be PUT/PATCH/DELETE — Perfex's CSRF filter blocks POST on module routes.
- `Expenses_model::add/update` treats the *presence* of `billable` as true; the API only sends it when set.
- `GET /expenses` caps at 500 rows per call whatever `limit` says — always page with `offset`.
- Module views via `module_views_path()` failed on 3.4.1; the admin page renders with `init_head()/init_tail()`.
- The Perfex upload form uses a per-page CSRF token; one upload per page load.
- The instance's date format is read from `get_option('dateformat')` and handled by the API (`_d()`), so always send `YYYY-MM-DD`.
- Old rows can have `date = 0000-00-00` (seen on InteliClic id 792) — GET by id works; fix with PATCH if they matter.
- Perfex may not overwrite files if the zip's init file is empty/invalid — always `php -l` and check sizes before packing.
- Scotiabank's web export caps at 100 rows with no warning, and files a card's full history under its current number.
