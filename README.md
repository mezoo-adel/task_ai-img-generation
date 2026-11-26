# PixelForge image generation

A Laravel, Vue, and Inertia app for the [PixelForge assessment](https://pixelforge.promptedit.dev/assessment). The feature instructions and checklist below describe Phase 1 as implemented: submission, signed-webhook and sweep recovery, exact settlement, and the generate/history UI. PixelForge uses simulated images and prices. The [Laravel Cloud preview](https://task-ai-img-generation-production-7wrysp.laravel.cloud/) hosts the starter only; a verified Phase 1 deployment has not been published yet.

## Run locally

Prerequisites: PHP 8.4.1+ with PDO SQLite for the defaults, Composer 2, and Node 22.

**1. Install dependencies.** From this directory:

```sh
composer install
npm ci
```

**2. Configure the environment** (fresh setup only — never overwrite an existing `.env`, app key, or database):

```sh
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi
test -f database/database.sqlite || touch database/database.sqlite
```

The defaults use SQLite and the database drivers for queue, cache, and session — no Redis needed. If you prefer MySQL, create a dedicated local database and set the `DB_*` values; install Redis only if you select it.

**3. Add local PixelForge keys.** Set `PIXELFORGE_API_KEY` and the non-secret `PIXELFORGE_KEY_LABEL` (see [Configure PixelForge](#configure-pixelforge)). `PIXELFORGE_WEBHOOK_SECRET` is only needed for webhook delivery, which requires a public HTTPS URL — locally the scheduler covers updates instead.

**4. Migrate and seed.**

```sh
php artisan migrate --seed
```

`--seed` is safe and non-destructive: it creates the demo account below only when `APP_ENV` is `local` or `testing`, and skips it if the address is already taken. This is not `migrate:fresh` — existing data is kept.

### Local demo account

Sign in with **`admin@example.com` / `admin123`**. Despite the address it is an ordinary verified user with no admin role, meant only for local development. For cloud verification use a separate, private evaluator account — never this one.

**5. Run — keep two terminals open.**

```sh
# Terminal 1: web app, Vite frontend, and queue listener
composer run dev

# Terminal 2: recovery sweep that updates generations
php artisan schedule:work
```

Then open `http://localhost:8000` and sign in with the demo account.

**Try it.** Include `[succeed]` in a prompt to exercise the success flow or `[slow]` to test a longer wait. History checks for updates automatically. Without a public webhook, the scheduler polls for the outcome, so a row can go straight from queued to finished. `SubmitGeneration DONE` only means the worker finished its submission job; it does not confirm acceptance or completion. Do not resubmit an in-progress request just to check its status.

## Configure PixelForge

Put `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, and a non-secret `PIXELFORGE_KEY_LABEL` in local configuration. Use a separate key and signing secret in each environment. Never commit `.env`, keys, signing secrets, or signed image URLs.

Email verification is disabled, so no mail is needed to sign up or sign in. Outbound mail is only used for password-reset links; for local testing set `MAIL_MAILER=log` and read the link from the local Laravel log. Production can use any SMTP provider.

In the PixelForge dashboard, set the webhook URL to `https://<public-host>/webhooks/pixelforge` and use **Send test event**. Local webhook delivery needs a public HTTPS tunnel; local polling does not. On the public deployment, run the web app, worker, and scheduler with a shared cache and persistent MySQL. Set `APP_DEBUG=false`, configure outbound mail for password resets, and create an evaluator account with sample generations. Share its access privately, not in the repository.

## Deploy to Laravel Cloud

Deploy from this repository on branch `main`, then configure the environment canvas:

1. **Database** — attach a Laravel Cloud MySQL database; Cloud injects the `DB_*` variables. Make sure the deploy command runs `php artisan migrate --force`.
2. **Cache and queues (Valkey)** — click **Cache** on the canvas, create a **Laravel Valkey** cache, and attach it. Cloud injects `REDIS_HOST`, `REDIS_PASSWORD`, and `REDIS_URL` on the next deploy. Set `QUEUE_CONNECTION=redis` and keep `SESSION_DRIVER=database` (or `redis`). Cloud sets `CACHE_STORE` for you.
3. **Queue worker** — on the App cluster (or a dedicated worker cluster), add a background process with the **queue worker** configuration so `queue:work` runs continuously; `SubmitGeneration` jobs need it. Cloud restarts workers after each deploy.
4. **Scheduler** — enable the **Scheduler** toggle on the App cluster. Cloud runs `schedule:run` every minute, which drives `PollOverdueGenerations`; `onOneServer` plus the `WithoutOverlapping` mutex keep it safe across replicas.
5. **Email (optional)** — needed only for password-reset links; accounts never need verification. Use any SMTP provider (Brevo works on the free tier): `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` (`smtp` for 587/STARTTLS, `smtps` for 465), `MAIL_USERNAME` (the provider's SMTP login), `MAIL_PASSWORD` (the SMTP key), and `MAIL_FROM_ADDRESS` to a sender the provider accepts.
6. **PixelForge** — set the production `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, and `PIXELFORGE_KEY_LABEL`, register `https://<app>.laravel.cloud/webhooks/pixelforge` in the PixelForge dashboard, and use **Send test event**.
7. Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=https://<app>.laravel.cloud`, then redeploy.

## Use the app

1. Sign in or register an account. Open **Create**, enter a prompt, pick a size and quality, and optionally add up to four reference image links.
2. Check the estimated USD range before submitting. It includes the 25% markup but is not a price cap.
3. Open **History** for progress, the image, and the final charge. A successful generation is charged once, a failure is free, and image links expire after 24 hours. Balances and test credit purchases are not part of Phase 1.

## Manual verification

Run these checks in a disposable environment after implementation; they are not automated tests.

| Action | Expected result |
| --- | --- |
| Change quality, dimensions, or reference links. | The estimate updates without creating a generation; submit is unavailable without a valid estimate. |
| Generate with `[succeed]`, `[fail]`, `[slow]`, `[blocked]`, and `[overloaded]`. | Success shows the image and one exact charge; failure and policy rejection show no charge; slow work shows progress; overload backs off and does not retry forever. |
| Generate with `[succeed] [duplicate]`, then resend the success and earlier processing events from PixelForge. | One stored entry per event ID and one charge per generation. Late processing does not change the final result. |
| Send a dashboard ping; send a request with an invalid signature; repeat with the local secret unset. | Valid ping is acknowledged. Invalid signatures are rejected, and the missing-secret case returns `503` before any write. Restore local configuration afterward. |
| Temporarily disable webhook delivery in the disposable environment, generate an image, then restore delivery and resend its success. Repeat on MySQL while the sweep runs. | The sweep recovers the result; both update sources converge on the same final status and single charge. |
| Inspect a delayed submission and a provider cooldown in the logs and database when reproducible. | The worker and sweep honor the same retry deadline. An uncertain submission is never assigned a new key or charged without verified usage. `401` pauses outbound calls; `409` needs review rather than a new submission. |
| Sign in as two users. Delete an unused account, then attempt deletion from an account with a generation. | No history leaks across users. The unused account can be deleted; the account with history stays signed in and retains its records. |
| Compare stored provider costs to the [usage report](https://pixelforge.promptedit.dev/docs#usage) for the same key and UTC completion dates. | Provider-only totals match. Each successful user charge is provider cost plus 25%, with no stored rounding. |

If the API key is misconfigured, restore the original key configuration and reload running processes before clearing the pause with `php artisan cache:forget pixelforge:auth-paused`. Do not switch pending requests to a different key or resend with a different idempotency key. Inspect the provider dashboard and resend the original signed event for a parked request when available.
