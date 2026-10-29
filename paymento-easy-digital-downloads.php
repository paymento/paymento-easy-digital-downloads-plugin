<?php
/**
 * Plugin Name: Paymento for Easy Digital Downloads
 * Description: Accept crypto payments in Easy Digital Downloads through Paymento's hosted, non-custodial checkout.
 * Plugin URI: https://github.com/paymento/paymento-easy-digital-downloads-plugin
 * Author: Paymento
 * Author URI: https://paymento.io
 * Version: 1.0.1
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Requires Plugins: easy-digital-downloads
 * License: GPL-2.0-or-later
 * Text Domain: paymento-edd
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/class-paymento-edd.php';
Paymento_EDD::instance()->register_hooks();
register_activation_hook(__FILE__, array('Paymento_EDD', 'activate'));
register_deactivation_hook(__FILE__, array('Paymento_EDD', 'deactivate'));
