<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public (token-authenticated) JSON API.
 * URL: /perfex_crm_api_layer/api/<resource>[/<sub>]
 *
 * Auth: header X-Api-Token (or Authtoken) must equal option perfex_crm_api_layer_token.
 * Reads: GET. Writes: PUT with a JSON body (POST is intercepted by Perfex's CSRF filter).
 * Dates in and out: YYYY-MM-DD. Amounts as plain numbers.
 *
 * Written for old PHP (5.6+ safe): no scalar type hints, no ??, no short closures.
 */
class Api extends App_Controller
{
    private $staffId = 1;

    public function __construct()
    {
        parent::__construct();
        $this->auth();
        $this->staffId = (int) get_option('perfex_crm_api_layer_staff_id');
        if ($this->staffId < 1) {
            $this->staffId = 1;
        }
        // Make Perfex helpers that read the "current staff" behave (addedfrom, activity log).
        $this->session->set_userdata(array('staff_user_id' => $this->staffId, 'staff_logged_in' => true));
    }

    // ---------------------------------------------------------------- plumbing

    private function auth()
    {
        $expected = (string) get_option('perfex_crm_api_layer_token');
        $given    = '';
        if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
            $given = $_SERVER['HTTP_X_API_TOKEN'];
        } elseif (isset($_SERVER['HTTP_AUTHTOKEN'])) {
            $given = $_SERVER['HTTP_AUTHTOKEN'];
        }
        if ($expected === '' || $given === '' || !$this->constantTimeEquals($expected, $given)) {
            $this->out(array('error' => 'unauthorized'), 401);
        }
    }

    private function constantTimeEquals($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $r = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $r |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $r === 0;
    }

    private function out($data, $status = 200)
    {
        // Discard anything Perfex may have buffered (notices, etc.) so the body is clean JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('http_response_code')) {
            http_response_code($status);
        } else {
            header('HTTP/1.1 ' . $status);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    private function body()
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return array();
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            $this->out(array('error' => 'invalid_json'), 400);
        }
        return $j;
    }

    private function method()
    {
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    private function requireWrite()
    {
        $m = $this->method();
        if ($m !== 'PUT' && $m !== 'POST' && $m !== 'PATCH' && $m !== 'DELETE') {
            $this->out(array('error' => 'method_not_allowed', 'hint' => 'use PUT with a JSON body'), 405);
        }
    }

    private function need($in, $keys)
    {
        $missing = array();
        foreach ($keys as $k) {
            if (!isset($in[$k]) || $in[$k] === '') {
                $missing[] = $k;
            }
        }
        if (count($missing)) {
            $this->out(array('error' => 'missing_fields', 'fields' => $missing), 400);
        }
    }

    /** YYYY-MM-DD -> Perfex's configured display format (what the models expect in to_sql_date). */
    private function uiDate($ymd)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            $this->out(array('error' => 'bad_date', 'value' => $ymd, 'hint' => 'YYYY-MM-DD'), 400);
        }
        return _d($ymd);
    }

    private function dateRange(&$q, $col)
    {
        $from = $this->input->get('from');
        $to   = $this->input->get('to');
        if ($from) {
            $this->db->where($col . ' >=', $from);
        }
        if ($to) {
            $this->db->where($col . ' <=', $to);
        }
    }

    private function paging()
    {
        $limit  = (int) $this->input->get('limit');
        $offset = (int) $this->input->get('offset');
        if ($limit < 1 || $limit > 2000) {
            $limit = 500;
        }
        $this->db->limit($limit, $offset);
    }

    // ---------------------------------------------------------------- routing

    public function index($resource = '', $sub = '')
    {
        $this->_dispatch($resource, $sub);
    }

    public function _remap($method, $params = array())
    {
        // /api/<resource>/<sub> — everything is routed here regardless of method name
        $resource = $method;
        $sub      = isset($params[0]) ? $params[0] : '';
        if ($method === 'index') {
            $resource = isset($params[0]) ? $params[0] : '';
            $sub      = isset($params[1]) ? $params[1] : '';
        }
        $this->_dispatch($resource, $sub);
    }

    private function _dispatch($resource, $sub)
    {
        switch ($resource) {
            case 'meta':               return $this->meta();
            case 'currencies':         return $this->lookup('currencies');
            case 'expense_categories': return $this->lookup('expenses_categories');
            case 'payment_modes':      return $this->lookup('payment_modes');
            case 'taxes':              return $this->lookup('taxes');
            case 'customers':          return $this->customers();
            case 'expenses':
                if ($sub === 'batch') { return $this->expensesBatch(); }
                if ($sub !== '' && ctype_digit((string) $sub)) { return $this->expenseOne((int) $sub); }
                return $this->expenses();
            case 'invoices':           return $this->invoices($sub);
            case 'payments':           return $this->payments();
            default:
                $this->out(array('error' => 'unknown_resource', 'resource' => $resource), 404);
        }
    }

    // ---------------------------------------------------------------- endpoints

    private function meta()
    {
        $this->out(array(
            'ok'             => true,
            'perfex_version' => $this->app->get_current_db_version(),
            'php_version'    => PHP_VERSION,
            'date_format'    => get_option('dateformat'),
            'base_currency'  => get_base_currency(),
            'staff_id'       => $this->staffId,
            'db_prefix'      => db_prefix(),
        ));
    }

    private function lookup($table)
    {
        $rows = $this->db->get(db_prefix() . $table)->result_array();
        $this->out(array('data' => $rows));
    }

    private function customers()
    {
        $this->db->select('userid, company, active, default_currency, datecreated');
        $q = $this->input->get('q');
        if ($q) {
            $this->db->like('company', $q);
        }
        $rows = $this->db->get(db_prefix() . 'clients')->result_array();
        $this->out(array('data' => $rows));
    }

    // --- expenses

    private function expenses()
    {
        if ($this->method() === 'GET') {
            $this->dateRange($q, 'date');
            $cat = $this->input->get('category');
            if ($cat) {
                $this->db->where('category', (int) $cat);
            }
            $this->db->order_by('date', 'asc');
            $this->paging();
            $rows = $this->db->get(db_prefix() . 'expenses')->result_array();
            $this->out(array('data' => $rows));
        }
        $this->requireWrite();
        $in = $this->body();
        $id = $this->createExpense($in);
        $this->out(array('ok' => true, 'id' => $id), 201);
    }

    private function expensesBatch()
    {
        $this->requireWrite();
        $in = $this->body();
        if (!isset($in['items']) || !is_array($in['items'])) {
            $this->out(array('error' => 'missing_fields', 'fields' => array('items')), 400);
        }
        $results = array();
        foreach ($in['items'] as $i => $item) {
            $results[] = array('index' => $i, 'id' => $this->createExpense($item, false));
        }
        $this->out(array('ok' => true, 'created' => count($results), 'results' => $results), 201);
    }

    /** Validates and inserts one expense through Expenses_model::add(). Returns the new id. */
    private function createExpense($in, $strict = true)
    {
        $this->need($in, array('category', 'amount', 'date', 'currency'));
        $this->load->model('expenses_model');

        $data = array(
            'category'     => (int) $in['category'],
            'amount'       => (float) $in['amount'],
            'date'         => $this->uiDate($in['date']),
            'currency'     => (int) $in['currency'],
            'expense_name' => isset($in['expense_name']) ? $in['expense_name'] : '',
            'note'         => isset($in['note']) ? $in['note'] : '',
            'reference_no' => isset($in['reference_no']) ? $in['reference_no'] : '',
            'paymentmode'  => isset($in['paymentmode']) ? $in['paymentmode'] : '',
            'clientid'     => isset($in['clientid']) ? (int) $in['clientid'] : 0,
            'project_id'   => isset($in['project_id']) ? (int) $in['project_id'] : 0,
            'tax'          => isset($in['tax']) ? (int) $in['tax'] : 0,
            'tax2'         => isset($in['tax2']) ? (int) $in['tax2'] : 0,
        );
        // Expenses_model treats the mere presence of 'billable' as true — only send it when set.
        if (!empty($in['billable'])) { $data['billable'] = 1; }
        // Optional idempotency: refuse a duplicate reference_no on the same date/amount.
        if ($data['reference_no'] !== '') {
            $dup = $this->db->where('reference_no', $data['reference_no'])
                ->where('amount', $data['amount'])
                ->get(db_prefix() . 'expenses')->row();
            if ($dup) {
                if ($strict) {
                    $this->out(array('error' => 'duplicate', 'existing_id' => $dup->id), 409);
                }
                return (int) $dup->id; // batch: skip silently, report existing id
            }
        }
        $id = $this->expenses_model->add($data);
        if (!$id) {
            $this->out(array('error' => 'insert_failed', 'db' => $this->db->error()), 500);
        }
        // Force addedfrom to the configured staff (the model reads the session; belt and braces).
        $this->db->where('id', $id)->update(db_prefix() . 'expenses', array('addedfrom' => $this->staffId));
        return (int) $id;
    }

    /** GET /expenses/<id>, PATCH|PUT /expenses/<id> (partial update), DELETE /expenses/<id>. */
    private function expenseOne($id)
    {
        $this->load->model('expenses_model');
        $m = $this->method();
        if ($m === 'GET') {
            $row = $this->db->where('id', $id)->get(db_prefix() . 'expenses')->row_array();
            if (!$row) { $this->out(array('error' => 'not_found', 'id' => $id), 404); }
            $this->out(array('data' => $row));
        }
        if ($m === 'DELETE') {
            $row = $this->db->where('id', $id)->get(db_prefix() . 'expenses')->row();
            if (!$row) { $this->out(array('error' => 'not_found', 'id' => $id), 404); }
            if (!empty($row->invoiceid)) {
                $this->out(array('error' => 'billed', 'hint' => 'expense is attached to invoice ' . $row->invoiceid . '; detach it in Perfex first'), 409);
            }
            $ok = $this->expenses_model->delete($id);
            $this->out(array('ok' => (bool) $ok, 'id' => $id));
        }
        if ($m === 'PATCH' || $m === 'PUT' || $m === 'POST') {
            $in  = $this->body();
            $row = $this->db->where('id', $id)->get(db_prefix() . 'expenses')->row_array();
            if (!$row) { $this->out(array('error' => 'not_found', 'id' => $id), 404); }
            // Whitelist of editable columns; unknown keys are ignored.
            $allowed = array('category', 'amount', 'date', 'currency', 'expense_name', 'note', 'reference_no',
                             'paymentmode', 'clientid', 'project_id', 'billable', 'tax', 'tax2');
            $data = array();
            foreach ($allowed as $k) {
                if (array_key_exists($k, $in)) { $data[$k] = $in[$k]; }
            }
            if (!count($data)) { $this->out(array('error' => 'nothing_to_update', 'allowed' => $allowed), 400); }
            if (isset($data['date'])) { $data['date'] = $this->uiDate($data['date']); }
            foreach (array('category', 'currency', 'clientid', 'project_id', 'tax', 'tax2') as $k) {
                if (isset($data[$k])) { $data[$k] = (int) $data[$k]; }
            }
            if (isset($data['amount'])) { $data['amount'] = (float) $data['amount']; }
            $billable = isset($data['billable']) ? !empty($data['billable']) : !empty($row['billable']);
            unset($data['billable']);
            // Expenses_model::update() expects the full edit-form payload; merge over the current row.
            $payload = array_merge(array(
                'category'     => $row['category'],
                'amount'       => $row['amount'],
                'date'         => _d($row['date']),
                'currency'     => $row['currency'],
                'expense_name' => $row['expense_name'],
                'note'         => $row['note'],
                'reference_no' => $row['reference_no'],
                'paymentmode'  => $row['paymentmode'],
                'clientid'     => $row['clientid'],
                'project_id'   => $row['project_id'],
                'tax'          => $row['tax'],
                'tax2'         => $row['tax2'],
            ), $data);
            if ($billable) { $payload['billable'] = 1; }
            $ok = $this->expenses_model->update($payload, $id);
            $new = $this->db->where('id', $id)->get(db_prefix() . 'expenses')->row_array();
            $this->out(array('ok' => true, 'changed' => (bool) $ok, 'data' => $new));
        }
        $this->out(array('error' => 'method_not_allowed'), 405);
    }

    // --- invoices

    private function invoices($sub)
    {
        if ($this->method() === 'GET') {
            if ($sub !== '' && ctype_digit((string) $sub)) {
                $this->load->model('invoices_model');
                $inv = $this->invoices_model->get((int) $sub);
                $this->out(array('data' => $inv));
            }
            $this->dateRange($q, 'date');
            $st = $this->input->get('status');
            if ($st !== null && $st !== '') {
                $this->db->where('status', (int) $st);
            }
            $cl = $this->input->get('clientid');
            if ($cl) {
                $this->db->where('clientid', (int) $cl);
            }
            $this->db->select('id, number, prefix, clientid, date, duedate, currency, subtotal, total, status, total_tax, adminnote');
            $this->db->order_by('date', 'asc');
            $this->paging();
            $rows = $this->db->get(db_prefix() . 'invoices')->result_array();
            $this->out(array('data' => $rows));
        }
        $this->requireWrite();
        $in = $this->body();
        $this->need($in, array('clientid', 'date', 'currency', 'newitems'));
        if (!is_array($in['newitems']) || !count($in['newitems'])) {
            $this->out(array('error' => 'missing_fields', 'fields' => array('newitems[]')), 400);
        }
        $this->load->model('invoices_model');

        $items    = array();
        $subtotal = 0.0;
        $order    = 1;
        foreach ($in['newitems'] as $it) {
            $qty  = isset($it['qty']) ? (float) $it['qty'] : 1;
            $rate = isset($it['rate']) ? (float) $it['rate'] : 0;
            $items[] = array(
                'description'      => isset($it['description']) ? $it['description'] : '',
                'long_description' => isset($it['long_description']) ? $it['long_description'] : '',
                'qty'              => $qty,
                'rate'             => $rate,
                'unit'             => isset($it['unit']) ? $it['unit'] : '',
                'order'            => $order++,
                'taxname'          => isset($it['taxname']) && is_array($it['taxname']) ? $it['taxname'] : array(),
            );
            $subtotal += $qty * $rate;
        }
        $total = isset($in['total']) ? (float) $in['total'] : $subtotal;

        $data = array(
            'clientid'                  => (int) $in['clientid'],
            'date'                      => $this->uiDate($in['date']),
            'duedate'                   => isset($in['duedate']) && $in['duedate'] !== '' ? $this->uiDate($in['duedate']) : '',
            'currency'                  => (int) $in['currency'],
            'newitems'                  => $items,
            'subtotal'                  => $subtotal,
            'total'                     => $total,
            'discount_percent'          => 0,
            'discount_total'            => 0,
            'discount_type'             => '',
            'adjustment'                => isset($in['adjustment']) ? (float) $in['adjustment'] : 0,
            'adminnote'                 => isset($in['adminnote']) ? $in['adminnote'] : '',
            'clientnote'                => isset($in['clientnote']) ? $in['clientnote'] : '',
            'terms'                     => isset($in['terms']) ? $in['terms'] : '',
            'billing_street'            => isset($in['billing_street']) ? $in['billing_street'] : '',
            'billing_city'              => '',
            'billing_state'             => '',
            'billing_zip'               => '',
            'billing_country'           => 0,
            'include_shipping'          => 0,
            'show_shipping_on_invoice'  => 0,
            'show_quantity_as'          => 1,
            'allowed_payment_modes'     => isset($in['allowed_payment_modes']) ? $in['allowed_payment_modes'] : array(),
            'sale_agent'                => $this->staffId,
            'save_as_draft'             => !empty($in['save_as_draft']) ? 1 : 0,
        );
        if (isset($in['number']) && $in['number'] !== '') {
            $data['number'] = (int) $in['number'];
        }
        if (isset($in['status'])) {
            $data['status'] = (int) $in['status'];
        }
        $id = $this->invoices_model->add($data);
        if (!$id) {
            $this->out(array('error' => 'insert_failed', 'db' => $this->db->error()), 500);
        }
        $this->out(array('ok' => true, 'id' => (int) $id), 201);
    }

    // --- payments

    private function payments()
    {
        if ($this->method() === 'GET') {
            $this->dateRange($q, 'date');
            $this->db->order_by('date', 'asc');
            $this->paging();
            $rows = $this->db->get(db_prefix() . 'invoicepaymentrecords')->result_array();
            $this->out(array('data' => $rows));
        }
        $this->requireWrite();
        $in = $this->body();
        $this->need($in, array('invoiceid', 'amount', 'date', 'paymentmode'));
        $this->load->model('payments_model');

        $data = array(
            'invoiceid'                  => (int) $in['invoiceid'],
            'amount'                     => (float) $in['amount'],
            'date'                       => $this->uiDate($in['date']),
            'paymentmode'                => $in['paymentmode'],
            'transactionid'              => isset($in['transactionid']) ? $in['transactionid'] : '',
            'note'                       => isset($in['note']) ? $in['note'] : '',
            'do_not_send_email_template' => empty($in['send_email']) ? true : false,
        );
        if ($data['transactionid'] !== '') {
            $dup = $this->db->where('transactionid', $data['transactionid'])->get(db_prefix() . 'invoicepaymentrecords')->row();
            if ($dup) {
                $this->out(array('error' => 'duplicate', 'existing_id' => $dup->id), 409);
            }
        }
        $id = $this->payments_model->process_payment($data, $data['invoiceid']);
        if (!$id) {
            $this->out(array('error' => 'payment_failed', 'db' => $this->db->error()), 500);
        }
        $this->out(array('ok' => true, 'id' => (int) $id), 201);
    }
}
