<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Annual Accountant Packet — life-ops #103 + #104 in one document.
 * URL: /admin/perfex_crm_api_layer/reports
 *
 * Session-authenticated (admin only). No API token is embedded in the page.
 * Reads are GET, writes are PUT — Perfex's CSRF filter intercepts POST on module routes.
 */
class Reports extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!is_admin()) {
            access_denied('perfex_crm_api_layer');
        }
    }

    private function engine()
    {
        require_once __DIR__ . '/../libraries/Report_engine.php';
        return new Report_engine($this);
    }

    private function json($d, $code = 200)
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (function_exists('http_response_code')) { http_response_code($code); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d);
        exit;
    }

    private function params()
    {
        $from = $this->input->get('from');
        $to   = $this->input->get('to');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) { $from = date('Y') . '-01-01'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to))   { $to   = date('Y-m-d'); }
        return array(
            'from'             => $from,
            'to'               => $to,
            'basis'            => $this->input->get('basis') === 'cash' ? 'cash' : 'accrual',
            'present_currency' => (int) $this->input->get('present_currency'),
        );
    }

    // ------------------------------------------------------------------ data

    public function data()
    {
        $e = $this->engine();
        $p = $this->params();
        $r = $e->build($p);
        // Prior-year comparative for the P&L (#104), same window shifted back one year.
        if ($this->input->get('compare') === '1') {
            $py = $e->build(array(
                'from'  => date('Y-m-d', strtotime($p['from'] . ' -1 year')),
                'to'    => date('Y-m-d', strtotime($p['to'] . ' -1 year')),
                'basis' => $p['basis'], 'present_currency' => $p['present_currency'],
            ));
            $r['prior'] = array(
                'params'       => $py['params'],
                'pnl'          => $py['pnl'],
                'expenses'     => array('by_category' => $py['expenses']['by_category']),
                'revenue'      => array('by_currency' => $py['revenue']['by_currency']),
            );
        }
        $this->json($r);
    }

    public function settings()
    {
        $e = $this->engine();
        $p = db_prefix();
        $this->json(array(
            'ok'          => true,
            'balances'    => $this->db->order_by('date', 'desc')->order_by('account', 'asc')->get($p . 'papi_bank_balances')->result_array(),
            'adjustments' => $this->db->order_by('date', 'asc')->get($p . 'papi_adjustments')->result_array(),
            'fx_rates'    => $this->db->order_by('date', 'desc')->limit(300)->get($p . 'papi_fx_rates')->result_array(),
            'categories'  => $this->db->order_by('name', 'asc')->get($p . 'expenses_categories')->result_array(),
            'currencies'  => $this->db->get($p . 'currencies')->result_array(),
            'other_income_categories' => $e->otherIncomeCategories(),
        ));
    }

    /** GET /reports/fx_needed?from=&to=&present_currency= */
    public function fx_needed()
    {
        $e = $this->engine();
        $p = $this->params();
        $pc = $p['present_currency'];
        if ($pc < 1) {
            foreach ($e->currencies() as $id => $c) { if ((int) $c['isdefault'] === 1) { $pc = $id; } }
        }
        $need = $e->fxNeeded($p['from'], $p['to'], $pc);
        $missing = array();
        foreach ($need as $n) { if (!$n['has_exact']) { $missing[] = $n; } }
        $this->json(array('ok' => true, 'data' => $need, 'missing_exact' => $missing));
    }

    /** PUT /reports/save  {what: balances|adjustments|fx|config, items:[...]} */
    public function save()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'PUT') {
            $this->json(array('error' => 'method_not_allowed', 'hint' => 'use PUT'), 405);
        }
        $this->engine();
        $in   = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) { $this->json(array('error' => 'invalid_json'), 400); }
        $what = isset($in['what']) ? $in['what'] : '';
        $p    = db_prefix();

        if ($what === 'config') {
            $ids = array();
            foreach ((array) $in['items'] as $v) { if ((int) $v > 0) { $ids[] = (int) $v; } }
            update_option('perfex_crm_api_layer_other_income_categories', implode(',', array_unique($ids)));
            $this->json(array('ok' => true));
        }

        $map = array(
            'balances'    => array($p . 'papi_bank_balances', array('date', 'account'), array('date', 'account', 'currency', 'amount', 'note')),
            'adjustments' => array($p . 'papi_adjustments',   array('date', 'label'),   array('date', 'label', 'amount', 'currency', 'note')),
            'fx'          => array($p . 'papi_fx_rates',      array('date', 'base', 'quote'), array('date', 'base', 'quote', 'rate', 'source')),
        );
        if (!isset($map[$what])) { $this->json(array('error' => 'unknown_target', 'what' => $what), 400); }
        list($table, $keys, $cols) = $map[$what];

        $n = 0; $deleted = 0;
        foreach ((array) $in['items'] as $it) {
            if (!empty($it['_delete']) && !empty($it['id'])) {
                $this->db->where('id', (int) $it['id'])->delete($table);
                $deleted++;
                continue;
            }
            $row = array();
            foreach ($cols as $c) {
                if (!isset($it[$c])) { continue; }
                $row[$c] = in_array($c, array('amount', 'rate')) ? (float) $it[$c]
                    : (in_array($c, array('currency')) ? (int) $it[$c] : $it[$c]);
            }
            if (!count($row)) { continue; }
            $this->db->reset_query();
            foreach ($keys as $k) { if (isset($row[$k])) { $this->db->where($k, $row[$k]); } }
            $ex = $this->db->get($table)->row();
            if ($ex) { $this->db->where('id', $ex->id)->update($table, $row); }
            else { $this->db->insert($table, $row); }
            $n++;
        }
        $this->json(array('ok' => true, 'saved' => $n, 'deleted' => $deleted));
    }

    // ------------------------------------------------------------------ Excel

    /**
     * SpreadsheetML 2003 — a plain XML string, no library. Deliberate: this has to
     * work on the InteliClic box (Ubuntu 14.04, old PHP) and on AronCorp (PHP 8.3)
     * without depending on whatever spreadsheet library each Perfex build ships.
     * Excel, LibreOffice and Google Sheets all open it, and it carries real sheets.
     */
    public function xls()
    {
        $e = $this->engine();
        $p = $this->params();
        $r = $e->build($p);
        $cc = $r['params']['present_currency_code'];

        $sheets = array();

        $s = array(array('Annual Accountant Packet'), array($r['meta']['company']),
            array('Period', $p['from'] . ' to ' . $p['to']),
            array('Basis', $r['pnl']['revenue_label']),
            array('Presentation currency', $cc),
            array('Generated', $r['meta']['generated_at']), array(),
            array('Every derived figure on every sheet is computed from dates as at ' . $p['to'] . '.'),
            array('Invoice status is never read from the stored status field, which reflects today.'),
            array());
        if (count($r['warnings'])) {
            $s[] = array('Notes');
            foreach ($r['warnings'] as $w) { $s[] = array($w); }
        }
        $sheets['Cover'] = $s;

        $s = array(array('Profit and Loss', '', $cc), array(),
            array($r['pnl']['revenue_label'], '', $r['pnl']['revenue']));
        foreach ($r['revenue']['by_currency'] as $c) {
            $s[] = array('   ' . $c['currency_code'] . ' ' . number_format($c['invoiced'], 2), '', $c['converted']);
        }
        $s[] = array();
        $s[] = array('Other income', '', $r['pnl']['other_income']);
        foreach ($r['other_income']['by_category'] as $c) {
            $s[] = array('   ' . $c['category_name'], '', $c['converted']);
        }
        $s[] = array();
        $s[] = array('Expenses', '', '', '% of revenue');
        foreach ($r['expenses']['by_category'] as $c) {
            $s[] = array('   ' . $c['category_name'], '', $c['converted'], $c['pct_of_revenue']);
        }
        $s[] = array('Total expenses', '', $r['pnl']['expenses']);
        $s[] = array();
        $s[] = array('NET', '', $r['pnl']['net']);
        $sheets['P&L'] = $s;

        $s = array(array('Cash reconciliation as at ' . $p['to'], '', $cc), array());
        foreach ($r['reconciliation']['lines'] as $l) {
            $s[] = array($l['label'], '', $l['amount']);
        }
        $s[] = array();
        $s[] = array('Difference', '', $r['reconciliation']['difference']);
        $s[] = array('', '', $r['reconciliation']['message']);
        $sheets['Reconciliation'] = $s;

        $s = array(array('Revenue by customer'), array(), array('Customer', 'Invoices', $cc));
        foreach ($r['revenue']['by_customer'] as $c) {
            $s[] = array($c['customer'], $c['count'], round($c['converted'], 2));
        }
        $sheets['Revenue by customer'] = $s;

        $s = array(array('Invoices dated in the period, as at ' . $p['to']), array(),
            array('Invoice', 'Date', 'Due', 'Customer', 'Ccy', 'Total', 'Paid as at ' . $p['to'],
                  'Open as at ' . $p['to'], 'Status as at ' . $p['to'], 'Status today'));
        foreach ($r['invoices'] as $i) {
            $s[] = array($i['number'], $i['date'], $i['duedate'], $i['customer'], $i['currency_code'],
                $i['total'], $i['paid_asat'], $i['open_asat'], $i['status_asat'], $i['status_today']);
        }
        $sheets['Invoices as-at'] = $s;

        $s = array(array('Accounts receivable as at ' . $p['to']), array(),
            array('Total open', '', $r['ar']['total_open']),
            array('   arose in period', '', $r['ar']['arose_in_period']),
            array('   arose before period', '', $r['ar']['arose_before_period']), array(),
            array('Aging', '0-30', '31-60', '61-90', '90+'),
            array('All', $r['ar']['aging_all']['0-30'], $r['ar']['aging_all']['31-60'], $r['ar']['aging_all']['61-90'], $r['ar']['aging_all']['90+']),
            array('Period only', $r['ar']['aging_period']['0-30'], $r['ar']['aging_period']['31-60'], $r['ar']['aging_period']['61-90'], $r['ar']['aging_period']['90+']),
            array(), array('Invoice', 'Date', 'Customer', 'Ccy', 'Open', 'Days', 'Arose in period'));
        foreach ($r['ar']['detail'] as $i) {
            $s[] = array($i['number'], $i['date'], $i['customer'], $i['currency_code'],
                $i['open_asat'], $i['days_outstanding'], $i['arose_in_period'] ? 'yes' : 'no');
        }
        $sheets['AR aging'] = $s;

        $s = array(array('Expenses by category'), array(), array('Category', 'Rows', $cc, '% of revenue'));
        foreach ($r['expenses']['by_category'] as $c) {
            $s[] = array($c['category_name'], $c['count'], $c['converted'], $c['pct_of_revenue']);
        }
        $s[] = array('Total', $r['expenses']['row_count'], $r['expenses']['total_converted']);
        $s[] = array();
        $s[] = array('By month');
        foreach ($r['expenses']['by_month'] as $m) { $s[] = array($m['month'], '', round($m['expenses_converted'], 2)); }
        $sheets['Expenses'] = $s;

        $s = array(array('Collections in period (by payment date)'), array(),
            array('Against invoices dated in the period', '', $r['collections']['against_period_invoices']),
            array('Against invoices dated before the period', '', $r['collections']['against_prior_invoices']),
            array('Total', '', $r['collections']['total_converted']), array(),
            array('Currency', 'Collected', $cc));
        foreach ($r['collections']['by_currency'] as $c) {
            $s[] = array($c['currency_code'], $c['collected'], $c['converted']);
        }
        $sheets['Collections'] = $s;

        if (count($r['fx']['rates_used'])) {
            $s = array(array('Exchange rates applied'), array(),
                array('Rates are taken on the transaction date; for a collection, the day the money was received.'),
                array('Where the market was closed, the most recent prior published rate is used.'), array(),
                array('Pair', 'Transaction date', 'Rate date', 'Rate', 'Source'));
            foreach ($r['fx']['rates_used'] as $f) {
                $s[] = array($f['base'] . '/' . $f['quote'], $f['txn_date'], $f['rate_date'], $f['rate'], $f['source']);
            }
            $sheets['FX rates'] = $s;
        }

        if (count($r['excluded']['draft']) || count($r['excluded']['cancelled'])) {
            $s = array(array('Excluded from revenue — listed, not dropped'), array(),
                array('Kind', 'Invoice', 'Date', 'Customer', 'Total'));
            foreach ($r['excluded']['draft'] as $i) { $s[] = array('Draft', $i['number'], $i['date'], $i['customer'], $i['total']); }
            foreach ($r['excluded']['cancelled'] as $i) { $s[] = array('Cancelled', $i['number'], $i['date'], $i['customer'], $i['total']); }
            $s[] = array();
            $s[] = array('Perfex stores no cancellation date, so an invoice cancelled after the period end cannot be told apart from one cancelled before it.');
            $sheets['Excluded'] = $s;
        }

        $name = 'accountant-packet-' . $p['from'] . '_' . $p['to'] . '.xls';
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo $this->spreadsheetML($sheets);
        exit;
    }

    private function spreadsheetML($sheets)
    {
        $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $x .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        $x .= '<Styles>'
            . '<Style ss:ID="t"><Font ss:Bold="1" ss:Size="13"/></Style>'
            . '<Style ss:ID="h"><Font ss:Bold="1"/><Interior ss:Color="#EFEFEF" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="m"><NumberFormat ss:Format="#,##0.00"/></Style>'
            . '</Styles>' . "\n";
        foreach ($sheets as $name => $rows) {
            $x .= '<Worksheet ss:Name="' . htmlspecialchars(substr(str_replace(array('/', '\\', '?', '*', '[', ']', ':'), '-', $name), 0, 31), ENT_QUOTES) . '"><Table>' . "\n";
            $ri = 0;
            foreach ($rows as $row) {
                $ri++;
                $x .= '<Row>';
                foreach ((array) $row as $cell) {
                    if ($cell === null || $cell === '') { $x .= '<Cell/>'; continue; }
                    if (is_int($cell) || is_float($cell)) {
                        $x .= '<Cell ss:StyleID="m"><Data ss:Type="Number">' . $cell . '</Data></Cell>';
                    } else {
                        $st = ($ri === 1) ? ' ss:StyleID="t"' : '';
                        $x .= '<Cell' . $st . '><Data ss:Type="String">'
                            . htmlspecialchars((string) $cell, ENT_QUOTES) . '</Data></Cell>';
                    }
                }
                $x .= '</Row>' . "\n";
            }
            $x .= '</Table></Worksheet>' . "\n";
        }
        return $x . '</Workbook>';
    }

    // ------------------------------------------------------------------ view

    public function index()
    {
        $this->load->vars(array('title' => 'Accountant Packet'));
        init_head();
        echo '<div id="wrapper"><div class="content">';
        echo $this->shell();
        echo '</div></div>';
        init_tail();
        echo $this->script();
        echo '</body></html>';
    }

    private function shell()
    {
        $base = admin_url('perfex_crm_api_layer/reports');
        $y    = date('Y');
        $h    = '';
        $h .= '<style>' . $this->css() . '</style>';
        $h .= '<div class="row"><div class="col-md-12">';

        $h .= '<div class="panel_s noprint"><div class="panel-body">'
            . '<div class="pk-controls">'
            . '<div><label>From</label><input type="date" id="pk-from" class="form-control" value="' . $y . '-01-01"></div>'
            . '<div><label>To</label><input type="date" id="pk-to" class="form-control" value="' . $y . '-12-31"></div>'
            . '<div><label>Basis</label><select id="pk-basis" class="form-control">'
            . '<option value="accrual">Accrual (invoiced)</option><option value="cash">Cash (collected)</option></select></div>'
            . '<div><label>Currency</label><select id="pk-ccy" class="form-control"></select></div>'
            . '<div><label>&nbsp;</label><div><label class="pk-chk"><input type="checkbox" id="pk-compare"> Prior year</label></div></div>'
            . '<div><label>&nbsp;</label><div>'
            . '<button class="btn btn-primary" id="pk-run">Run</button> '
            . '<button class="btn btn-default" id="pk-print">Print / PDF</button> '
            . '<button class="btn btn-default" id="pk-xls">Excel</button> '
            . '<button class="btn btn-default" id="pk-settings-btn">Setup</button>'
            . '</div></div>'
            . '</div>'
            . '<div class="pk-hint">Every figure is computed from dates as at the end date. Invoice status is derived from payment dates, never read from the stored status field.</div>'
            . '</div></div>';

        $h .= '<div id="pk-settings" class="panel_s noprint" style="display:none"><div class="panel-body">'
            . '<h4>Setup</h4>'
            . '<p class="pk-hint">These live in the module, so the packet is self-contained and reproducible years later.</p>'
            . '<div id="pk-settings-body">Loading…</div>'
            . '</div></div>';

        $h .= '<div id="pk-out"></div>';
        $h .= '</div></div>';
        $h .= '<script>window.PK_BASE=' . json_encode($base) . ';</script>';
        return $h;
    }

    private function css()
    {
        return '
.pk-controls{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.pk-controls>div{min-width:130px}
.pk-controls label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#7a8699;margin-bottom:3px}
.pk-chk{text-transform:none!important;font-size:13px!important;color:#333!important;font-weight:400}
.pk-hint{margin-top:10px;font-size:12px;color:#7a8699;line-height:1.5}
.pk-sec{background:#fff;border:1px solid #e3e8ef;border-radius:3px;margin-bottom:14px;padding:20px 24px}
.pk-sec h3{margin:0 0 2px;font-size:15px;font-weight:600;letter-spacing:.01em}
.pk-sec .pk-sub{font-size:12px;color:#7a8699;margin-bottom:14px}
.pk-cover{border-left:3px solid #2c3e50}
.pk-cover h2{margin:0 0 4px;font-size:20px;font-weight:600}
.pk-cover .pk-meta{font-size:12px;color:#63708a;line-height:1.7}
table.pk{width:100%;border-collapse:collapse;font-size:12.5px}
table.pk th{text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em;
  color:#63708a;border-bottom:1px solid #d8dee9;padding:6px 8px}
table.pk td{padding:5px 8px;border-bottom:1px solid #f0f2f6;vertical-align:top}
table.pk td.n,table.pk th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
table.pk tr.tot td{border-top:1.5px solid #c3ccd9;border-bottom:none;font-weight:600;padding-top:8px}
table.pk tr.grand td{border-top:2.5px double #4a5568;font-weight:700;font-size:14px;padding-top:10px}
table.pk tr.sub td{color:#63708a}
table.pk tr.zero td{color:#a3adbd}
.pk-net{display:flex;justify-content:space-between;align-items:baseline;margin-top:16px;padding-top:14px;border-top:2.5px double #4a5568}
.pk-net .l{font-size:14px;font-weight:600}
.pk-net .v{font-size:24px;font-weight:700;font-variant-numeric:tabular-nums}
.pk-neg{color:#b3282d}
.pk-warn{background:#fff8e6;border:1px solid #f0d9a0;border-radius:3px;padding:12px 16px;margin-bottom:14px;font-size:12.5px;line-height:1.6}
.pk-warn b{display:block;margin-bottom:6px}
.pk-warn ul{margin:0;padding-left:18px}
.pk-ok{background:#eef8f0;border:1px solid #b9dfc4;color:#1e6b34}
.pk-bad{background:#fdecec;border:1px solid #f0b6b6;color:#a3282d}
.pk-tie{padding:10px 16px;border-radius:3px;font-size:13px;font-weight:600;margin-top:12px}
.pk-flag{display:inline-block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;
  background:#fde9c8;color:#8a5a00;padding:1px 6px;border-radius:2px;margin-left:6px;font-weight:600}
.pk-pills{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:4px}
.pk-pill{flex:1;min-width:150px;border-left:2px solid #dde3ec;padding-left:12px}
.pk-pill .k{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#7a8699}
.pk-pill .v{font-size:18px;font-weight:600;font-variant-numeric:tabular-nums;margin-top:2px}
.pk-note{font-size:11.5px;color:#7a8699;line-height:1.6;margin-top:10px;padding-top:10px;border-top:1px solid #f0f2f6}
.pk-bar{height:7px;background:#eef1f6;border-radius:2px;overflow:hidden;min-width:60px}
.pk-bar i{display:block;height:100%;background:#5b7fa6}
.pk-editrow{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px}
.pk-editrow input,.pk-editrow select{padding:4px 7px;border:1px solid #d5dce6;border-radius:3px;font-size:12.5px}
@media print{
  .noprint,#wrapper>.navbar,nav,header,footer,.menu,#header,#setup-menu,.sidebar,#side-menu{display:none!important}
  body,#wrapper,.content{margin:0!important;padding:0!important;background:#fff!important;width:100%!important}
  .pk-sec{page-break-inside:avoid;border:none;border-bottom:1px solid #ccc;padding:14px 0;margin:0 0 8px}
  .pk-cover{border-left:none;border-bottom:2px solid #333}
  table.pk{font-size:10.5px} table.pk th{padding:4px 5px} table.pk td{padding:3px 5px}
  a[href]:after{content:""}
}
@page{margin:14mm 12mm}
';
    }

    private function script()
    {
        // Rendered as a plain string so it survives Perfex's asset pipeline entirely.
        return '<script>' . <<<'JS'
(function(){
var B = window.PK_BASE, S = null, CUR = null;
function $(id){ return document.getElementById(id); }
function esc(s){ return String(s===null||s===undefined?'':s).replace(/[&<>"]/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function m(v){ if(v===null||v===undefined) return '<span style="color:#a3adbd">n/a</span>';
  var n=Number(v); if(Math.abs(n)<0.005) n=0;
  var s=n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  return n<0 ? '<span class="pk-neg">('+s.replace('-','')+')</span>' : s; }
function qs(){ var p=new URLSearchParams();
  p.set('from',$('pk-from').value); p.set('to',$('pk-to').value);
  p.set('basis',$('pk-basis').value); p.set('present_currency',$('pk-ccy').value||'0');
  if($('pk-compare').checked) p.set('compare','1');
  return p.toString(); }
function get(path,q){ return fetch(B+'/'+path+(q?'?'+q:''),{credentials:'same-origin'})
  .then(function(r){ return r.json(); }); }
function put(body){ return fetch(B+'/save',{method:'PUT',credentials:'same-origin',
  headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json();}); }

// ---------- render ----------
function sec(title,sub,inner,cls){ return '<div class="pk-sec '+(cls||'')+'"><h3>'+esc(title)+'</h3>'+
  (sub?'<div class="pk-sub">'+sub+'</div>':'')+inner+'</div>'; }

function render(r){
  var cc = r.params.present_currency_code, out = '';

  out += '<div class="pk-sec pk-cover"><h2>'+esc(r.meta.company||'')+' &middot; Annual Accountant Packet</h2>'+
    '<div class="pk-meta"><b>Period</b> '+esc(r.params.from)+' to '+esc(r.params.to)+
    ' &nbsp;&middot;&nbsp; <b>Basis</b> '+esc(r.pnl.revenue_label)+
    ' &nbsp;&middot;&nbsp; <b>Currency</b> '+esc(cc)+
    '<br>Generated '+esc(r.meta.generated_at)+
    '<br>Every derived figure is computed from dates as at '+esc(r.params.to)+
    '. Invoice status is never read from the stored status field.</div></div>';

  if(r.warnings && r.warnings.length){
    out += '<div class="pk-warn"><b>Read before sending</b><ul>'+
      r.warnings.map(function(w){return '<li>'+esc(w)+'</li>';}).join('')+'</ul></div>';
  }

  // ---- P&L
  var pr = r.prior, rows='';
  function cmpCells(cur, prv){
    if(!pr) return '';
    var d = (prv===null||prv===undefined) ? null : cur-prv;
    return '<td class="n">'+m(prv)+'</td><td class="n">'+(d===null?'':m(d))+'</td>';
  }
  rows += '<tr class="tot"><td>'+esc(r.pnl.revenue_label)+'</td><td class="n">'+m(r.pnl.revenue)+'</td>'+
    (pr?cmpCells(r.pnl.revenue, pr.pnl.revenue):'')+'<td class="n"></td></tr>';
  r.revenue.by_currency.forEach(function(c){
    rows += '<tr class="sub"><td style="padding-left:22px">'+esc(c.currency_code)+' '+m(c.invoiced)+
      (c.currency_code!==cc?' &rarr; '+esc(cc):'')+(c.converted_complete?'':'<span class="pk-flag">rate missing</span>')+
      '</td><td class="n">'+m(c.converted)+'</td>'+(pr?'<td></td><td></td>':'')+'<td></td></tr>';
  });
  if(r.other_income.by_category.length){
    rows += '<tr class="tot"><td>Other income</td><td class="n">'+m(r.pnl.other_income)+'</td>'+
      (pr?cmpCells(r.pnl.other_income, pr.pnl.other_income):'')+'<td></td></tr>';
    r.other_income.by_category.forEach(function(c){
      rows += '<tr class="sub"><td style="padding-left:22px">'+esc(c.category_name)+'</td><td class="n">'+
        m(c.converted)+'</td>'+(pr?'<td></td><td></td>':'')+'<td></td></tr>';
    });
  }
  var pmap={}; if(pr) pr.expenses.by_category.forEach(function(c){ pmap[c.category]=c.converted; });
  var maxExp = 0; r.expenses.by_category.forEach(function(c){ if(Math.abs(c.converted)>maxExp) maxExp=Math.abs(c.converted); });
  rows += '<tr class="tot"><td>Expenses</td><td class="n"></td>'+(pr?'<td></td><td></td>':'')+'<td></td></tr>';
  r.expenses.by_category.forEach(function(c){
    var z = Math.abs(c.converted)<0.005;
    rows += '<tr class="sub'+(z?' zero':'')+'"><td style="padding-left:22px">'+esc(c.category_name)+
      (z?' <span style="font-size:11px">(no rows in period)</span>':'')+'</td><td class="n">'+m(c.converted)+'</td>'+
      (pr?cmpCells(c.converted, pmap[c.category]===undefined?null:pmap[c.category]):'')+
      '<td class="n" style="width:90px">'+(c.pct_of_revenue===null?'':
        '<div style="display:flex;align-items:center;gap:6px;justify-content:flex-end">'+
        '<span style="font-size:11px;color:#7a8699">'+c.pct_of_revenue.toFixed(1)+'%</span>'+
        '<span class="pk-bar" style="width:46px"><i style="width:'+
        Math.min(100,maxExp?Math.abs(c.converted)/maxExp*100:0)+'%"></i></span></div>')+'</td></tr>';
  });
  rows += '<tr class="tot"><td>Total expenses</td><td class="n">'+m(r.pnl.expenses)+'</td>'+
    (pr?cmpCells(r.pnl.expenses, pr.pnl.expenses):'')+'<td></td></tr>';
  rows += '<tr class="grand"><td>Net</td><td class="n">'+m(r.pnl.net)+'</td>'+
    (pr?cmpCells(r.pnl.net, pr.pnl.net):'')+'<td></td></tr>';

  var pnlHead = '<tr><th>&nbsp;</th><th class="n">'+esc(cc)+'</th>'+
    (pr?'<th class="n">Prior year</th><th class="n">Variance</th>':'')+'<th class="n">% of rev</th></tr>';
  out += sec('Profit and loss',
    'Presented on the '+esc(r.pnl.basis)+' basis. Categories with no rows in the period are shown at zero so nothing looks omitted.',
    '<table class="pk"><thead>'+pnlHead+'</thead><tbody>'+rows+'</tbody></table>'+
    (r.other_income.by_category.length ? '<div class="pk-note">'+esc(r.other_income.note)+
      ' The cash reconciliation nets them back against expenses, which is why its <i>gastos</i> figure is '+
      m(r.pnl.expenses - r.pnl.other_income)+' rather than '+m(r.pnl.expenses)+'.</div>' : ''));

  // ---- reconciliation
  var rec = r.reconciliation, rl='';
  rec.lines.forEach(function(l){
    var cls = (l.kind==='subtotal'||l.kind==='total')?'tot':(l.kind==='actual'?'tot':'');
    rl += '<tr class="'+cls+'"><td>'+esc(l.label)+
      (l.note?'<div style="font-size:11px;color:#7a8699;margin-top:2px">'+esc(l.note)+'</div>':'')+
      (l.detail&&l.detail.length?'<div style="font-size:11px;color:#7a8699;margin-top:2px">'+
        l.detail.map(function(d){return esc(d.account)+' '+Number(d.amount).toLocaleString(undefined,{minimumFractionDigits:2})+
          (d.stale?' <span class="pk-flag">as at '+esc(d.date)+'</span>':'');}).join(' &middot; ')+'</div>':'')+
      '</td><td class="n">'+m(l.amount)+'</td></tr>';
  });
  rl += '<tr class="grand"><td>Difference</td><td class="n">'+m(rec.difference)+'</td></tr>';
  out += sec('Cash reconciliation',
    'Opening balance, plus what was billed, less what was still owed at the end date, less what was spent — against the actual bank balance.',
    '<table class="pk"><tbody>'+rl+'</tbody></table>'+
    '<div class="pk-tie '+(rec.ties?'pk-ok':'pk-bad')+'">'+esc(rec.message)+'</div>'+
    '<div class="pk-note">The line for collections against pre-period invoices is shown even when it is 0.00. '+
    'A reconciliation that ties only because a line was silently absent is a reconciliation that ties by luck.</div>');

  // ---- AR
  var ar = r.ar, ag='';
  ['0-30','31-60','61-90','90+'].forEach(function(b){
    ag += '<tr><td>'+b+' days</td><td class="n">'+m(ar.aging_all[b])+'</td><td class="n">'+m(ar.aging_period[b])+'</td></tr>';
  });
  ag += '<tr class="tot"><td>Total</td><td class="n">'+m(ar.total_open)+'</td><td class="n">'+m(ar.arose_in_period)+'</td></tr>';
  var arRows = ar.detail.map(function(i){
    return '<tr><td>'+esc(i.number)+'</td><td>'+esc(i.date)+'</td><td>'+esc(i.customer||'')+'</td>'+
      '<td class="n">'+m(i.open_asat)+'</td><td class="n">'+i.days_outstanding+'</td>'+
      '<td>'+(i.arose_in_period?'in period':'<span class="pk-flag">pre-period</span>')+'</td></tr>';
  }).join('');
  out += sec('Accounts receivable as at '+esc(r.params.to),
    'Two different numbers, both correct — and confusing them is what made a settled question look like missing money.',
    '<div class="pk-pills">'+
      '<div class="pk-pill"><div class="k">Total open (balance sheet)</div><div class="v">'+m(ar.total_open)+'</div></div>'+
      '<div class="pk-pill"><div class="k">Arose in period (reconciliation)</div><div class="v">'+m(ar.arose_in_period)+'</div></div>'+
      '<div class="pk-pill"><div class="k">Arose before period</div><div class="v">'+m(ar.arose_before_period)+'</div></div>'+
    '</div>'+
    '<table class="pk" style="margin-top:14px"><thead><tr><th>Aging</th><th class="n">All</th>'+
    '<th class="n">Period only</th></tr></thead><tbody>'+ag+'</tbody></table>'+
    '<table class="pk" style="margin-top:14px"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th>'+
    '<th class="n">Open</th><th class="n">Days</th><th>Origin</th></tr></thead><tbody>'+arRows+'</tbody></table>'+
    '<div class="pk-note">'+esc(ar.note)+'</div>');

  // ---- invoices as-at
  var flagged = 0;
  var invRows = r.invoices.map(function(i){
    var differs = (i.status_asat !== i.status_today);
    if(differs) flagged++;
    return '<tr><td>'+esc(i.number)+'</td><td>'+esc(i.date)+'</td><td>'+esc(i.customer||'')+'</td>'+
      '<td>'+esc(i.currency_code)+'</td><td class="n">'+m(i.total)+'</td><td class="n">'+m(i.paid_asat)+'</td>'+
      '<td class="n">'+m(i.open_asat)+'</td><td>'+esc(i.status_asat)+'</td>'+
      '<td style="color:#7a8699">'+esc(i.status_today)+(differs?'<span class="pk-flag">differs</span>':'')+'</td></tr>';
  }).join('');
  out += sec('Invoices dated in the period, as at '+esc(r.params.to),
    'The two right-hand columns are the whole point of this report.'+
    (flagged? ' <b>'+flagged+'</b> invoice'+(flagged>1?'s read':' reads')+' differently today than at the period end.' : ''),
    '<table class="pk"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Ccy</th>'+
    '<th class="n">Total</th><th class="n">Paid as at</th><th class="n">Open as at</th>'+
    '<th>Status as at</th><th>Status today</th></tr></thead><tbody>'+invRows+'</tbody></table>');

  // ---- revenue by customer + collections
  var cu = r.revenue.by_customer.slice().sort(function(a,b){ return b.converted-a.converted; });
  var cuRows = cu.map(function(c){
    var nat = Object.keys(c.by_currency).map(function(k){
      return esc(k)+' '+Number(c.by_currency[k]).toLocaleString(undefined,{minimumFractionDigits:2}); }).join(' · ');
    return '<tr><td>'+esc(c.customer)+'</td><td style="color:#7a8699;font-size:11.5px">'+nat+'</td>'+
      '<td class="n">'+c.count+'</td><td class="n">'+m(c.converted)+'</td></tr>';
  }).join('');
  out += sec('Revenue by customer',
    'Native amounts kept alongside the converted total, so both currencies stay visible.',
    '<table class="pk"><thead><tr><th>Customer</th><th>As invoiced</th><th class="n">Invoices</th>'+
    '<th class="n">'+esc(cc)+'</th></tr></thead><tbody>'+cuRows+
    '<tr class="tot"><td>Total</td><td></td><td class="n">'+r.revenue.invoice_count+'</td>'+
    '<td class="n">'+m(r.revenue.total_converted)+'</td></tr></tbody></table>');

  var co = r.collections;
  out += sec('Collections in period',
    'Counted by payment date, so a payment made in the window against an earlier invoice appears here — which invoiced revenue does not show.',
    '<div class="pk-pills">'+
      '<div class="pk-pill"><div class="k">Against invoices dated in period</div><div class="v">'+m(co.against_period_invoices)+'</div></div>'+
      '<div class="pk-pill"><div class="k">Against pre-period invoices</div><div class="v">'+m(co.against_prior_invoices)+'</div></div>'+
      '<div class="pk-pill"><div class="k">Total ('+esc(cc)+')</div><div class="v">'+m(co.total_converted)+'</div></div>'+
    '</div>'+
    (co.prior_detail.length? '<table class="pk" style="margin-top:14px"><thead><tr><th>Customer</th>'+
      '<th>Invoice dated</th><th>Paid</th><th class="n">Amount</th></tr></thead><tbody>'+
      co.prior_detail.map(function(d){ return '<tr><td>'+esc(d.customer||'')+'</td><td>'+esc(d.invoice_date)+
        '</td><td>'+esc(d.payment_date)+'</td><td class="n">'+m(d.amount)+'</td></tr>'; }).join('')+
      '</tbody></table>' : ''));

  // ---- FX
  if(r.fx.rates_used.length || r.fx.missing.length){
    var fxRows = r.fx.rates_used.map(function(f){
      var fb = f.txn_date!==f.rate_date;
      return '<tr><td>'+esc(f.base)+'/'+esc(f.quote)+'</td><td>'+esc(f.txn_date)+'</td>'+
        '<td>'+esc(f.rate_date)+(fb?'<span class="pk-flag">market closed</span>':'')+'</td>'+
        '<td class="n">'+esc(f.rate)+'</td><td style="color:#7a8699;font-size:11.5px">'+esc(f.source)+'</td></tr>';
    }).join('');
    var miss = r.fx.missing.length ? '<div class="pk-tie pk-bad">Missing '+r.fx.missing.length+
      ' rate(s): '+r.fx.missing.map(function(x){return esc(x.base+'/'+x.quote+' '+x.date);}).join(', ')+
      '. Converted totals are understated until these are loaded under Setup.</div>' : '';
    out += sec('Exchange rates applied', esc(r.fx.note),
      (fxRows?'<table class="pk"><thead><tr><th>Pair</th><th>Transaction date</th><th>Rate date</th>'+
        '<th class="n">Rate</th><th>Source</th></tr></thead><tbody>'+fxRows+'</tbody></table>':'')+miss);
  }

  // ---- excluded
  var ex = r.excluded, exAll = ex.draft.concat(ex.cancelled);
  if(exAll.length){
    var exRows = ex.draft.map(function(i){ return ['Draft',i]; })
      .concat(ex.cancelled.map(function(i){ return ['Cancelled',i]; }))
      .map(function(p){ var i=p[1]; return '<tr><td>'+p[0]+'</td><td>'+esc(i.number)+'</td><td>'+esc(i.date)+
        '</td><td>'+esc(i.customer||'')+'</td><td class="n">'+m(i.total)+'</td></tr>'; }).join('');
    out += sec('Excluded from revenue',
      'Listed rather than silently dropped, so the reader can see what was left out and why.',
      '<table class="pk"><thead><tr><th>Kind</th><th>Invoice</th><th>Date</th><th>Customer</th>'+
      '<th class="n">Total</th></tr></thead><tbody>'+exRows+'</tbody></table>'+
      '<div class="pk-note">Perfex stores no cancellation date, so an invoice cancelled after the period end '+
      'cannot be distinguished from one cancelled before it. Check these before sending.</div>');
  }
  $('pk-out').innerHTML = out;
}

// ---------- setup panel ----------
function renderSettings(s){
  CUR = s.currencies;
  var h = '';
  h += '<h5 style="margin-top:0">Bank balances</h5><div class="pk-hint" style="margin-bottom:8px">'+
    'The opening balance is the close on the day before the period starts; the closing balance is the close on the end date.</div>';
  h += '<div id="pk-bal">'+ s.balances.map(balRow).join('') +'</div>'+ balRow({}) +
    '<button class="btn btn-default btn-sm" onclick="PK.save(\'balances\')">Save balances</button>';

  h += '<hr><h5>Reconciliation adjustments</h5><div class="pk-hint" style="margin-bottom:8px">'+
    'Documented lines that belong between "balance per operations" and the actual bank balance — '+
    'the Visa timing difference and the audited-opening rounding, for example.</div>';
  h += '<div id="pk-adj">'+ s.adjustments.map(adjRow).join('') +'</div>'+ adjRow({}) +
    '<button class="btn btn-default btn-sm" onclick="PK.save(\'adjustments\')">Save adjustments</button>';

  h += '<hr><h5>Categories that are really income</h5><div class="pk-hint" style="margin-bottom:8px">'+
    'Perfex has no concept of income that is not an invoice, so bank interest is booked as a negative expense. '+
    'Flag those categories here and the packet presents them as income instead. The books are not modified.</div>';
  h += '<div id="pk-cats">'+ s.categories.map(function(c){
    var on = s.other_income_categories.indexOf(Number(c.id))>=0;
    return '<label style="display:inline-block;margin:0 14px 6px 0;font-weight:400">'+
      '<input type="checkbox" class="pk-cat" value="'+c.id+'"'+(on?' checked':'')+'> '+esc(c.name)+'</label>';
  }).join('') +'</div><button class="btn btn-default btn-sm" onclick="PK.save(\'config\')">Save categories</button>';

  h += '<hr><h5>Exchange rates</h5><div class="pk-hint" style="margin-bottom:8px">'+
    'Loaded only for dates that actually have a foreign-currency transaction — not a full calendar. '+
    'Run the period first, then click below to see exactly which dates need a rate.</div>'+
    '<button class="btn btn-default btn-sm" onclick="PK.fxNeeded()">Which dates need a rate?</button>'+
    '<div id="pk-fxneed" style="margin-top:10px"></div>'+
    '<div id="pk-fx" style="margin-top:10px">'+ s.fx_rates.slice(0,40).map(fxRow).join('') +'</div>'+ fxRow({}) +
    '<button class="btn btn-default btn-sm" onclick="PK.save(\'fx\')">Save rates</button>';

  $('pk-settings-body').innerHTML = h;
}
function ccySel(v){ return '<select data-f="currency">'+ (CUR||[]).map(function(c){
  return '<option value="'+c.id+'"'+(Number(v)===Number(c.id)?' selected':'')+'>'+esc(c.name)+'</option>'; }).join('')+'</select>'; }
function balRow(b){ return '<div class="pk-editrow" data-id="'+(b.id||'')+'">'+
  '<input type="date" data-f="date" value="'+esc(b.date||'')+'">'+
  '<input data-f="account" placeholder="Account" value="'+esc(b.account||'')+'" style="width:190px">'+
  ccySel(b.currency)+
  '<input data-f="amount" placeholder="0.00" value="'+esc(b.amount||'')+'" style="width:130px;text-align:right">'+
  '<input data-f="note" placeholder="Note (source of the figure)" value="'+esc(b.note||'')+'" style="flex:1;min-width:180px">'+
  (b.id?'<label style="font-weight:400;font-size:12px"><input type="checkbox" data-f="_delete"> delete</label>':'')+'</div>'; }
function adjRow(a){ return '<div class="pk-editrow" data-id="'+(a.id||'')+'">'+
  '<input type="date" data-f="date" value="'+esc(a.date||'')+'">'+
  '<input data-f="label" placeholder="Label as it should read on the statement" value="'+esc(a.label||'')+'" style="width:300px">'+
  '<input data-f="amount" placeholder="0.00" value="'+esc(a.amount||'')+'" style="width:120px;text-align:right">'+
  '<input data-f="note" placeholder="Why this line exists" value="'+esc(a.note||'')+'" style="flex:1;min-width:180px">'+
  (a.id?'<label style="font-weight:400;font-size:12px"><input type="checkbox" data-f="_delete"> delete</label>':'')+'</div>'; }
function fxRow(f){ return '<div class="pk-editrow" data-id="'+(f.id||'')+'">'+
  '<input type="date" data-f="date" value="'+esc(f.date||'')+'">'+
  '<input data-f="base" placeholder="USD" value="'+esc(f.base||'')+'" style="width:70px">'+
  '<input data-f="quote" placeholder="CAD" value="'+esc(f.quote||'')+'" style="width:70px">'+
  '<input data-f="rate" placeholder="1.3702" value="'+esc(f.rate||'')+'" style="width:110px;text-align:right">'+
  '<input data-f="source" placeholder="Bank of Canada FXUSDCAD" value="'+esc(f.source||'')+'" style="flex:1;min-width:180px">'+
  '</div>'; }

window.PK = {
  save: function(what){
    var items = [];
    if(what==='config'){
      [].forEach.call(document.querySelectorAll('.pk-cat'), function(c){ if(c.checked) items.push(c.value); });
    } else {
      var box = {balances:'pk-bal', adjustments:'pk-adj', fx:'pk-fx'}[what];
      var rows = $('pk-settings-body').querySelectorAll('.pk-editrow');
      [].forEach.call(rows, function(row){
        var o = {}, any = false;
        [].forEach.call(row.querySelectorAll('[data-f]'), function(el){
          var f = el.getAttribute('data-f');
          o[f] = (el.type==='checkbox') ? el.checked : el.value;
          if(el.type!=='checkbox' && el.value) any = true;
        });
        if(row.getAttribute('data-id')) o.id = row.getAttribute('data-id');
        var mine = (what==='fx') ? ('base' in o) : (what==='balances' ? ('account' in o) : ('label' in o));
        if(mine && (any || o._delete)) items.push(o);
      });
    }
    put({what:what, items:items}).then(function(res){
      if(res.ok){ loadSettings(); run(); } else { alert('Save failed: '+JSON.stringify(res)); }
    });
  },
  fxNeeded: function(){
    get('fx_needed', qs()).then(function(res){
      var d = res.missing_exact||[];
      if(!d.length){ $('pk-fxneed').innerHTML = '<div class="pk-tie pk-ok">Every foreign-currency date in this period has an exact rate.</div>'; return; }
      $('pk-fxneed').innerHTML = '<div class="pk-warn"><b>'+d.length+' date(s) need a rate</b>'+
        '<div style="font-family:monospace;font-size:12px;line-height:1.7">'+
        d.map(function(x){ return esc(x.base+'/'+x.quote+'  '+x.date)+
          (x.fallback_date?'  <span style="color:#8a5a00">(currently falling back to '+esc(x.fallback_date)+')</span>':
           '  <span style="color:#a3282d">(no rate at all — this amount is not converted)</span>'); }).join('<br>')+
        '</div></div>';
    });
  }
};

function loadSettings(){ get('settings').then(renderSettings); }

function run(){
  $('pk-out').innerHTML = '<div class="pk-sec">Running…</div>';
  get('data', qs()).then(function(r){
    if(!r || r.error){ $('pk-out').innerHTML = '<div class="pk-warn">'+esc(JSON.stringify(r))+'</div>'; return; }
    render(r);
  }).catch(function(e){ $('pk-out').innerHTML = '<div class="pk-warn">'+esc(String(e))+'</div>'; });
}

function boot(){
  get('settings').then(function(s){
    CUR = s.currencies;
    $('pk-ccy').innerHTML = s.currencies.map(function(c){
      return '<option value="'+c.id+'"'+(Number(c.isdefault)===1?' selected':'')+'>'+esc(c.name)+'</option>'; }).join('');
    renderSettings(s);
    run();
  });
  $('pk-run').onclick = function(e){ e.preventDefault(); run(); };
  $('pk-print').onclick = function(e){ e.preventDefault(); window.print(); };
  $('pk-xls').onclick = function(e){ e.preventDefault(); window.location = B+'/xls?'+qs(); };
  $('pk-settings-btn').onclick = function(e){ e.preventDefault();
    var p = $('pk-settings'); p.style.display = (p.style.display==='none') ? '' : 'none'; };
  ['pk-basis','pk-ccy','pk-compare'].forEach(function(id){ $(id).onchange = run; });
}
if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS
        . '</script>';
    }
}
