<?php
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Fixture requires local environment.'); }
if (EDD_VERSION !== '3.7.2' || get_bloginfo('version') !== '7.1.3') { throw new RuntimeException('Fixture versions differ from the pinned tested baseline.'); }
// The CLI activation request does not run EDD's normal admin table-install path.
edd_install_component_database_tables();
Paymento_EDD::activate();
edd_update_option('gateways', array('paymento' => '1', 'manual' => '1'));
edd_update_option('default_gateway', 'paymento');
edd_update_option('currency', 'USD');
edd_update_option('test_mode', true);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'edd' => EDD_VERSION, 'php' => PHP_VERSION, 'checkout_page' => edd_get_option('purchase_page'), 'success_page' => edd_get_option('success_page'))) . PHP_EOL;
