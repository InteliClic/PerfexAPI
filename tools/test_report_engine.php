<?php
define('BASEPATH', 1);

// ---- Perfex shims -------------------------------------------------------
$GLOBALS['OPTS'] = array(
    'companyname' => 'InteliClic S.A.',
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

$pdo->exec("INSERT INTO tblcurrencies VALUES (1,'USD','\$',1)");
$pdo->exec("INSERT INTO tblclients VALUES (1,'LogiCall Services Inc'),(2,'MJB Media Inc.'),(3,'Cescipholdings LLC'),(4,'Turn Key Marketing')");
$pdo->exec("INSERT INTO tblexpenses_categories VALUES (2,'Contractors'),(8,'Office Equipment / Supplies'),(10,'Bank Fees'),(19,'Bank Interest Received'),(11,'Software')");

// ---- Invoices -----------------------------------------------------------
// LogiCall Jan-Jun 82,430.00 (paid in period) + Jul 16,421.00 (INV-003297, paid AUG 4)
$inv = array(); $pay = array(); $iid = 3000; $pid = 5000; $num = 3200;
function addInv(&$inv,&$iid,&$num,$client,$date,$total,$status=1,$due=null){
    $iid++; $num++;
    $inv[] = array($iid,$num,'INV-',$date,$due===null?$date:$due,$total,1,$status,$client);
    return $iid;
}
function addPay(&$pay,&$pid,$invid,$amt,$date){ $pid++; $pay[] = array($pid,$invid,$amt,$date); }

$lg = array('2026-01-05'=>13500.00,'2026-02-05'=>13500.00,'2026-03-05'=>13500.00,'2026-04-05'=>13976.00,'2026-05-05'=>13977.00,'2026-06-05'=>13977.00);
foreach ($lg as $d=>$amt) { $id = addInv($inv,$iid,$num,1,$d,$amt,2); addPay($pay,$pid,$id,$amt,date('Y-m-d',strtotime($d.' +10 days'))); }
// THE TRAP: dated Jul 1, paid Aug 4. Reads Paid/0.00 today; was open at Jul 31.
$trap = addInv($inv,$iid,$num,1,'2026-07-01',16421.00,2);
addPay($pay,$pid,$trap,16421.00,'2026-08-04');

// MJB Jan-Jul 102,176.50, all paid in period
$mjb = array('2026-01-10'=>14596.64,'2026-02-10'=>14596.64,'2026-03-10'=>14596.64,'2026-04-10'=>14596.64,'2026-05-10'=>14596.64,'2026-06-10'=>14596.65,'2026-07-10'=>14596.65);
foreach ($mjb as $d=>$amt) { $id = addInv($inv,$iid,$num,2,$d,$amt,2); addPay($pay,$pid,$id,$amt,date('Y-m-d',strtotime($d.' +5 days'))); }

// Cescipholdings Jan-Apr 26,500 paid; May/Jun/Jul 6,250 each UNPAID at Jul 31
$ces = array('2026-01-15'=>6625.00,'2026-02-15'=>6625.00,'2026-03-15'=>6625.00,'2026-04-15'=>6625.00);
foreach ($ces as $d=>$amt) { $id = addInv($inv,$iid,$num,3,$d,$amt,2); addPay($pay,$pid,$id,$amt,date('Y-m-d',strtotime($d.' +8 days'))); }
foreach (array('2026-05-15','2026-06-15','2026-07-15') as $d) { addInv($inv,$iid,$num,3,$d,6250.00,1); }

// August invoices — must NOT influence a Jul 31 report
addInv($inv,$iid,$num,1,'2026-08-05',19358.50,1);
addInv($inv,$iid,$num,2,'2026-08-10',10372.00,1);

// Stale 2023 AR — real balance-sheet receivable, must NOT hit the cash reconciliation
addInv($inv,$iid,$num,4,'2023-04-12',11296.20,1);
// A draft and a cancelled invoice dated in the period — must be excluded, and listed
addInv($inv,$iid,$num,2,'2026-06-20',9999.00,6);
addInv($inv,$iid,$num,2,'2026-06-21',8888.00,5);

foreach ($inv as $r) { $pdo->exec("INSERT INTO tblinvoices VALUES ({$r[0]},{$r[1]},'{$r[2]}',1,'{$r[3]}','{$r[4]}',{$r[5]},{$r[6]},{$r[7]},{$r[8]})"); }
foreach ($pay as $r) { $pdo->exec("INSERT INTO tblinvoicepaymentrecords VALUES ({$r[0]},{$r[1]},{$r[2]},'{$r[3]}','1','')"); }

// ---- Expenses: Jan-Jul net 193,520.55 including negative interest --------
$ex = array(
    array(2,150000.00,'2026-03-01','Contractors'),
    array(10,200.00,'2026-04-01','LogiCall wire commissions'),
    array(10,115.00,'2026-05-01','Payoneer transfer fees'),
    array(8,43436.72,'2026-06-01','Office'),
    array(19,-231.17,'2026-07-31','CrediCorp interest Jan-Jul'),
    array(2,23831.89,'2026-08-15','August contractors — must be excluded'),
);
$eid=900; foreach ($ex as $r){ $eid++; $pdo->exec("INSERT INTO tblexpenses VALUES ($eid,{$r[0]},{$r[1]},'{$r[2]}',1,'".$r[3]."','REF$eid',0,0,0,0,0)"); }

// ---- Bank balances + the two documented adjustments ---------------------
$pdo->exec("INSERT INTO tblpapi_bank_balances (date,account,currency,amount,note) VALUES
 ('2025-12-31','CrediCorp 0898',1,118154.32,'audited'),
 ('2025-12-31','Payoneer',1,37536.68,'audited, rounded'),
 ('2026-07-31','CrediCorp 0898',1,153952.60,'running Saldo'),
 ('2026-07-31','Payoneer',1,19720.49,'derived')");
$pdo->exec("INSERT INTO tblpapi_adjustments (date,label,amount,currency,note) VALUES
 ('2026-07-31','Plus Visa card charges incurred not yet paid',440.46,1,'Perfex books charges when incurred; the bank shows the monthly auto-payment. A payable, not an error.'),
 ('2026-07-31','Less rounding on the audited opening balance',-44.32,1,'Audited Dec 31 2025 Payoneer balance is rounded to 37,537; actual 37,492.36. The audited opening is deliberately not restated.')");

require __DIR__ . '/../perfex_crm_api_layer/libraries/Report_engine.php';

$e = new Report_engine($CI);
$rep = $e->build(array('from' => '2026-01-01', 'to' => '2026-07-31', 'basis' => 'accrual'));

// ---- Assertions ---------------------------------------------------------
$fails = 0;
function check($label, $got, $want) {
    global $fails;
    $ok = abs((float)$got - (float)$want) < 0.005;
    if (!$ok) { $fails++; }
    printf("%-58s %14s   %s\n", $label, number_format((float)$got, 2), $ok ? 'OK' : ('FAIL want ' . number_format((float)$want, 2)));
}
echo "\n=== Jan 1 - Jul 31 2026, InteliClic (known-good period) ===\n\n";
check('Facturacion (invoiced in period)', $rep['revenue']['total_converted'], 246277.50);
check('Cuentas pendientes de cobro (arose in period)', $rep['ar']['arose_in_period'], 35171.00);
check('Gastos (net of other income, cash view)', $rep['expenses']['total_converted'] - $rep['other_income']['total_converted'], 193520.55);
check('Saldo segun operacion', $rep['reconciliation']['lines'][5]['amount'], 173276.95);
check('Saldo ajustado', $rep['reconciliation']['expected'], 173673.09);
check('Saldo real en bancos', $rep['reconciliation']['actual'], 173673.09);
check('Diferencia', $rep['reconciliation']['difference'], 0.00);
echo "\n--- as-at-date trap ---\n";
$t = null; foreach ($rep['invoices'] as $r) { if (abs($r['total'] - 16421.00) < 0.01) { $t = $r; } }
printf("%-58s %14s   %s\n", 'LogiCall Jul invoice status TODAY', $t['status_today'], $t['status_today']==='Paid'?'OK':'FAIL');
$openAsAt = ($t['status_asat'] !== 'Paid');
if (!$openAsAt) { $fails++; }
printf("%-58s %14s   %s\n", 'LogiCall Jul invoice status AS AT Jul 31', $t['status_asat'], $openAsAt?'OK (open)':'FAIL');
check('  ...open as at Jul 31 (Perfex shows 0.00)', $t['open_asat'], 16421.00);
echo "\n--- separations that stop the next false alarm ---\n";
check('AR total open at Jul 31 (balance sheet, incl. stale)', $rep['ar']['total_open'], 46467.20);
check('  of which arose before the period (2023 stale)', $rep['ar']['arose_before_period'], 11296.20);
check('Collections against pre-period invoices', $rep['collections']['against_prior_invoices'], 0.00);
check('Collections in period (Perfex payment records)', $rep['collections']['total_converted'], 211106.50);
echo "\n--- P&L ---\n";
check('Revenue (accrual)', $rep['pnl']['revenue'], 246277.50);
check('Other income (interest, lifted out of expenses)', $rep['pnl']['other_income'], 231.17);
check('Expenses', $rep['pnl']['expenses'], 193751.72);
check('Net', $rep['pnl']['net'], 52756.95);
check('Tie: P&L expenses - other income = recon gastos', $rep['pnl']['expenses'] - $rep['pnl']['other_income'], 193520.55);
echo "\n--- nothing after Y leaks in ---\n";
check('Invoices counted in period (21; the 2 Aug ones excluded)', $rep['revenue']['invoice_count'], 21);
check('August expenses excluded', $rep['expenses']['row_count'], 5);
echo "\n--- excluded, listed not dropped ---\n";
check('Draft invoices listed', count($rep['excluded']['draft']), 1);
check('Cancelled invoices listed', count($rep['excluded']['cancelled']), 1);
check('AR aging 90+ bucket (the 2023 stale invoice)', $rep['ar']['aging_all']['90+'], 11296.20);
check('AR aging, period-only, total', array_sum($rep['ar']['aging_period']), 35171.00);
echo "\nWarnings:\n"; foreach ($rep['warnings'] as $w) { echo "  - $w\n"; }

// ---- adjustments must be point-in-time, not accumulating -----------------
echo "\n--- adjustments are the position AT the closing date ---\n";
// Add the August position alongside the July one, exactly as a real user would.
$pdo->exec("INSERT INTO tblpapi_adjustments (date,label,amount,currency,note) VALUES
 ('2026-08-31','Plus Visa card charges incurred not yet paid',413.84,1,''),
 ('2026-08-31','Less rounding on the audited opening balance',-44.32,1,'')");
$pdo->exec("INSERT INTO tblpapi_bank_balances (date,account,currency,amount,note) VALUES
 ('2026-08-31','CrediCorp 0898',1,157317.19,''),('2026-08-31','Payoneer',1,19290.39,'')");
$e3 = new Report_engine($CI);
$julAgain = $e3->build(array('from'=>'2026-01-01','to'=>'2026-07-31','basis'=>'accrual'));
check('Jan-Jul still ties after Aug rows are entered', $julAgain['reconciliation']['difference'], 0.00);
check('  ...and still adjusts by exactly the July position', $julAgain['reconciliation']['expected'], 173673.09);
$augRep = $e3->build(array('from'=>'2026-01-01','to'=>'2026-08-31','basis'=>'accrual'));
$augAdj = 0; foreach ($augRep['reconciliation']['lines'] as $l) { if ($l['kind']==='adjustment') $augAdj += $l['amount']; }
check('Jan-Aug applies only the Aug position (413.84-44.32)', $augAdj, 369.52);
$strayWarn = 0; foreach ($augRep['warnings'] as $w) { if (strpos($w,'not dated at the period end')!==false) $strayWarn=1; }
check('July rows reported as not applied, not silently added', $strayWarn, 1);

echo "\n" . ($fails ? "*** $fails CHECK(S) FAILED ***" : "*** ALL CHECKS PASSED ***") . "\n";
