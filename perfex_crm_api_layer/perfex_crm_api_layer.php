<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Perfex CRM API Layer
Description: Token-authenticated JSON API for automation (expenses, payments, invoices, lookups) plus the Annual Accountant Packet - period reporting with as-at-date accuracy. Writes go through Perfex models.
Version: 1.2.0
Requires at least: 2.3.*
Author: InteliClic
*/

define('PERFEX_CRM_API_LAYER_MODULE_NAME', 'perfex_crm_api_layer');

/**
 * Activation: create the API token option if it does not exist.
 * The token is stored in tbloptions as perfex_crm_api_layer_token.
 */
register_activation_hook(PERFEX_CRM_API_LAYER_MODULE_NAME, 'perfex_crm_api_layer_activation');

function perfex_crm_api_layer_activation()
{
    if (get_option('perfex_crm_api_layer_token') === false || get_option('perfex_crm_api_layer_token') === '') {
        // 48 hex chars; conservative PHP (no random_bytes dependency)
        $token = '';
        if (function_exists('openssl_random_pseudo_bytes')) {
            $token = bin2hex(openssl_random_pseudo_bytes(24));
        } else {
            $token = sha1(uniqid(mt_rand(), true)) . substr(sha1(mt_rand()), 0, 8);
        }
        add_option('perfex_crm_api_layer_token', $token);
    }
    if (get_option('perfex_crm_api_layer_staff_id') === false) {
        // staff id used as "addedfrom" for records created via the API (default: 1 = first admin)
        add_option('perfex_crm_api_layer_staff_id', 1);
    }
    if (get_option('perfex_crm_api_layer_other_income_categories') === false) {
        // Expense categories that are really income (bank interest booked as a negative
        // expense, because Perfex has no concept of income that is not an invoice).
        add_option('perfex_crm_api_layer_other_income_categories', '');
    }
    perfex_crm_api_layer_migrate();
}

/**
 * Create the module's own tables. Called on activation AND lazily by the report
 * engine on every construct: upgrading a module in place does not re-fire the
 * activation hook, so a table added in a later version would otherwise never exist
 * on an instance that was upgraded rather than freshly installed.
 */
function perfex_crm_api_layer_migrate()
{
    require_once __DIR__ . '/libraries/Report_engine.php';
    $CI = &get_instance();
    $e  = new Report_engine($CI);
    $e->migrate();
}

/**
 * Admin menu entry: Setup -> API Layer (shows the token and endpoint list).
 */
hooks()->add_action('admin_init', 'perfex_crm_api_layer_init_menu');

function perfex_crm_api_layer_init_menu()
{
    if (is_admin()) {
        $CI = &get_instance();
        $CI->app_menu->add_setup_menu_item('perfex-crm-api-layer', [
            'name'     => 'API Layer',
            'href'     => admin_url('perfex_crm_api_layer/admin'),
            'position' => 60,
        ]);
        // The packet is a report, not a setting — it goes in the Reports menu where
        // an accountant would look for it, not buried under Setup. Guarded: this
        // helper is not present on every Perfex build, and a fatal here would take
        // the whole admin down.
        if (method_exists($CI->app_menu, 'add_sidebar_children_item')) {
            $CI->app_menu->add_sidebar_children_item('reports', [
                'slug'     => 'perfex-crm-api-layer-packet',
                'name'     => 'Accountant Packet',
                'href'     => admin_url('perfex_crm_api_layer/reports'),
                'position' => 40,
            ]);
        }
        // Always reachable from Setup as well, so it is never lost if the Reports
        // menu is unavailable or hidden by a role.
        $CI->app_menu->add_setup_menu_item('perfex-crm-api-layer-packet', [
            'name'     => 'Accountant Packet',
            'href'     => admin_url('perfex_crm_api_layer/reports'),
            'position' => 61,
        ]);
    }
}
