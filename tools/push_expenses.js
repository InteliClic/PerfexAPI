// Paste into the browser console on  /admin/perfex_crm_api_layer/admin  (token is read from the page).
// Then:  await pushLedger(ledgerJson)   where ledgerJson = {ledger:[{date,name,note,amount,cat,ref,pm,client}, ...]}
async function pushLedger(L, chunk = 50) {
  const tok = [...document.querySelectorAll('code')].map(c => c.innerText).find(c => /^[0-9a-f]{40,}$/.test(c));
  const api = async (m, u, b) => (await fetch('/perfex_crm_api_layer/api/' + u, { method: m, headers: { 'X-Api-Token': tok, 'Content-Type': 'application/json' }, body: b ? JSON.stringify(b) : undefined })).json();
  const items = L.ledger.filter(e => e.cat).map(e => ({ category: e.cat, amount: e.amount, date: e.date, currency: 1, expense_name: e.name, note: e.note, reference_no: e.ref, paymentmode: String(e.pm), clientid: e.client || 0 }));
  const results = [];
  for (let i = 0; i < items.length; i += chunk) {
    const r = await api('PUT', 'expenses/batch', { items: items.slice(i, i + chunk) });
    results.push(r); console.log(i, r.created, r.error || '');
  }
  const byMonth = {}; for (const e of items) { const m = e.date.slice(0, 7); byMonth[m] = (byMonth[m] || 0) + e.amount; }
  console.table(byMonth);
  return results;
}
