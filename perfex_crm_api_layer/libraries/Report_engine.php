<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Report engine — as-at-date accurate period reporting.
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE:
 *   For a period ending on date Y, every derived figure is computed from dates.
 *   Nothing is ever read from tblinvoices.status, which reflects the world today.
 *   An invoice is outstanding at Y if its date <= Y and the payments applied on or
 *   before Y fall short of its total. A payment counts by its own date, even against
 *   an earlier invoice. An expense counts on the date incurred. Nothing dated after Y
 *   may influence any figure on the report.
 *
 * Worked example (life-ops #103): INV-003297, LogiCall, 16,421.00, dated 2026-07-01,
 * paid 2026-08-04. It reads "Paid / 0.00" in every Perfex screen today. At Y=2026-07-31
 * this engine reports it open for 16,421.00, because 2026-08-04 > Y.
 *
 * Written for PHP 5.6 through 8.3: no scalar type hints, no ??, no arrow functions.
 */
class Report_engine
{
    /** Perfex invoice status ids (tblinvoices.status). Used ONLY to exclude non-documents. */
    const INV_DRAFT     = 6;
    const INV_CANCELLED = 5;

    /** Money comparisons: anything under half a cent is zero. */
    const EPS = 0.005;

    private $ci;
    private $pfx;
    private $warnings = array();

    public function __construct($ci = null)
    {
        $this->ci  = $ci ? $ci : get_instance();
        $this->pfx = db_prefix();
        $this->migrate();
    }

    // ------------------------------------------------------------------ schema

    /**
     * Module-owned tables. Called on every construct, not just on activation:
     * upgrading the module in place does NOT re-fire the activation hook, so a
     * new table added in a later version would never exist on an upgraded instance.
     */
    public function migrate()
    {
        $p = $this->pfx;
        $this->ci->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}papi_bank_balances` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `date` date NOT NULL,
              `account` varchar(120) NOT NULL,
              `currency` int(11) NOT NULL DEFAULT '0',
              `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
              `note` text,
              PRIMARY KEY (`id`),
              UNIQUE KEY `date_account` (`date`,`account`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
        $this->ci->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}papi_adjustments` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `date` date NOT NULL,
              `label` varchar(200) NOT NULL,
              `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
              `currency` int(11) NOT NULL DEFAULT '0',
              `note` text,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
        $this->ci->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}papi_fx_rates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `date` date NOT NULL,
              `base` varchar(8) NOT NULL,
              `quote` varchar(8) NOT NULL,
              `rate` decimal(18,8) NOT NULL,
              `source` varchar(120) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              UNIQUE KEY `pair_date` (`date`,`base`,`quote`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }

    // ------------------------------------------------------------------ helpers

    private function warn($msg)
    {
        $this->warnings[] = $msg;
    }

    private function esc($s)
    {
        return $this->ci->db->escape($s);
    }

    private function q($sql)
    {
        return $this->ci->db->query($sql)->result_array();
    }

    private function n($v)
    {
        $r = round((float) $v, 2);
        // Kill negative zero: a reconciliation that ties must print 0.00, not -0.00.
        return ($r == 0) ? 0.0 : $r;
    }

    /** Categories whose rows are income booked as negative expenses (see README). */
    public function otherIncomeCategories()
    {
        $raw = (string) get_option('perfex_crm_api_layer_other_income_categories');
        $out = array();
        foreach (explode(',', $raw) as $bit) {
            $bit = trim($bit);
            if ($bit !== '' && ctype_digit($bit)) {
                $out[] = (int) $bit;
            }
        }
        return $out;
    }

    public function currencies()
    {
        $rows = $this->q("SELECT id, name, symbol, isdefault FROM `{$this->pfx}currencies`");
        $map  = array();
        foreach ($rows as $r) {
            $map[(int) $r['id']] = $r;
        }
        return $map;
    }

    private function baseCurrencyId($currencies)
    {
        foreach ($currencies as $id => $c) {
            if ((int) $c['isdefault'] === 1) {
                return $id;
            }
        }
        return 0;
    }

    // ------------------------------------------------------------------ FX

    /**
     * Rate for converting `base` into `quote` on `date`.
     * CRA convention: the rate on the transaction date, or if the market was closed
     * that day (weekend, holiday), the most recent published rate before it.
     * Returns array(rate, rate_date, source) or null — never a guess.
     */
    public function fxRate($base, $quote, $date)
    {
        if ($base === $quote) {
            return array('rate' => 1.0, 'rate_date' => $date, 'source' => 'identity');
        }
        $rows = $this->q(
            "SELECT date, rate, source FROM `{$this->pfx}papi_fx_rates`
             WHERE base = " . $this->esc($base) . " AND quote = " . $this->esc($quote) . "
               AND date <= " . $this->esc($date) . "
             ORDER BY date DESC LIMIT 1"
        );
        if (!count($rows)) {
            return null;
        }
        return array(
            'rate'      => (float) $rows[0]['rate'],
            'rate_date' => $rows[0]['date'],
            'source'    => $rows[0]['source'],
        );
    }

    /**
     * The distinct (currency, date) pairs in a period that need a rate — and only those.
     * Nick, 2026-09-04: "I don't think you wanna bring in a whole list of everything.
     * That's 365 records, and we only get twelve payments a year if that."
     * So rates are loaded sparsely, pinned to real transaction dates.
     */
    public function fxNeeded($from, $to, $presentId)
    {
        $currencies = $this->currencies();
        if (!isset($currencies[$presentId])) { return array(); }
        $quote = $currencies[$presentId]['name'];

        // Invoices by issue date, payments by the date money was received, expenses by date incurred.
        $sql = "SELECT DISTINCT currency, date FROM (
                  SELECT i.currency AS currency, i.date AS date FROM `{$this->pfx}invoices` i
                    WHERE i.date >= " . $this->esc($from) . " AND i.date <= " . $this->esc($to) . "
                      AND i.status NOT IN (" . self::INV_DRAFT . "," . self::INV_CANCELLED . ")
                  UNION ALL
                  SELECT i.currency AS currency, p.date AS date
                    FROM `{$this->pfx}invoicepaymentrecords` p
                    JOIN `{$this->pfx}invoices` i ON i.id = p.invoiceid
                    WHERE p.date >= " . $this->esc($from) . " AND p.date <= " . $this->esc($to) . "
                  UNION ALL
                  SELECT e.currency AS currency, e.date AS date FROM `{$this->pfx}expenses` e
                    WHERE e.date >= " . $this->esc($from) . " AND e.date <= " . $this->esc($to) . "
                ) u ORDER BY date ASC";
        $rows = $this->q($sql);

        $out = array();
        foreach ($rows as $r) {
            $cid = (int) $r['currency'];
            if ($cid === $presentId || !isset($currencies[$cid])) { continue; }
            $base = $currencies[$cid]['name'];
            $exact = $this->q(
                "SELECT rate FROM `{$this->pfx}papi_fx_rates` WHERE base = " . $this->esc($base) .
                " AND quote = " . $this->esc($quote) . " AND date = " . $this->esc($r['date']) . " LIMIT 1"
            );
            $fb = $this->fxRate($base, $quote, $r['date']);
            $out[] = array(
                'base' => $base, 'quote' => $quote, 'date' => $r['date'],
                'has_exact'     => count($exact) > 0,
                'fallback_date' => ($fb === null || count($exact) > 0) ? null : $fb['rate_date'],
                'covered'       => $fb !== null,
            );
        }
        return $out;
    }

    // ------------------------------------------------------------------ the report

    /**
     * @param array $p from, to, present_currency (id|0), basis ('accrual'|'cash')
     */
    public function build($p)
    {
        $from  = $p['from'];
        $to    = $p['to'];
        $basis = isset($p['basis']) && $p['basis'] === 'cash' ? 'cash' : 'accrual';

        $currencies = $this->currencies();
        $baseId     = $this->baseCurrencyId($currencies);
        $presentId  = isset($p['present_currency']) && (int) $p['present_currency'] > 0
            ? (int) $p['present_currency'] : $baseId;
        $presentCode = isset($currencies[$presentId]) ? $currencies[$presentId]['name'] : '';

        $this->warnings = array();
        $notDoc = '(i.status NOT IN (' . self::INV_DRAFT . ',' . self::INV_CANCELLED . '))';

        $out = array(
            'ok'     => true,
            'params' => array(
                'from' => $from, 'to' => $to, 'basis' => $basis,
                'present_currency' => $presentId, 'present_currency_code' => $presentCode,
            ),
            'meta' => array(
                'generated_at'   => date('Y-m-d H:i:s'),
                'company'        => get_option('companyname'),
                'base_currency'  => isset($currencies[$baseId]) ? $currencies[$baseId]['name'] : '',
                'currencies'     => array_values($currencies),
                'engine_version' => '1.2.0',
            ),
        );

        $fxUsed    = array();
        $fxMissing = array();

        // -- convert(): every conversion in the report goes through here, and every
        //    one of them records the rate and the rate's date so the packet can show it.
        $convert = function ($amount, $ccyId, $date) use (
            $currencies, $presentId, $presentCode, &$fxUsed, &$fxMissing
        ) {
            if ($ccyId === $presentId || !isset($currencies[$ccyId])) {
                return array('converted' => $amount, 'rate' => 1.0, 'rate_date' => $date, 'ok' => true);
            }
            $code = $currencies[$ccyId]['name'];
            $r    = $this->fxRate($code, $presentCode, $date);
            if ($r === null) {
                $key = $code . '/' . $presentCode . ' ' . $date;
                $fxMissing[$key] = array('base' => $code, 'quote' => $presentCode, 'date' => $date);
                return array('converted' => null, 'rate' => null, 'rate_date' => null, 'ok' => false);
            }
            $fxUsed[$code . '/' . $presentCode . ' ' . $r['rate_date']] = array(
                'base' => $code, 'quote' => $presentCode, 'txn_date' => $date,
                'rate_date' => $r['rate_date'], 'rate' => $r['rate'], 'source' => $r['source'],
            );
            return array(
                'converted' => round($amount * $r['rate'], 2),
                'rate'      => $r['rate'],
                'rate_date' => $r['rate_date'],
                'ok'        => true,
            );
        };

        // ============================================================ 1. INVOICES
        // Every invoice dated on or before Y, with the amount applied to it on or
        // before Y. One query; the as-at-date arithmetic is in the join, not in a
        // status column. LEFT JOIN so an invoice with no payments yet still appears.
        $invoices = $this->q(
            "SELECT i.id, i.number, i.prefix, i.number_format, i.date, i.duedate, i.total,
                    i.currency, i.status AS status_today, i.clientid,
                    c.company,
                    COALESCE(pay.paid_asat, 0) AS paid_asat
             FROM `{$this->pfx}invoices` i
             LEFT JOIN `{$this->pfx}clients` c ON c.userid = i.clientid
             LEFT JOIN (
                SELECT invoiceid, SUM(amount) AS paid_asat
                FROM `{$this->pfx}invoicepaymentrecords`
                WHERE date <= " . $this->esc($to) . "
                GROUP BY invoiceid
             ) pay ON pay.invoiceid = i.id
             WHERE i.date <= " . $this->esc($to) . "
             ORDER BY i.date ASC, i.id ASC"
        );

        $inPeriod = array();      // issued in the window — this is facturación
        $priorOpen = array();     // dated before the window and still open at Y
        $excludedDraft = array();
        $excludedCancelled = array();
        $arDetail = array();

        foreach ($invoices as $r) {
            $ccy   = (int) $r['currency'];
            $total = (float) $r['total'];
            $paid  = (float) $r['paid_asat'];
            $open  = round($total - $paid, 2);
            $st    = (int) $r['status_today'];
            $isIn  = ($r['date'] >= $from && $r['date'] <= $to);

            $row = array(
                'id'        => (int) $r['id'],
                'number'    => $this->invoiceNumber($r),
                'date'      => $r['date'],
                'duedate'   => $r['duedate'],
                'customer'  => $r['company'],
                'currency'  => $ccy,
                'currency_code' => isset($currencies[$ccy]) ? $currencies[$ccy]['name'] : '',
                'total'     => $this->n($total),
                'paid_asat' => $this->n($paid),
                'open_asat' => $this->n($open),
                // Status DERIVED from dates. Compare with status_today to see the trap.
                'status_asat'  => $this->statusAsAt($total, $paid, $r['duedate'], $to),
                'status_today' => $this->statusName($st),
            );

            if ($st === self::INV_DRAFT) {
                if ($isIn) { $excludedDraft[] = $row; }
                continue;
            }
            if ($st === self::INV_CANCELLED) {
                if ($isIn) { $excludedCancelled[] = $row; }
                continue;
            }

            if ($isIn) {
                $c = $convert($total, $ccy, $r['date']);
                $row['converted'] = $c['converted'];
                $row['fx_rate']   = $c['rate'];
                $row['fx_date']   = $c['rate_date'];
                $inPeriod[] = $row;
            }
            if ($open > self::EPS) {
                $row['days_outstanding'] = $this->daysBetween($r['date'], $to);
                $row['arose_in_period']  = $isIn;
                $arDetail[] = $row;
                if (!$isIn) { $priorOpen[] = $row; }
            }
        }

        // Cancelled invoices are a genuine blind spot and the packet says so out loud:
        // Perfex records no cancellation date, so an invoice cancelled AFTER Y cannot be
        // told apart from one cancelled before it. They are listed, never silently dropped.
        if (count($excludedCancelled)) {
            $this->warn(count($excludedCancelled) . ' cancelled invoice(s) dated in the period are excluded from revenue. '
                . 'Perfex stores no cancellation date, so if any were cancelled after ' . $to . ' they were live at the period end. Listed under "Excluded".');
        }
        if (count($excludedDraft)) {
            $this->warn(count($excludedDraft) . ' draft invoice(s) dated in the period are excluded from revenue (a draft is not an issued document).');
        }

        // ============================================================ 2. REVENUE
        $revByCcy = array(); $revByCustomer = array(); $revByMonth = array();
        foreach ($inPeriod as $r) {
            $ccy = $r['currency'];
            if (!isset($revByCcy[$ccy])) {
                $revByCcy[$ccy] = array('currency' => $ccy, 'currency_code' => $r['currency_code'],
                    'invoiced' => 0.0, 'converted' => 0.0, 'converted_complete' => true, 'count' => 0);
            }
            $revByCcy[$ccy]['invoiced'] += $r['total'];
            $revByCcy[$ccy]['count']++;
            if ($r['converted'] === null) { $revByCcy[$ccy]['converted_complete'] = false; }
            else { $revByCcy[$ccy]['converted'] += $r['converted']; }

            $k = $r['customer'] === null ? '(no customer)' : $r['customer'];
            if (!isset($revByCustomer[$k])) {
                $revByCustomer[$k] = array('customer' => $k, 'by_currency' => array(), 'converted' => 0.0, 'count' => 0);
            }
            if (!isset($revByCustomer[$k]['by_currency'][$r['currency_code']])) {
                $revByCustomer[$k]['by_currency'][$r['currency_code']] = 0.0;
            }
            $revByCustomer[$k]['by_currency'][$r['currency_code']] += $r['total'];
            $revByCustomer[$k]['count']++;
            if ($r['converted'] !== null) { $revByCustomer[$k]['converted'] += $r['converted']; }

            $m = substr($r['date'], 0, 7);
            if (!isset($revByMonth[$m])) { $revByMonth[$m] = array('month' => $m, 'invoiced_converted' => 0.0); }
            if ($r['converted'] !== null) { $revByMonth[$m]['invoiced_converted'] += $r['converted']; }
        }
        foreach ($revByCcy as $k => $v) {
            $revByCcy[$k]['invoiced']  = $this->n($v['invoiced']);
            $revByCcy[$k]['converted'] = $this->n($v['converted']);
        }
        ksort($revByMonth);

        // ============================================================ 3. COLLECTIONS
        // Payments by PAYMENT date. A payment in the window against an invoice from
        // before the window still counts here — and it is exactly the line the
        // hand-built cash proof has no room for.
        $payments = $this->q(
            "SELECT p.id, p.invoiceid, p.amount, p.date, p.paymentmode, p.transactionid,
                    i.date AS invoice_date, i.currency, i.status AS status_today, c.company
             FROM `{$this->pfx}invoicepaymentrecords` p
             JOIN `{$this->pfx}invoices` i ON i.id = p.invoiceid
             LEFT JOIN `{$this->pfx}clients` c ON c.userid = i.clientid
             WHERE p.date >= " . $this->esc($from) . " AND p.date <= " . $this->esc($to) . "
             ORDER BY p.date ASC"
        );
        $collCurrent = 0.0; $collPrior = 0.0; $collByCcy = array(); $collByMonth = array();
        $collPriorDetail = array();
        foreach ($payments as $r) {
            $amt = (float) $r['amount'];
            $ccy = (int) $r['currency'];
            $code = isset($currencies[$ccy]) ? $currencies[$ccy]['name'] : '';
            if (!isset($collByCcy[$ccy])) {
                $collByCcy[$ccy] = array('currency' => $ccy, 'currency_code' => $code,
                    'collected' => 0.0, 'converted' => 0.0, 'converted_complete' => true);
            }
            $collByCcy[$ccy]['collected'] += $amt;
            // Converted at the rate on the day the money was RECEIVED — Nick, 2026-09-04.
            $c = $convert($amt, $ccy, $r['date']);
            if ($c['converted'] === null) { $collByCcy[$ccy]['converted_complete'] = false; }
            else { $collByCcy[$ccy]['converted'] += $c['converted']; }

            $m = substr($r['date'], 0, 7);
            if (!isset($collByMonth[$m])) { $collByMonth[$m] = array('month' => $m, 'collected_converted' => 0.0); }
            if ($c['converted'] !== null) { $collByMonth[$m]['collected_converted'] += $c['converted']; }

            if ($r['invoice_date'] < $from) {
                $collPrior += $amt;
                $collPriorDetail[] = array(
                    'invoice_id' => (int) $r['invoiceid'], 'invoice_date' => $r['invoice_date'],
                    'customer' => $r['company'], 'payment_date' => $r['date'],
                    'amount' => $this->n($amt), 'currency_code' => $code,
                );
            } else {
                $collCurrent += $amt;
            }
        }
        foreach ($collByCcy as $k => $v) {
            $collByCcy[$k]['collected'] = $this->n($v['collected']);
            $collByCcy[$k]['converted'] = $this->n($v['converted']);
        }
        ksort($collByMonth);

        // ============================================================ 4. EXPENSES
        $otherIncomeCats = $this->otherIncomeCategories();
        $expenses = $this->q(
            "SELECT e.id, e.category, e.amount, e.date, e.currency, e.expense_name,
                    e.reference_no, e.clientid, e.billable, e.invoiceid, e.tax, e.tax2,
                    cat.name AS category_name
             FROM `{$this->pfx}expenses` e
             LEFT JOIN `{$this->pfx}expenses_categories` cat ON cat.id = e.category
             WHERE e.date >= " . $this->esc($from) . " AND e.date <= " . $this->esc($to) . "
             ORDER BY e.date ASC"
        );

        // Every category on the instance, so a category with no rows in the period
        // shows as 0.00 rather than vanishing (#104: "show it at zero, so the reader
        // can see nothing is missing").
        $allCats = $this->q("SELECT id, name FROM `{$this->pfx}expenses_categories` ORDER BY name ASC");
        $expByCat = array(); $incByCat = array();
        foreach ($allCats as $c) {
            $slot = array('category' => (int) $c['id'], 'category_name' => $c['name'],
                'amount' => 0.0, 'converted' => 0.0, 'count' => 0, 'by_currency' => array());
            if (in_array((int) $c['id'], $otherIncomeCats)) { $incByCat[(int) $c['id']] = $slot; }
            else { $expByCat[(int) $c['id']] = $slot; }
        }

        $expByMonth = array(); $expByCcy = array(); $taxSeen = false; $billableSeen = 0; $bucket = null;
        foreach ($expenses as $r) {
            $cat  = (int) $r['category'];
            $amt  = (float) $r['amount'];
            $ccy  = (int) $r['currency'];
            $code = isset($currencies[$ccy]) ? $currencies[$ccy]['name'] : '';
            if ((int) $r['tax'] > 0 || (int) $r['tax2'] > 0) { $taxSeen = true; }
            if ((int) $r['billable'] === 1) { $billableSeen++; }

            $isIncome = in_array($cat, $otherIncomeCats);
            // Bind $bucket to whichever map this category belongs in, by reference.
            unset($bucket);
            if ($isIncome) { $bucket = &$incByCat; } else { $bucket = &$expByCat; }
            if (!isset($bucket[$cat])) {
                $bucket[$cat] = array('category' => $cat,
                    'category_name' => $r['category_name'] === null ? '(uncategorised)' : $r['category_name'],
                    'amount' => 0.0, 'converted' => 0.0, 'count' => 0, 'by_currency' => array());
            }
            $bucket[$cat]['amount'] += $amt;
            $bucket[$cat]['count']++;
            if (!isset($bucket[$cat]['by_currency'][$code])) { $bucket[$cat]['by_currency'][$code] = 0.0; }
            $bucket[$cat]['by_currency'][$code] += $amt;
            $c = $convert($amt, $ccy, $r['date']);
            if ($c['converted'] !== null) { $bucket[$cat]['converted'] += $c['converted']; }
            unset($bucket);

            if (!$isIncome) {
                $m = substr($r['date'], 0, 7);
                if (!isset($expByMonth[$m])) { $expByMonth[$m] = array('month' => $m, 'expenses_converted' => 0.0); }
                if ($c['converted'] !== null) { $expByMonth[$m]['expenses_converted'] += $c['converted']; }
                if (!isset($expByCcy[$ccy])) {
                    $expByCcy[$ccy] = array('currency' => $ccy, 'currency_code' => $code, 'amount' => 0.0, 'converted' => 0.0);
                }
                $expByCcy[$ccy]['amount'] += $amt;
                if ($c['converted'] !== null) { $expByCcy[$ccy]['converted'] += $c['converted']; }
            }
        }
        ksort($expByMonth);
        if ($taxSeen) {
            $this->warn('Some expense rows carry a tax id. This report sums the expense amount only, which is how the Jan-Jul 2026 gastos figure of 193,520.55 was derived. Tax-inclusive totals would be higher.');
        }
        if ($billableSeen) {
            $this->warn($billableSeen . ' expense row(s) are marked billable. If any were re-invoiced they are counted twice — once as an expense and once in revenue.');
        }

        $expTotalNative = 0.0; $expTotalConv = 0.0;
        foreach ($expByCat as $k => $v) {
            $expByCat[$k]['amount'] = $this->n($v['amount']);
            $expByCat[$k]['converted'] = $this->n($v['converted']);
            $expTotalNative += $v['amount']; $expTotalConv += $v['converted'];
        }
        $incTotalNative = 0.0; $incTotalConv = 0.0;
        foreach ($incByCat as $k => $v) {
            // Booked as negative expenses; presented as positive income.
            $incByCat[$k]['amount'] = $this->n(-$v['amount']);
            $incByCat[$k]['converted'] = $this->n(-$v['converted']);
            $incTotalNative += -$v['amount']; $incTotalConv += -$v['converted'];
        }
        usort($expByCat, array($this, 'cmpCategory'));
        usort($incByCat, array($this, 'cmpCategory'));

        // ============================================================ 5. AR AGING at Y
        $buckets = array('0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0);
        $bucketsPeriod = $buckets; $arTotal = 0.0; $arPeriod = 0.0; $arPrior = 0.0;
        foreach ($arDetail as $r) {
            $d = $r['days_outstanding'];
            $b = $d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'));
            $buckets[$b] += $r['open_asat'];
            $arTotal += $r['open_asat'];
            if ($r['arose_in_period']) { $bucketsPeriod[$b] += $r['open_asat']; $arPeriod += $r['open_asat']; }
            else { $arPrior += $r['open_asat']; }
        }
        foreach ($buckets as $k => $v) { $buckets[$k] = $this->n($v); }
        foreach ($bucketsPeriod as $k => $v) { $bucketsPeriod[$k] = $this->n($v); }

        // ============================================================ 6. P&L
        $revConv = 0.0; $revComplete = true;
        foreach ($revByCcy as $v) {
            $revConv += $v['converted'];
            if (!$v['converted_complete']) { $revComplete = false; }
        }
        $collConv = 0.0;
        foreach ($collByCcy as $v) { $collConv += $v['converted']; }

        $topLine = $basis === 'cash' ? $collConv : $revConv;
        $pnl = array(
            'basis'        => $basis,
            'revenue'      => $this->n($topLine),
            'revenue_label' => $basis === 'cash' ? 'Collections in period (cash basis)' : 'Invoiced in period (accrual basis)',
            'other_income' => $this->n($incTotalConv),
            'expenses'     => $this->n($expTotalConv),
            'net'          => $this->n($topLine + $incTotalConv - $expTotalConv),
        );
        foreach ($expByCat as $k => $v) {
            $expByCat[$k]['pct_of_revenue'] = $topLine > self::EPS ? round(100 * $v['converted'] / $topLine, 1) : null;
        }

        // ============================================================ 7. RECONCILIATION
        $recon = $this->reconcile(array(
            'from' => $from, 'to' => $to,
            'billings'        => $revConv,
            'ar_period'       => $arPeriod,
            'collections_prior' => $collPrior,
            'expenses_net'    => $expTotalConv - $incTotalConv, // cash view: interest nets against spend
            'present_currency' => $presentId,
        ));

        // ============================================================ assemble
        $out['revenue'] = array(
            'by_currency' => $this->sortCurrencies(array_values($revByCcy), $baseId),
            'by_customer' => array_values($revByCustomer),
            'by_month'    => array_values($revByMonth),
            'total_converted' => $this->n($revConv),
            'converted_complete' => $revComplete,
            'invoice_count' => count($inPeriod),
        );
        $out['other_income'] = array(
            'by_category' => array_values($incByCat),
            'total_converted' => $this->n($incTotalConv),
            'note' => 'Perfex has no concept of income that is not an invoice. These rows are booked as negative expenses in flagged categories and are presented here as income. The underlying rows are unchanged.',
        );
        $out['expenses'] = array(
            'by_category' => array_values($expByCat),
            'by_month'    => array_values($expByMonth),
            'by_currency' => $this->sortCurrencies(array_values($expByCcy), $baseId),
            'total_converted' => $this->n($expTotalConv),
            'row_count' => count($expenses),
        );
        $out['collections'] = array(
            'by_currency' => $this->sortCurrencies(array_values($collByCcy), $baseId),
            'by_month'    => array_values($collByMonth),
            'against_period_invoices' => $this->n($collCurrent),
            'against_prior_invoices'  => $this->n($collPrior),
            'prior_detail' => $collPriorDetail,
            'total_converted' => $this->n($collConv),
        );
        $out['invoices'] = $inPeriod;
        $out['ar'] = array(
            'as_at' => $to,
            'total_open'    => $this->n($arTotal),
            'arose_in_period' => $this->n($arPeriod),
            'arose_before_period' => $this->n($arPrior),
            'aging_all'     => $buckets,
            'aging_period'  => $bucketsPeriod,
            'detail'        => $arDetail,
            'prior_detail'  => $priorOpen,
            'note' => 'Two different numbers, both correct. "Arose in period" is what the cash reconciliation subtracts, because the opening balance already reflects that pre-period invoices went uncollected. "Total open" is the real balance-sheet receivable.',
        );
        $out['pnl'] = $pnl;
        $out['reconciliation'] = $recon;
        $out['excluded'] = array(
            'draft' => $excludedDraft,
            'cancelled' => $excludedCancelled,
        );
        $out['fx'] = array(
            'present_currency_code' => $presentCode,
            'rates_used' => array_values($fxUsed),
            'missing'    => array_values($fxMissing),
            'note' => 'Foreign amounts are converted at the rate on the transaction date - for a collection, the day the money was received. Where the market was closed that day the most recent prior published rate is used and shown as the rate date.',
        );
        if (count($fxMissing)) {
            $this->warn(count($fxMissing) . ' exchange rate(s) are missing, so some converted totals are incomplete. Load them under Setup > API Layer > Reports, or the report will understate.');
        }
        $out['warnings'] = $this->warnings;
        return $out;
    }

    // ------------------------------------------------------------------ reconciliation

    private function reconcile($a)
    {
        $from = $a['from']; $to = $a['to']; $ccy = $a['present_currency'];

        // Opening balance: the recorded close on the last date strictly before the period.
        $open = $this->balancesAsOf($this->dayBefore($from), $ccy);
        $close = $this->balancesAsOf($to, $ccy);

        // Adjustments are a POINT-IN-TIME statement of the position at the period end
        // (Visa charges outstanding at Y, the opening-balance rounding), not amounts
        // that accrue across the window. So only rows dated exactly on $to are applied.
        // Anything else inside the period is reported but never silently added, or a
        // Jan-Aug run would apply both the July and the August position and tie to nothing.
        $adj = $this->q(
            "SELECT date, label, amount, note FROM `{$this->pfx}papi_adjustments`
             WHERE date = " . $this->esc($to) . " ORDER BY label ASC"
        );
        $strayAdj = $this->q(
            "SELECT date, label, amount FROM `{$this->pfx}papi_adjustments`
             WHERE date >= " . $this->esc($from) . " AND date < " . $this->esc($to) . "
             ORDER BY date ASC"
        );
        if (count($strayAdj)) {
            $names = array();
            foreach ($strayAdj as $x) { $names[] = $x['label'] . ' (' . $x['date'] . ')'; }
            $this->warn(count($strayAdj) . ' adjustment row(s) fall inside the period but are not dated at the period end, so they were NOT applied: '
                . implode('; ', $names) . '. Adjustments state the position as at the closing date - re-date them to ' . $to . ' if they belong in this report.');
        }

        $lines = array();
        $lines[] = array('label' => 'Opening bank balance at ' . $this->dayBefore($from), 'amount' => $open['total'], 'kind' => 'opening', 'detail' => $open['accounts']);
        $lines[] = array('label' => 'Plus billings in period', 'amount' => $this->n($a['billings']), 'kind' => 'add');
        $lines[] = array('label' => 'Less receivables at ' . $to . ' arising from those billings', 'amount' => $this->n(-$a['ar_period']), 'kind' => 'less');
        // This line does not exist on the hand-built sheet. It is 0.00 when nothing
        // pre-period was collected in the window, and the packet shows the 0.00 on
        // purpose — a silently absent line is how a reconciliation ties by luck.
        $lines[] = array('label' => 'Plus collections in period against pre-period invoices', 'amount' => $this->n($a['collections_prior']), 'kind' => 'add');
        $lines[] = array('label' => 'Less expenses in period (net of other income)', 'amount' => $this->n(-$a['expenses_net']), 'kind' => 'less');

        $expected = $open['total'] + $a['billings'] - $a['ar_period'] + $a['collections_prior'] - $a['expenses_net'];
        $subtotal = $this->n($expected);
        $lines[] = array('label' => 'Balance per operations', 'amount' => $subtotal, 'kind' => 'subtotal');

        foreach ($adj as $r) {
            $lines[] = array('label' => $r['label'], 'amount' => $this->n($r['amount']), 'kind' => 'adjustment', 'note' => $r['note'], 'date' => $r['date']);
            $expected += (float) $r['amount'];
        }
        $lines[] = array('label' => 'Adjusted balance', 'amount' => $this->n($expected), 'kind' => 'total');
        $lines[] = array('label' => 'Actual bank balance at ' . $to, 'amount' => $close['total'], 'kind' => 'actual', 'detail' => $close['accounts']);

        $diff = $this->n($expected - $close['total']);
        $ready = count($open['accounts']) > 0 && count($close['accounts']) > 0;

        return array(
            'lines'      => $lines,
            'expected'   => $this->n($expected),
            'actual'     => $close['total'],
            'difference' => $diff,
            'ties'       => $ready && abs($diff) < 0.005,
            'ready'      => $ready,
            'message'    => $ready
                ? (abs($diff) < 0.005 ? 'Ties to the cent.' : 'Does not tie. Investigate before sending.')
                : 'Enter the opening and closing bank balances for this period to complete the reconciliation.',
        );
    }

    private function balancesAsOf($date, $ccy)
    {
        // The recorded balance for each account on the latest date at or before $date.
        $rows = $this->q(
            "SELECT b.account, b.date, b.amount, b.currency, b.note
             FROM `{$this->pfx}papi_bank_balances` b
             JOIN (
               SELECT account, MAX(date) AS d FROM `{$this->pfx}papi_bank_balances`
               WHERE date <= " . $this->esc($date) . " GROUP BY account
             ) m ON m.account = b.account AND m.d = b.date
             ORDER BY b.account ASC"
        );
        $total = 0.0; $acc = array();
        foreach ($rows as $r) {
            // Only balances recorded ON the date itself count as that date's close.
            $onDate = ($r['date'] === $date);
            $acc[] = array('account' => $r['account'], 'date' => $r['date'],
                'amount' => $this->n($r['amount']), 'stale' => !$onDate);
            $total += (float) $r['amount'];
        }
        return array('total' => $this->n($total), 'accounts' => $acc);
    }

    // ------------------------------------------------------------------ small helpers

    private function statusAsAt($total, $paid, $duedate, $to)
    {
        if ($paid >= $total - self::EPS && $total > self::EPS) { return 'Paid'; }
        if ($paid > self::EPS) { return 'Partially paid'; }
        if ($duedate && $duedate !== '0000-00-00' && $duedate < $to) { return 'Overdue'; }
        return 'Unpaid';
    }

    private function statusName($s)
    {
        $m = array(1 => 'Unpaid', 2 => 'Paid', 3 => 'Partially paid', 4 => 'Overdue', 5 => 'Cancelled', 6 => 'Draft');
        return isset($m[$s]) ? $m[$s] : ('status ' . $s);
    }

    private function invoiceNumber($r)
    {
        $prefix = isset($r['prefix']) ? $r['prefix'] : '';
        $num    = isset($r['number']) ? $r['number'] : '';
        if (function_exists('format_invoice_number')) {
            $f = format_invoice_number($r['id']);
            if ($f) { return $f; }
        }
        return $prefix . str_pad($num, 6, '0', STR_PAD_LEFT);
    }

    private function daysBetween($a, $b)
    {
        $t1 = strtotime($a); $t2 = strtotime($b);
        if (!$t1 || !$t2) { return 0; }
        return (int) floor(($t2 - $t1) / 86400);
    }

    private function dayBefore($ymd)
    {
        return date('Y-m-d', strtotime($ymd . ' -1 day'));
    }

    /**
     * Stable currency ordering for every stacked section: the base currency first,
     * then the rest alphabetically. An accountant packet must not reorder its own
     * columns between runs just because rows were entered in a different order.
     */
    private function sortCurrencies($rows, $baseId)
    {
        usort($rows, function ($a, $b) use ($baseId) {
            $ab = ((int) $a['currency'] === $baseId) ? 0 : 1;
            $bb = ((int) $b['currency'] === $baseId) ? 0 : 1;
            if ($ab !== $bb) { return $ab - $bb; }
            return strcasecmp($a['currency_code'], $b['currency_code']);
        });
        return $rows;
    }

    public function cmpCategory($a, $b)
    {
        return strcasecmp($a['category_name'], $b['category_name']);
    }
}
