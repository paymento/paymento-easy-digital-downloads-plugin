=== Paymento for Easy Digital Downloads ===
Contributors: paymento
Tags: crypto, payments, easy-digital-downloads, bitcoin, gateway
Requires at least: 6.7
Tested up to: 7.1
Requires Plugins: easy-digital-downloads
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto payments in Easy Digital Downloads through Paymento's hosted, non-custodial checkout.

== Description ==
Customers choose "Crypto with Paymento" at checkout, pay on Paymento's hosted payment page, and receive their downloads once the payment is confirmed on-chain. Payments go directly to the merchant's own wallet.

* Signed payment notifications (HMAC-SHA256), confirmed with Paymento's API before an order is completed.
* Notifications are stored before processing and retried automatically if processing fails.
* Unpaid orders are marked abandoned after a configurable expiry; a late payment still completes the order.
* Full refunds made in Paymento mark the EDD order refunded.

One-time payments only. No recurring payments or refunds started from WordPress.

== Installation ==
1. Install and activate Easy Digital Downloads.
2. Upload this plugin's ZIP under Plugins > Add New > Upload Plugin and activate it.
3. Go to Settings > Paymento EDD. Enter your Paymento store's API key and IPN secret (or define PAYMENTO_EDD_API_KEY and PAYMENTO_EDD_IPN_SECRET in wp-config.php), set the payment expiry, and save.
4. Click "Configure Paymento callback". Your site must be on public HTTPS. Use a separate Paymento store for each website.
5. Enable Paymento under Downloads > Settings > Payments.
6. Recommended: run `wp cron event run --due-now` every minute from a server cron job so failed notifications are retried promptly.

== Frequently Asked Questions ==
= The order stays pending after payment =
Paymento could not reach https://your-site/wp-json/paymento-edd/v1/ipn. Make sure security plugins, Cloudflare or your host's firewall do not block it, and that the callback is configured.

= Where are the keys stored? =
Constants in wp-config.php are preferred. Keys saved in the dashboard are encrypted with AES-256-GCM using your WordPress authentication salt; if you change the salts, enter the keys again.

== External service ==
This plugin uses the Paymento API (https://api.paymento.io). When a customer checks out, it sends the order ID, amount, currency, customer email, return URL and payment expiry to Paymento and redirects the customer to Paymento's hosted checkout. Paymento sends signed payment status notifications back to your site.

* Service: https://paymento.io
* Terms of service: https://paymento.io/term-of-service/
* Privacy policy: https://paymento.io/privacy-policy/
* API documentation: https://docs.paymento.io

== Changelog ==
= 1.0.2 =
Text domain changed to paymento-easy-digital-downloads to match the plugin slug. Settings input is sanitized.

= 1.0.1 =
Removed the Environment setting; the plugin always uses Paymento production.

= 1.0.0 =
First public release.
