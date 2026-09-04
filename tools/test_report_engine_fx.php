<?php
define('BASEPATH', 1);

// ---- Perfex shims -------------------------------------------------------
$GLOBALS['OPTS'] = array(
    'companyname' => 'Aron Corp Inc.',
    'perfex_crm_api_layer_other_income_categories' => '19',
);
function db_prefix() { return 'tbl'; }
function get_option($k) { return isset($GLOBALS['OPTS'][$k]) ? $GLOBALS['OPTS'][$k] : false; }
function get_instance() { return $GLOBALS['CI']; }

class FakeResult {
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function result_array() { return $this->rows; }
}
class FakeDb {
    public $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function escape($s) { return $this->pdo->quote((string) $s); }
    public function query($sql) {
        if (stripos(trim($sql), 'CREATE TABLE') === 0) { return new FakeResult(array()); }
        $st = $this->pdo->query($sql);
        if ($st === false) { throw new Exception('SQL failed: ' . $sql . ' :: ' . implode(' ', $this->pdo->errorInfo())); }
        return new FakeResult($st->fetchAll(PDO::FETCH_ASSOC));
    }
}
class FakeCI { public $db; }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$CI = new FakeCI(); $CI->db = new FakeDb($pdo); $GLOBALS['CI'] = $CI;

// ---- Perfex-shaped schema ----------------------------------------------
$pdo->exec("CREATE TABLE tblcurrencies (id INT, name TEXT, symbol TEXT, isdefault INT)");
$pdo->exec("CREATE TABLE tblclients (userid INT, company TEXT)");
$pdo->exec("CREATE TABLE tblinvoices (id INT, number INT, prefix TEXT, number_format INT, date TEXT, duedate TEXT, total REAL, currency INT, status INT, clientid INT)");
$pdo->exec("CREATE TABLE tblinvoicepaymentrecords (id INT, invoiceid INT, amount REAL, date TEXT, paymentmode TEXT, transactionid TEXT)");
$pdo->exec("CREATE TABLE tblexpenses (id INT, category INT, amount REAL, date TEXT, currency INT, expense_name TEXT, reference_no TEXT, clientid INT, billable INT, invoiceid INT, tax INT, tax2 INT)");
$pdo->exec("CREATE TABLE tblexpenses_categories (id INT, name TEXT)");
$pdo->exec("CREATE TABLE tblpapi_bank_balances (id INTEGER PRIMARY KEY, date TEXT, account TEXT, currency INT, amount REAL, note TEXT)");
$pdo->exec("CREATE TABLE tblpapi_adjustments (id INTEGER PRIMARY KEY, date TEXT, label TEXT, amount REAL, currency INT, note TEXT)");
$pdo->exec("CREATE TABLE tblpapi_fx_rates (id INTEGER PRIMARY KEY, date TEXT, base TEXT, quote TEXT, rate REAL, source TEXT)");

$pdo->exec("INSERT INTO tblcurrencies VALUES (1,'CAD','\$',1),(3,'USD','US\$',0)");
$pdo->exec("INSERT INTO tblclients VALUES (1,'LogiCall Services Inc'),(2,'MJB Media Inc.'),(3,'Cescipholdings LLC'),(4,'Turn Key Marketing')");
$pdo->exec("INSERT INTO tblexpenses_categories VALUES (2,'Contractors'),(8,'Office Equipment / Supplies'),(10,'Bank Fees'),(19,'Bank Interest Received'),(11,'Software')");


