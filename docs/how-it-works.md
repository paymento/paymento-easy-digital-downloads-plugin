# How the plugin works

For developers and security reviewers. Store owners only need the [README](../README.md).

All gateway code is in [`includes/class-paymento-edd.php`](../includes/class-paymento-edd.php). It uses the Paymento API described at [docs.paymento.io](https://docs.paymento.io).

## Checkout

1. EDD calls the `edd_gateway_paymento` action. The plugin checks EDD's `edd-gateway` nonce and creates a **pending** EDD order with `edd_insert_payment`.
2. It reads the total and currency back from the saved order, never from the browser, and calls `POST /v1/payment/request` with `fiatAmount` (decimal string), `fiatCurrency`, `orderId`, `returnUrl`, `emailAddress` and `tokenExpiryDate`.
3. The returned token is saved in `{prefix}paymento_edd_attempts` together with the order ID, amount, currency and the API/checkout URLs used. One order can have several attempts if the customer retries checkout.
4. The customer is redirected to `…/gateway?token=…`. The cart is emptied only after the token is saved.

## Payment notifications (IPN)

Route: `POST` or `PUT` `/wp-json/paymento-edd/v1/ipn`.

1. The `X-HMAC-SHA256-Signature` header must be the uppercase hex HMAC-SHA256 of the **raw body** with the IPN secret, compared in constant time. Otherwise `401`.
2. Bodies over 64 KB get `413`; invalid JSON gets `400`. The `Token` and `OrderId` must match a saved attempt, or the response is `404`. Unknown tokens never reach the Verify API.
3. The notification is stored in `{prefix}paymento_edd_inbox`. If storing fails, the plugin answers `503` so Paymento retries.
4. Once stored, the plugin answers `200` **even if processing fails**. This is required: the first successful Verify moves a Paymento order from Paid to Approve, which stops Paymento's own retries. Processing failures are retried locally instead (see below).

The return page (`?paymento_return=1`) only shows a generic message. Its `status` parameter is unsigned and is never used.

## Processing a notification

`process_row()` runs for each stored notification, first right after it arrives and later from the retry job.

1. It takes a MySQL `GET_LOCK` for the **EDD order**, not the token, so two attempts for one order can't race. If the lock is busy, the row is left for the next retry.
2. It checks that the EDD order's total and currency still match the attempt. If they don't, it stops without calling Verify.
3. It calls `POST /v1/payment/verify` and requires `body.token` and `body.orderId` to match the attempt. `body.orderStatus` decides the outcome; `success` is ignored, since a healthy pending payment returns `success: false`. If `settlement.requestedFiatAmount` is present, it must match.
4. The verified status is saved on the inbox row **before** EDD is changed. If the process dies after Verify, the retry reuses the saved status instead of depending on Paymento.
5. Status 7 or 8 completes the order with `edd_update_payment_status(…, 'complete')`, and the transaction ID is set to `paymento-attempt-{id}`. Other statuses leave the order unchanged.
6. The row is marked processed.

Any failure leaves the row pending with a generic `last_error`. No remote response, token or secret is stored in it.

## Several attempts and refunds

`{prefix}paymento_edd_orders` records, for each EDD order, the **winning attempt** (the first verified 7/8) and whether the order is terminally refunded.

- A later paid attempt for an already-paid order does not complete it again. It is flagged `duplicate_payment`, and an admin notice asks the merchant to review it.
- A refund (status 10) of the winning attempt marks the EDD order **refunded**. A refund of a losing or unused attempt does not.
- A refunded order is never completed again by a late or cached paid result.
- A paid order never goes back to pending because an older attempt reports Timeout or UserCanceled.

## Scheduled jobs

| Hook | Interval | Job |
|---|---|---|
| `paymento_edd_retry` | every minute | Reprocesses pending inbox rows, least-tried first, for up to 5 seconds per run |
| `paymento_edd_expire` | hourly | Marks pending Paymento orders **abandoned** once their newest attempt is older than the payment expiry + 1 hour. It takes the same order lock |

WP-Cron only runs on page visits, so production sites should call it from a real cron job. An admin notice appears when notifications have been pending for over an hour.

Paymento does not reliably notify for orders that are never paid, so expiry is enforced locally. The expiry is also sent as `tokenExpiryDate` so Paymento's checkout closes at the same time. An abandoned order that later receives a verified payment is completed.

## Settings and secrets

- **Settings page:** Settings > Paymento EDD. It requires `manage_options` and a nonce.
- **Secrets:** the `PAYMENTO_EDD_API_KEY` / `PAYMENTO_EDD_IPN_SECRET` constants take precedence. Dashboard-saved secrets are encrypted with AES-256-GCM using a key derived from `wp_salt('auth')`, and are never shown again.
- **Callback setup:** *Configure Paymento callback* reads the store's current settings first and refuses to replace a different callback URL. It then sets `ipN_URL` and `ipN_Method: 1`.
- **Endpoints:** always `api.paymento.io` and `app.paymento.io/gateway`. The constants `PAYMENTO_EDD_API_BASE` / `PAYMENTO_EDD_CHECKOUT_BASE` override them for test setups, such as the local Docker fixture. Never point them at a service you don't trust: the API key is sent to that address.

## Known limits

- EDD sends emails and fires hooks during its own status change. If PHP dies in the middle of that, the retry restores the correct order status, but an email may be missing or sent twice.
- Deactivating or deleting the plugin keeps its three tables as payment records.
- Refund callbacks are sent once by Paymento and are not resent if your site is down.

## Tests

`npm test` runs everything in Docker against real WordPress, EDD and MariaDB, with a mock Paymento API (`tests/mock-paymento.mjs`):

| File | Covers |
|---|---|
| `tests/integration.php` | Signatures, payload checks, verification, outages, crash recovery, refunds, expiry |
| `tests/attempt-refunds.php` | Multiple attempts per order, winner and refund rules, schema migration |
| `tests/concurrency*.php` / `.mjs` | Separate PHP processes racing on one order |
| `tests/browser-checkout.mjs` | Guest checkout and download over HTTP |
| `tests/admin-security.mjs` | Settings permissions, nonces, secret encryption |
| `tests/package-smoke.php` | Not run by `npm test`: loads a built ZIP from `/tmp/paymento-edd-package.zip` inside the CLI container and reruns the integration tests |
