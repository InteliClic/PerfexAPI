<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin page: shows the token, staff id, and the endpoint list. Admins only.
 * URL: /admin/perfex_crm_api_layer/admin
 */
class Admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!is_admin()) {
            access_denied('perfex_crm_api_layer');
        }
    }

    public function index()
    {
        if ($this->input->post('regenerate') == '1') {
            $token = bin2hex(openssl_random_pseudo_bytes(24));
            update_option('perfex_crm_api_layer_token', $token);
            set_alert('success', 'API token regenerated');
            redirect(admin_url('perfex_crm_api_layer/admin'));
        }
        if ($this->input->post('staff_id') !== null && $this->input->post('staff_id') !== '') {
            update_option('perfex_crm_api_layer_staff_id', (int) $this->input->post('staff_id'));
            set_alert('success', 'Staff id updated');
            redirect(admin_url('perfex_crm_api_layer/admin'));
        }

        $token   = get_option('perfex_crm_api_layer_token');
        $staffId = (int) get_option('perfex_crm_api_layer_staff_id');
        $base    = site_url('perfex_crm_api_layer/api');

        $html = '<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">';
        $html .= '<h4 class="no-margin">Perfex CRM API Layer</h4><hr />';
        $html .= '<p><strong>Base URL:</strong> <code>' . htmlspecialchars($base) . '</code></p>';
        $html .= '<p><strong>Token:</strong> <code>' . htmlspecialchars($token) . '</code><br /><small>Send as header <code>X-Api-Token: &lt;token&gt;</code> (or <code>Authtoken</code>). Keep it secret.</small></p>';
        $html .= '<p><strong>Records are created as staff id:</strong> ' . $staffId . '</p>';
        $html .= '<form method="post">' . form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash());
        $html .= '<div class="form-group"><label>Staff id for API-created records</label><input class="form-control" style="max-width:200px" name="staff_id" value="' . $staffId . '" /></div>';
        $html .= '<button class="btn btn-default" type="submit">Save staff id</button> ';
        $html .= '</form><br /><form method="post" onsubmit="return confirm(\'Regenerate the token? Existing clients will stop working.\');">' . form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash());
        $html .= '<input type="hidden" name="regenerate" value="1" /><button class="btn btn-danger" type="submit">Regenerate token</button></form>';
        $html .= '<hr /><h5>Endpoints</h5><pre style="white-space:pre-wrap">'
            . "GET  /meta\n"
            . "GET  /currencies | /expense_categories | /payment_modes | /customers | /taxes\n"
            . "GET  /expenses?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=500&offset=0\n"
            . "PUT  /expenses            {category, amount, date(YYYY-MM-DD), currency, expense_name?, note?, clientid?, paymentmode?, reference_no?, billable?}\n"
            . "PUT  /expenses/batch      {items:[...expense objects...]}   (duplicates by reference_no+amount are skipped)\n"
            . "GET  /expenses/<id>  |  PATCH /expenses/<id> {any of the fields above}  |  DELETE /expenses/<id>\n"
            . "GET  /invoices?from=&to=&status=&clientid=\n"
            . "PUT  /invoices            {clientid, date, duedate?, currency, number?, newitems:[{description, long_description?, qty, rate, taxname?:[]}], status?, adminnote?, clientnote?}\n"
            . "GET  /payments?from=&to=\n"
            . "PUT  /payments            {invoiceid, amount, date(YYYY-MM-DD), paymentmode, transactionid?, note?}\n"
            . "\nWrites use PUT (POST is blocked by Perfex CSRF). Dates in YYYY-MM-DD. JSON body, JSON response.\n"
            . '</pre>';
        $html .= '</div></div></div></div>';

        $this->load->vars(array('title' => 'API Layer'));
        init_head();
        echo '<div id="wrapper"><div class="content">' . $html . '</div></div>';
        init_tail();
        echo '</body></html>';
    }
}
