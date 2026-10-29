# Paymento for Easy Digital Downloads

Accept crypto payments in your [Easy Digital Downloads](https://wordpress.org/plugins/easy-digital-downloads/) store with [Paymento](https://paymento.io).

Customers choose **Crypto with Paymento** at checkout, pay on Paymento's hosted payment page, and get their downloads as soon as the payment is confirmed on-chain. Paymento is non-custodial: payments go straight to your own wallet.

## Requirements

- WordPress 6.7 or newer, PHP 8.2 or newer
- Easy Digital Downloads 3.x (the free version is enough)
- Your site on **HTTPS**, reachable from the internet (Paymento sends payment notifications to it)
- A Paymento merchant account — sign up at [app.paymento.io](https://app.paymento.io)

## Installation

1. Download `paymento-easy-digital-downloads-x.y.z.zip` from the [latest release](https://github.com/paymento/paymento-easy-digital-downloads-plugin/releases/latest).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose the ZIP, then **Install Now** and **Activate**.

To update later, upload the new ZIP the same way and choose **Replace current with uploaded**. Your settings are kept.

## Setup

### 1. Get your Paymento keys

In your Paymento merchant panel, create a store for this website and copy its **API key** and **IPN secret**.

Use a separate Paymento store for each website. A store has one callback URL, and the plugin will not overwrite a callback that another site already uses.

### 2. Enter them in WordPress

Go to **Settings > Paymento EDD** (under the WordPress *Settings* menu, not the Downloads menu):

| Setting | Value |
|---|---|
| Environment | **Production** (use **Beta** only if Paymento gave you beta access) |
| API key / IPN secret | From step 1 |
| Payment expiry (hours) | How long a customer has to pay. Default **24**. Unpaid orders are marked *Abandoned* one hour after this |

Click **Save settings**.

> **Tip:** instead of saving keys in the dashboard, you can put them in `wp-config.php`:
> ```php
> define('PAYMENTO_EDD_API_KEY', 'your-api-key');
> define('PAYMENTO_EDD_IPN_SECRET', 'your-ipn-secret');
> ```

### 3. Connect the callback

On the same page, tick the confirmation box and click **Configure Paymento callback**. This tells Paymento to send payment notifications to:

```
https://your-site.com/wp-json/paymento-edd/v1/ipn
```

### 4. Turn on the gateway

Go to **Downloads > Settings > Payments**, switch on **Paymento** under *Active Gateways*, and click **Save Changes**.

### 5. Add a cron job (recommended)

The plugin retries any payment notification it could not process the first time (for example during a short outage). WordPress only runs scheduled tasks when someone visits your site, so on a quiet site a paid order could wait for the next visitor.

Ask your host to run this every minute, or add it in your hosting control panel:

```
* * * * * cd /path/to/wordpress && wp cron event run --due-now
```

If you can't use WP-CLI, your host's "cron job" feature can call `https://your-site.com/wp-cron.php` every minute instead.

## How orders move

| What happens | EDD order status |
|---|---|
| Customer is sent to Paymento | Pending |
| Payment confirmed on-chain | **Complete** — downloads unlocked |
| Customer never pays | Abandoned, after the payment expiry + 1 hour |
| Customer pays late, after the order was abandoned | Complete |
| Payment fully refunded in Paymento | Refunded |

The customer coming back to your site from the payment page never completes an order on its own. Only a signed notification from Paymento, checked against Paymento's API, does.

## Troubleshooting

**The order stays *Pending* after paying.**
Paymento could not reach your callback URL. Check that:
- your site is on public HTTPS;
- security plugins (Wordfence, iThemes, LiteSpeed, …), Cloudflare or your host's firewall don't block `/wp-json/paymento-edd/v1/ipn`;
- the callback is configured (step 3). In the Paymento merchant panel you can see the callbacks sent and the response your site gave.

**"Paymento: N callbacks have been pending for over one hour."**
Notifications arrived but could not be processed yet. Make sure the cron job (step 5) runs. They are retried automatically.

**"A different callback already exists."**
The Paymento store already sends notifications to another URL. Create a separate store for this website.

**"Unable to start crypto payment."**
Check the API key and that *Environment* matches the store (a beta key does not work on Production).

## Limits

- One-time payments only. Recurring payments, partial captures and refunds started from WordPress are not supported (refund in Paymento; the EDD order follows).
- Deactivating the plugin keeps all payment records.

## Development

Requires Docker and Node.js 22+.

```bash
npm test          # starts WordPress + EDD + MariaDB and a mock Paymento API in Docker, runs all tests
npm run package   # builds the installable ZIP in artifacts/
```

The test site runs at http://localhost:18080 with fake credentials. Never point the tests at a real store. `docker compose down` stops it.

How the plugin works internally — callback storage, verification, locking, retries and refunds — is described in [docs/how-it-works.md](docs/how-it-works.md).

## Support

- Bugs and questions: [GitHub Issues](https://github.com/paymento/paymento-easy-digital-downloads-plugin/issues)
- Security issues: see [SECURITY.md](SECURITY.md)
- Paymento API documentation: [docs.paymento.io](https://docs.paymento.io)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
