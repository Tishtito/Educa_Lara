# Email

Elimu Pass sends email to people: staff invitations, password reset links, and a
notice whenever a password changes. Any future email (report cards ready,
reminders, parent messages) should use the same foundation, described below.
Ops alerts about the queue are separate. `App\Operations\OpsAlerts` sends them
synchronously to `OPS_ALERT_EMAIL`, so they still get through when the queue is
broken.

## How it works

| Piece | Where |
|---|---|
| Base class for every people-facing email | `app/Notifications/ElimuPassMail.php` |
| Layout and theme | `resources/views/mail/notification.blade.php`, `resources/views/mail/elimupass-theme.blade.php` |
| Delivery log (`email_log`) | `app/Domain/Messaging/EmailLogger.php`; pruned daily by `mail:prune-log` |
| Invitations and reset links (`account_tokens`) | `app/Domain/People/Services/AccountLinks.php` |
| Account emails | `app/Notifications/Account/` |
| Settings | `config/mail.php` (`MAIL_*`), `config/educa.php` (`mail`, `apps`, `accounts`) |

Every `ElimuPassMail` follows these rules:

- **Queued** on the `notifications` queue on the long-running connection. A slow
  mail server never holds up a request. **A worker must serve `notifications`.**
  In production this is the `educa-general` Supervisor program
  (`deploy/supervisor/educa-worker.conf`). In development, `composer run dev`
  includes it.
- **Dispatched after the database commit.** A rolled-back transaction sends
  nothing.
- **Sent from one platform address** (`MAIL_FROM_ADDRESS`) under the school's
  name ("Gatimu Primary School via Elimu Pass"). **Replies** go to the school's own
  email address. One sending domain therefore covers every school's
  SPF/DKIM/DMARC.
- **Logged** to `email_log` as `sent` or `failed`: type, recipient and subject
  only. Never the body or links, because they carry sign-in tokens. A job that
  gives up also reaches ops through the failed-job alert.
- **Encrypted on the queue** (`ShouldBeEncrypted`) when it carries a secret.
  The invitation and reset emails do this, so the `jobs` and `failed_jobs`
  tables never hold a usable link.

Links in emails point at `EDUCA_ADMIN_URL` for administrators and superadmins,
and at `EDUCA_PORTAL_URL` for everyone else.

## Invitations and password resets

- **Invitations.** An administrator enters an email and leaves **Send an
  invitation email** on. The person gets a link, valid 7 days
  (`INVITATION_TTL_HOURS`), to choose their own password. Without an email the
  account gets a temporary password shown once, as before.
  - The admin can resend or cancel an invitation until the person first signs
    in.
  - Changing the email, deactivating or removing the account cancels the link.
  - A superadmin creating a school can invite its first administrator the same
    way.
- **Forgot password.** The sign-in screens email a link valid 60 minutes
  (`PASSWORD_RESET_TTL_MINUTES`).
  - The response is the same whether or not the account exists or has an email.
  - One link per account per minute; a newer link replaces the older one.
  - Accounts without an email still need an administrator's reset.
- **Using a link** sets the password, marks the email verified, signs out every
  other session and signs the person in. Links are single-use and stored only as
  a sha256 hash.
- **Security notice.** The person is emailed "your password was changed" when
  they change it themselves, use a reset link, or an administrator resets it.
  The forced first change after an admin reset or legacy import sends no notice.

## Setting up SMTP

Put the provider's details in `.env`, then check them:

```bash
php artisan config:clear
php artisan mail:test you@example.com
```

