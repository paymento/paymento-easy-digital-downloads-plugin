import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';

// Docker development fixture only. Control routes are intentionally absent from production code.
const payments = new Map();
let mode = 'normal';
let verifies = 0;
const json = (res, status, body) => { res.writeHead(status, {'content-type': 'application/json'}); res.end(JSON.stringify(body)); };
const server = createServer(async (req, res) => {
  try {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const raw = Buffer.concat(chunks).toString();
    const body = raw ? JSON.parse(raw) : {};
    if (req.url === '/health') return json(res, 200, {ready: true});
    if (req.url === '/__control' && req.method === 'POST') {
      if (body.reset) { payments.clear(); mode = 'normal'; verifies = 0; }
      if (body.mode) mode = body.mode;
      if (body.token) {
        const payment = payments.get(body.token);
        if (!payment) return json(res, 404, {error: 'unknown mock token'});
        if ('status' in body) payment.status = body.status;
        if ('verifyToken' in body) payment.verifyToken = body.verifyToken;
        if ('verifyOrderId' in body) payment.verifyOrderId = body.verifyOrderId;
      }
      return json(res, 200, {ok: true});
    }
    if (req.url === '/__state') return json(res, 200, {mode, verifies, payments: [...payments.values()]});
    if (req.headers['api-key'] !== 'synthetic-api-key') return json(res, 401, {success: false, message: 'API_KEY_INVALID'});
    if (mode === 'outage') return json(res, 503, {success: false, message: 'synthetic outage'});
    if (req.url === '/v1/payment/request') {
      if (mode === 'refuse') return json(res, 200, {success: false, message: 'synthetic business refusal', body: ''});
      if (typeof body.fiatAmount !== 'string' || !/^\d+(\.\d+)?$/.test(body.fiatAmount)) return json(res, 200, {success: false, message: 'decimal string required', body: ''});
      const token = randomUUID().replaceAll('-', '');
      payments.set(token, {token, orderId: body.orderId, amount: body.fiatAmount, currency: body.fiatCurrency, additionalData: body.additionalData ?? [], tokenExpiryDate: body.tokenExpiryDate, status: 0});
      return json(res, 200, {success: true, message: '', body: token});
    }
    if (req.url === '/v1/payment/verify') {
      verifies++;
      const payment = payments.get(body.token);
      if (!payment) return json(res, 200, {success: false, message: 'Invalid Token', body: {token: '', orderStatus: 0}});
      if (payment.status === 7) payment.status = 8;
      return json(res, 200, {success: payment.status === 8, message: '', body: {
        token: payment.verifyToken ?? payment.token,
        orderId: payment.verifyOrderId ?? payment.orderId,
        orderStatus: payment.status,
        additionalData: payment.additionalData,
        settlement: [7, 8, 10].includes(payment.status) ? { requestedFiatAmount: Number(payment.amount) } : null,
      }});
    }
    if (req.url === '/v1/payment/settings') return json(res, 200, {success: true, message: '', body});
    return json(res, 404, {error: 'unknown mock route'});
  } catch { json(res, 400, {error: 'invalid request'}); }
});
server.listen(3000, '0.0.0.0', () => console.log('Synthetic Paymento fixture listening on :3000'));
