# TradeWithCuso — API

Laravel API behind the [TradeWithCuso website](../tradewithcuso). It owns trader
accounts, trading plans, subscriptions and payments (Paystack and bank
transfer), site settings, and the admin dashboard's data.

## Requirements

- PHP 8.3+
- Composer
- MySQL 8+ / MariaDB 10.4+

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the `tradewithcuso` database, then:
php artisan migrate --seed
php artisan serve          # http://localhost:8000
```

Seeding creates the first owner account from `ADMIN_EMAIL` / `ADMIN_PASSWORD`
in `.env`, and three starter plans (Starter, Pro, Elite Mentorship) that you can
edit or replace from the dashboard. **Change the password after your first
sign-in.**

Then, in the dashboard, go to **Settings** and fill in the bank details to turn
on bank transfers. For card payments, set `PAYSTACK_SECRET_KEY` in `.env` and
point Paystack's webhook at `https://<api-domain>/api/paystack/webhook`.

## Layout

```
app/Http/Controllers/Api/           public endpoints and the Paystack webhook
app/Http/Controllers/Api/Account/   trader endpoints (customer guard)
app/Http/Controllers/Api/Admin/     dashboard endpoints (admin guard)
app/Services/Paystack.php           the only place naira becomes kobo
app/Support/SubscriptionPayments.php  turns confirmed money into an active plan
```

## Authentication

Traders (`users`) and staff (`admins`) are separate tables with separate
Sanctum guards, so a trader's token is never accepted by the dashboard. Both
send bearer tokens — no cookies, no CSRF handshake.

Admin roles: **owner** (everything, including the team) and **manager**
(everything except the team). Suspending a trader or deactivating an admin
revokes their tokens immediately.

## How a subscription works

1. A trader chooses a plan → a `pending` subscription is created with the plan's
   name, price and length **frozen** on it, so later price changes never touch
   an existing bill.
2. They pay:
   - **Card** — Paystack checkout. The webhook and the trader's return both
     verify with Paystack; whichever lands first activates the plan.
   - **Bank transfer** — they transfer, using the reference as narration, and
     tap "I've made the transfer". The team gets an email, and the dashboard
     shows it under Subscriptions → "Transfer to confirm".
3. It goes `active` with start and end dates. If the trader already has time
   left, the new plan is **queued** to start when the current one ends.

Expiry is read from the dates — no cron job needed. Admins can also add a plan
to a trader by hand (a comp or an offline payment); record ₦0 for a free place
so it never counts as revenue.

## Endpoints

### Public

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/plans` | Active plans |
| GET | `/api/plans/{slug}` | One plan |
| GET | `/api/settings` | Contact details + which payment methods are on |
| POST | `/api/paystack/webhook` | Paystack callback (signature-checked) |

### Trader (`/api/account/*`)

`register`, `login`, `me`, `logout`, `profile`, `password`, and
`subscriptions` — list, create, show, plus `/{reference}/paystack`,
`/verify-payment`, `/transfer-sent` and `/cancel`.

### Dashboard (`/api/admin/*`)

`POST /api/admin/login` issues the token; everything else requires it:
dashboard, plans, subscriptions (activate / cancel / note / delete), users
(suspend / grant a plan / delete), settings, and team (owner only).

## Things worth knowing

**Paid records are never deleted.** A plan with subscribers can only be hidden;
a trader with payments can only be suspended; a paid subscription can only be
cancelled.

**A short payment doesn't activate.** If Paystack reports less than the bill,
the amount is recorded with a note and the plan stays pending for an admin.

**CORS is an allow-list** in `config/cors.php`. Add any new website origin
there, and run `php artisan config:clear` on a server that caches config.

**Mail never breaks a request.** Emails are sent inline through
`App\Support\Notifier`; a mail failure is logged, not shown to the trader.

## Tests

```bash
php artisan test
```