// ===== AronCorp shape: CAD base, USD income, CAD expenses ================
$pdo->exec("INSERT INTO tblclients VALUES (10,'LogiCall Inc'),(11,'Domestic Client Ltd')");
// USD invoices, each collected on a different day => a different rate each time
$pdo->exec("INSERT INTO tblinvoices VALUES (7001,101,'INV-',1,'2026-03-02','2026-03-31',10000.00,3,2,10)");
$pdo->exec("INSERT INTO tblinvoices VALUES (7002,102,'INV-',1,'2026-06-01','2026-06-30',20000.00,3,2,10)");
// A CAD invoice alongside, so the stacking has both sides
$pdo->exec("INSERT INTO tblinvoices VALUES (7003,103,'INV-',1,'2026-04-01','2026-04-30',5000.00,1,2,11)");
// Payments: received 2026-03-14 and 2026-06-13 (a Saturday -> must fall back)
$pdo->exec("INSERT INTO tblinvoicepaymentrecords VALUES (8001,7001,10000.00,'2026-03-14','1','')");
$pdo->exec("INSERT INTO tblinvoicepaymentrecords VALUES (8002,7002,20000.00,'2026-06-13','1','')");
$pdo->exec("INSERT INTO tblinvoicepaymentrecords VALUES (8003,7003,5000.00,'2026-04-10','1','')");
$pdo->exec("INSERT INTO tblexpenses VALUES (9001,2,30000.00,'2026-05-01',1,'CAD contractors','R1',0,0,0,0,0)");
// Sparse rates only - a handful of days, not 365. Jun 13 is a Saturday: no rate published.
$pdo->exec("INSERT INTO tblpapi_fx_rates (date,base,quote,rate,source) VALUES
 ('2026-03-02','USD','CAD',1.3550,'Bank of Canada FXUSDCAD'),
 ('2026-03-14','USD','CAD',1.3702,'Bank of Canada FXUSDCAD'),
 ('2026-06-01','USD','CAD',1.3610,'Bank of Canada FXUSDCAD'),
 ('2026-06-12','USD','CAD',1.3805,'Bank of Canada FXUSDCAD')");

require __DIR__ . '/../perfex_crm_api_layer/libraries/Report_engine.php';
$e = new Report_engine($CI);
$rep = $e->build(array('from'=>'2026-01-01','to'=>'2026-12-31','basis'=>'cash','present_currency'=>1));

$fails=0;
function byCode($rows,$code){ foreach($rows as $r){ if($r['currency_code']===$code) return $r; } return null; }
function check($l,$g,$w){ global $fails; $ok=abs((float)$g-(float)$w)<0.005; if(!$ok)$fails++;
  printf("%-56s %14s   %s\n",$l,number_format((float)$g,2),$ok?'OK':'FAIL want '.number_format((float)$w,2)); }

echo "\n=== AronCorp shape: CAD base, USD income, date-of-payment FX ===\n\n";
echo "-- Income stacked by currency, not mixed --\n";
foreach ($rep['revenue']['by_currency'] as $c) {
  printf("   %-8s invoiced %14s   -> CAD %14s\n", $c['currency_code'],
    number_format($c['invoiced'],2), number_format($c['converted'],2));
}
echo "\n-- Collections converted at the rate on the day money was RECEIVED --\n";
foreach ($rep['fx']['rates_used'] as $r) {
  printf("   %s %s->%s  txn %s  rate %s (rate date %s)%s\n", '', $r['base'],$r['quote'],
    $r['txn_date'], $r['rate'], $r['rate_date'],
    $r['txn_date']!==$r['rate_date'] ? '  <- market closed, prior rate used' : '');
}
echo "\n";
check('USD collections converted at payment-date rates',
      byCode($rep['collections']['by_currency'],'USD')['converted'], 10000*1.3702 + 20000*1.3805);
check('CAD collections unconverted', byCode($rep['collections']['by_currency'],'CAD')['converted'], 5000.00);
check('Total collections in CAD', $rep['collections']['total_converted'], 10000*1.3702+20000*1.3805+5000);
check('Expenses stay CAD, no conversion', $rep['expenses']['total_converted'], 30000.00);
check('Net (cash basis)', $rep['pnl']['net'], 10000*1.3702+20000*1.3805+5000-30000);
check('No missing rates', count($rep['fx']['missing']), 0);
echo "\n-- now delete a rate: report must flag, never guess --\n";
$pdo->exec("DELETE FROM tblpapi_fx_rates WHERE date IN ('2026-06-12','2026-06-01','2026-03-02','2026-03-14')");
$e2 = new Report_engine($CI);
$rep2 = $e2->build(array('from'=>'2026-01-01','to'=>'2026-12-31','basis'=>'cash','present_currency'=>1));
check('Missing rates reported', count($rep2['fx']['missing']) > 0 ? 1 : 0, 1);
$flagged = 0; foreach ($rep2['warnings'] as $w) { if (strpos($w,'exchange rate') !== false) $flagged=1; }
check('Warning raised rather than a silent wrong total', $flagged, 1);
check('USD revenue marked incomplete, not guessed',
      byCode($rep2['revenue']['by_currency'],'USD')['converted_complete'] ? 1 : 0, 0);
echo "\n".($fails?"*** $fails FAILED ***":"*** ALL CHECKS PASSED ***"))."\n";
