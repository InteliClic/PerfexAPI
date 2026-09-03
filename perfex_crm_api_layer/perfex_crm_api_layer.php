<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Perfex CRM API Layer
Description: Minimal token-authenticated JSON API for automation (expenses, payments, invoices, lookups). Writes go through Perfex models.
Version: 1.1.1
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
    }
}