`mail:test` sends synchronously and prints the transport error if it fails:
wrong password, blocked port, or TLS mismatch.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587            # 587 = STARTTLS (leave MAIL_SCHEME=null); 465 = implicit TLS
MAIL_SCHEME=null         # or smtps for port 465
MAIL_USERNAME=no-reply@educa.example
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@educa.example
MAIL_FROM_NAME="Elimu Pass"
EDUCA_ADMIN_URL=https://admin.educa.example
EDUCA_PORTAL_URL=https://staff.educa.example
```

| Provider | Host | Port | Notes |
|---|---|---|---|
| cPanel / shared hosting | `mail.yourdomain` | 465 (`smtps`) or 587 | Use a real mailbox's full address and password. |
| Zoho Mail | `smtp.zoho.com` (`smtp.zoho.eu` for EU accounts) | 465 / 587 | Use an app-specific password if 2FA is on. |
| Google Workspace | `smtp.gmail.com` | 587 | Needs an app password (2-step verification on). Sending limit is about 2,000 per day. |
| Microsoft 365 | `smtp.office365.com` | 587 | SMTP AUTH must be enabled for the mailbox. |
| Mailgun | `smtp.mailgun.org` | 587 | The SMTP password is on the domain's SMTP credentials page — it is **not** the API key. A **sandbox** domain only delivers to addresses added under Sending → Authorized Recipients. |

Transactional providers (Mailgun, Postmark, Amazon SES, Resend) also offer SMTP
credentials that work here unchanged.

### Two mistakes that cost an afternoon

- **`MAIL_FROM_ADDRESS` must be an address, not a domain.** With a bare domain
  the message is rejected inside the transport, and for queued mail that shows
  up only as a failed job with `does not comply with addr-spec of RFC 2822`.
  `mail:test` now refuses that up front.
- **Editing `.env` does not reach a running worker.** `queue:listen` passes the
  environment it booted with to each job, and Laravel does not override an
  existing environment variable from the file. After changing mail settings,
  **restart `composer run dev`** (or the Supervisor workers in production with
  `php artisan queue:restart`), or invitations keep failing with the old
  settings while `php artisan mail:test` — a fresh process — succeeds.

### Deliverability

- **Send from a domain you control.** `MAIL_FROM_ADDRESS` must be on it, never
  a free Gmail/Yahoo address.
- **SPF:** a TXT record authorising the provider,
  e.g. `v=spf1 include:zoho.com ~all`.
- **DKIM:** publish the key the provider gives you.
- **DMARC:** start with `v=DMARC1; p=none; rua=mailto:dmarc@yourdomain`, then
  tighten.
- Ask schools to add the sender address to their contacts: school and ISP
  filters in Kenya are aggressive with first-time senders.

## Development

Day to day, run [Mailpit](https://mailpit.axllent.org), which catches everything
in a local inbox so nothing can reach a real person by accident:

```bash
docker run -d --name mailpit --restart unless-stopped -p 1025:1025 -p 8025:8025 axllent/mailpit
# inbox: http://localhost:8025      stop: docker stop mailpit      remove: docker rm -f mailpit
```

`.env.example` is already set for it (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`).
Tests use the `array` mailer (`phpunit.xml`) and never send anything.

To check real delivery instead, swap in the provider's block in `.env` (the
Mailgun lines are kept there, commented out), restart `composer run dev`, and
remember the sandbox recipient limit. Emailed links still point at
`EDUCA_ADMIN_URL` / `EDUCA_PORTAL_URL`, which are localhost in development — the
mail arrives anywhere, but its link only opens on this machine.

## Adding a new email

1. Create a class in `app/Notifications/…` that extends `ElimuPassMail`. Call
   `parent::__construct()`, implement `type()` (a stable log key) and
   `message()`, and start from `$this->mailMessage($school)`.
2. Pass ids and plain values to the constructor, not tenant-owned models
   (CLAUDE.md rule 13). `User` is not tenant-scoped, so notifying a user is fine.
3. Add `ShouldBeEncrypted` if the email contains a link that signs someone in or
   acts on their behalf.
4. Send with `$user->notify(new YourEmail(...))`. For a bare address, use
   `Notification::route('mail', $address)->notify(...)`.
5. Test with `Notification::fake()`: assert the recipient, and render
   `toMail()` for the content. See `tests/Feature/MailFoundationTest.php`.
