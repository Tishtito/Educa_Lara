# Subscriptions and M-Pesa payments

Each school has **one subscription**. While it is paid, everything works. Once it
lapses, the features a school pays for are locked, in the API and in both apps,
until the school renews. Nothing is ever deleted.

| Locked (402) | Always open |
|---|---|
| Exams: create, set up, edit, delete, change status, compute | Sign-in, account, sessions |
| Marking: marking overview, marksheets, marks-out-of | Pupils, class pupils (`/my/*`) |
| Mark lists, stream list, statistics | Staff, assignments, roles |
| Report cards, PDFs, class batches, remarks | Set-up: calendar, classes, subjects, grading |
| | School profile, dashboard, the exam list and `/me/*` |
| | **Billing**, so the school can pay |

- **In the apps:**
  - Educa Admin blurs **Exams** with a renewal card.
  - Educa Staff blurs **Marking**, **Mark list**, **Reports** and a class's
    **Marks** tab.
  - Both show a banner in the last week and once lapsed.
- **Superadmins** are never locked, so support can look into a school.

## Who does what

| | |
|---|---|
| **Platform superadmin** (`manage_subscriptions`) | Sets each school's **price** (KES) and **cycle** (months); sets **paid-until** (trial, extensions); records cash or bank payments; assigns Paybill payments that named no school. Every change needs a reason and is kept (`subscription_adjustments`). *Platform → Billing*, or *Schools → Subscription*. |
| **School administrator** (`manage_billing`, which a school can also give a custom role such as Bursar) | Sees the subscription and payments, and renews with M-Pesa. *Settings → Subscription*. |
| **Everyone at the school** | Sees the banner and the lock. |

## The rules

- **Paid until** is the last day the school may use the locked features,
  **inclusive**, in the school's timezone (Africa/Nairobi). Paid until
  31 October means all of 31 October; the lock starts at midnight.
- **Trial:** every new school (and every school that existed when this shipped)
  gets `BILLING_TRIAL_DAYS` (30) days, counting the first day. It has no price
  until the superadmin sets one, and the school cannot pay before that.
- **Credit and cycles** (`App\Domain\Billing\SubscriptionLedger`):
  ```
  credit += payment
  while price > 0 and credit >= price:
      start      = later of (paid_until + 1 day) and today
      paid_until = start + cycle_months − 1 day
      credit    -= price
  ```
  - Paying early loses no days, and paying late is not charged for the locked
    days.
  - Part payments (at the Paybill) wait as credit. A payment worth two cycles
    adds two, and anything left over stays as credit.
  - The STK prompt asks for price − credit.
- **Missing subscription row:** the school is locked (fail-closed).
- **Settled payments are final.** Settling one again does nothing, so replayed
  webhooks cannot credit twice.

## Paying with M-Pesa

Every school pays the **one Educa Paybill**. The school code (`schools.slug`) is
the account number.

### STK push (the "Pay with M-Pesa" button)

```
Admin app ─POST /billing/stk {phone}─▶ API ─stkpush─▶ Safaricom ─PIN prompt─▶ phone
                                        │ pending payment (CheckoutRequestID)
Safaricom ─POST /webhooks/pay/{secret}/stk─▶ API: ResultCode 0 + same amount → credit
Admin app ─GET /billing/payments/{id} (every 3 s)─▶ settled? (after 60 s the API asks Safaricom itself)
```

- **One prompt at a time:** a new prompt is refused (409) while the last one is
  under 2 minutes old.
- **Missing callbacks:** `billing:reconcile` (every 5 minutes) asks Safaricom
  about STK payments pending for more than 2 minutes. After 24 hours they are
  marked failed.
- **Failures:** cancelled, wrong PIN or insufficient funds mark the payment
  failed with Safaricom's message; nothing is credited.

### Paybill (C2B)

Anyone can pay the Paybill with the school code as the account number: the
bursar, or the head teacher's phone.

- **Validation** always accepts. A payment is never bounced because of a typo.
- **Confirmation** records the payment:
  - Account matches a school code (ignoring case and spaces) → credited.
  - Otherwise it is stored as **unmatched**. It shows under *Platform → Billing
    → Unmatched*, where the superadmin assigns it to the right school with a
    reason.
- **Duplicates:** a receipt number already recorded is ignored.

### Webhook security

Daraja does not sign its callbacks:
- The path is `/api/webhooks/pay/…`, never `.../mpesa/...`: Safaricom refuses to
  register a callback URL containing "mpesa", "safaricom", "exe" or "sql"
  ("Invalid ValidationURL - URL has the word MPESA").
- Every webhook URL contains `MPESA_WEBHOOK_SECRET`, compared in constant time.
  A wrong secret gets a plain 404.
- `MPESA_ALLOWED_IPS` optionally limits callers to Safaricom's IPs. Set it in
  production.
- STK callbacks only settle a `CheckoutRequestID` we created, for the amount we
  asked.
- Callbacks are always answered `{"ResultCode":0}` so Safaricom stops retrying.
  Failures are logged, and reconciliation picks STK payments up again.

## Emails

All emails go to whoever holds `manage_billing` in the school, through
`EducaMail` on the `notifications` queue:
- **Receipt** after every settled payment: amount, M-Pesa code, new paid-until
  date, credit.
- **Reminders** (`billing:remind`, daily 07:00 Nairobi): 7 days before, the last
  day, and the day it lapses. Each goes once per paid-until date.

## Set-up

### Configuration (`.env`)

