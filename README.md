# PixelForge image generation

A Laravel, Vue, and Inertia app for the [PixelForge assessment](https://pixelforge.promptedit.dev/assessment). The feature instructions and checklist below describe Phase 1 after it is implemented. PixelForge uses simulated images and prices; [live Preview](https://task-ai-img-generation-production-7wrysp.laravel.cloud/).

## Run locally

Use PHP 8.3+, Composer 2, and Node 22. From this directory:

```sh
composer install
npm ci
```

For a fresh setup only, copy `.env.example` to `.env` if it does not exist, then run `php artisan key:generate`. Create `database/database.sqlite` if using SQLite and that file does not exist. Keep any existing app key and database.

```sh
php artisan migrate
composer dev
```

`composer dev` starts the web app, frontend, and queue listener. Open `http://localhost:8000`. Once the recovery task is implemented, run `php artisan schedule:work` in a separate terminal.

## Configure PixelForge

Once Phase 1 is implemented, put `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, and a non-secret `PIXELFORGE_KEY_LABEL` in local configuration. Use a separate key and signing secret in each environment. Never commit `.env`, keys, signing secrets, or signed image URLs.

In the PixelForge dashboard, set the webhook URL to `https://<public-host>/webhooks/pixelforge` and use **Send test event**. Local webhook delivery needs a public HTTPS tunnel; local polling does not. On the public deployment, run the web app, worker, and scheduler with a shared cache and persistent MySQL. Set `APP_DEBUG=false`, configure outbound verification mail, and create a verified evaluator account with sample generations. Share its access privately, not in the repository.

## Use the app

1. Sign in with a verified account. Open **Generate**, enter a prompt, pick a size and quality, and optionally add up to four reference image links.
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