| Variable | |
|---|---|
| `BILLING_TRIAL_DAYS` | Trial length for new schools (30) |
| `BILLING_DEFAULT_CYCLE_MONTHS` | Starting cycle for new schools (4) |
| `MPESA_ENV` | `sandbox` or `production` |
| `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET` | From your Daraja app |
| `MPESA_SHORTCODE` | The Paybill number (sandbox: `174379`) |
| `MPESA_PASSKEY` | Lipa na M-Pesa Online passkey (sandbox: from the Daraja simulator page) |
| `MPESA_CALLBACK_BASE_URL` | Public **https** base of the API, e.g. `https://api.educa.co.ke`. Empty locally: the ngrok tunnel is used |
| `NGROK_ENABLED`, `NGROK_DOMAIN`, `NGROK_BIN` | Local only: the tunnel `composer run dev` opens |
| `MPESA_WEBHOOK_SECRET` | Long random string: `php -r 'echo bin2hex(random_bytes(24));'` |
| `MPESA_ALLOWED_IPS` | Optional, comma-separated |

Until all the `MPESA_*` values are set, the apps say "M-Pesa payments are not
switched on yet" and the superadmin can still record payments by hand.

### Sandbox (development)

Safaricom calls back over public https. `composer run dev` handles that: its
**tunnel** pane (`php artisan mpesa:tunnel`) opens an ngrok tunnel to
`127.0.0.1:8000`.
- **The URL is used automatically.** It is cached, and the app uses it while
  `MPESA_CALLBACK_BASE_URL` is **empty**. Keep it empty locally.
- **Sandbox Paybill URLs** are registered on every start.
- **The pane prints** the tunnel and the STK callback URL, with the secret
  masked. It warns if the secret or the Daraja keys are missing.

Steps:
1. **ngrok:** install it and run `ngrok config add-authtoken …` once.
   - Free accounts have one static domain (dashboard.ngrok.com → Domains). Put
     it in `NGROK_DOMAIN` so the URL never changes; ngrok may already use it by
     default.
   - `NGROK_ENABLED=false` turns the tunnel off.
2. **Daraja app:** create one on
   [developer.safaricom.co.ke](https://developer.safaricom.co.ke) with *Lipa na
   M-Pesa Sandbox* and *M-Pesa Sandbox* (C2B). Put the consumer key and secret,
   the passkey, `MPESA_SHORTCODE=174379` and a long `MPESA_WEBHOOK_SECRET` in
   `.env`.
3. **Restart `composer run dev`.** Workers keep their old environment until they
   restart.
4. **Price:** as superadmin, give the school one under *Platform → Billing →
   Manage*.
5. **Pay:** as its administrator, open *Settings → Subscription → Pay with
   M-Pesa*, using the sandbox test number from the Daraja portal. Requests
   arriving through the tunnel show at http://127.0.0.1:4040.

**Rules and failures:**
- **"Service is currently unreachable" (500.003.1001)** when registering the
  Paybill URLs is Safaricom's sandbox being down for C2B, not a problem here: it
  answers the same for any URL, including `https://example.com`. STK push (the
  Pay with M-Pesa button) does not depend on registration, so payments can still
  be tested. Restart `composer run dev` later to register again.
- **"Invalid ValidationURL - URL has the word MPESA"** means a callback path
  contains a word Safaricom's validator blocks (mpesa, safaricom, exe, sql).
  That is why the path is `/api/webhooks/pay/…`; `MpesaPaymentsTest` pins it.
- **Local only:** the tunnel is ignored outside `APP_ENV=local`, and the command
  refuses to start there.
- **Never exits:** if ngrok is missing or its authtoken is wrong, the pane says
  so and waits. The server and queues keep running (`concurrently
  --kill-others` would otherwise stop them all).

### Production

- Go live on the Daraja portal with the Educa Paybill and its production passkey.
- Set `MPESA_ENV=production` and the production keys, `MPESA_ALLOWED_IPS`, and
  an https `MPESA_CALLBACK_BASE_URL`. Production never runs `mpesa:tunnel`: a
  configured base URL always wins.
- Run `php artisan mpesa:register-urls` once.
- The scheduler must be running (`billing:reconcile`, `billing:remind`), and so
  must the `notifications` queue worker.

## Handling problems

| Situation | What to do |
|---|---|
| A school paid but is still locked | *Platform → Billing → Payments*: find the receipt. **Pending STK:** wait for `billing:reconcile`, or run it. **Unmatched Paybill:** assign it. **Not there at all:** the callback never reached us; record it by hand with the M-Pesa code as the reference. |
| Paid to the wrong school code | If that code is another real school, it was credited there. Record a correcting manual payment for the right school and adjust the other school's paid-until date, each with a reason. |
| A school needs more time | *Manage → Paid until*, with a reason. |
| Cash or bank transfer | *Manage → Record a payment*: it goes through the same ledger and emails a receipt. |

## Code

- **`app/Domain/Billing`:**
  - `Subscriptions`: state and the "is it active" check;
  - `SubscriptionLedger`: money into days, and receipts;
  - `SubscriptionAdmin`: superadmin changes and their audit trail;
  - `Mpesa/`: `DarajaClient`, `MpesaPayments` and `Phone`.
- **Lock:** the `subscribed` middleware (`EnsureSubscriptionActive`). It runs
  after `tenant` and before route bindings.
- **Tests:**
  - `SubscriptionLockTest` pins the exact list of locked routes; a new exam
    route must be added to the list or kept out of it deliberately.
  - `SubscriptionLedgerTest`, `MpesaPaymentsTest`, `PlatformSubscriptionsTest`.
