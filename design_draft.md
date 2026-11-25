# PixelForge: Image generation and usage charges

* **Author:** mezoo_adel
* **Status:** Draft
* **Date:** 2026-11-25

---

## Overview

Add image generation to the existing Laravel, Vue, and Inertia app. Signed-in users write a prompt, choose a size and quality, and can add reference image links. They see an estimated price in US dollars before they submit. Their history shows the request's progress, then the image and final charge or a failure reason and no charge. PixelForge is an external, simulated image-generation provider that the app calls over HTTPS ([API reference](https://pixelforge.promptedit.dev/docs)); no real money moves. The requirements come from the [assessment brief](https://pixelforge.promptedit.dev/assessment).

The app is built on the official Laravel Vue starter kit (`laravel/vue-starter-kit`). The plan relies on its Fortify sign-in with email verification, Inertia v3 with Vue, Wayfinder (typed route helpers generated from Laravel routes), and the sidebar app layout.

## The Problem

Users need enough price information to decide whether to generate an image, but PixelForge cannot report all the token counts until the job finishes. A fixed price shown up front would hide that difference. During the wait, users also need to know whether their request is queued, running, finished, or failed without submitting it again to find out.

PixelForge can send an update early, late, more than once, or not at all. The order and delivery of those messages must not determine whether the user sees their result or gets charged twice.

## Goals

* Let signed-in users submit a prompt, select size and quality, and optionally supply up to four reference image links. Show a clearly labeled estimated US-dollar range, including the 25% markup, before they submit.
* Keep each user's history private and current without a manual refresh. Distinguish queued, processing, succeeded, and failed requests. Show the image while its link is valid, and keep the status and cost visible after it expires.
* Record the exact provider cost and charge the user that cost plus 25% exactly once per successful generation. Charge nothing for a failed generation. Keep enough detail to explain the charge later.
* Reach the same final state and charge when provider updates are early, late, duplicated, or missing. Handle provider limits and temporary errors without creating a second generation for the same request.
* Verify the flow on a public deployment and leave examples created through the deployed app.

## Non-Goals

* Admin reporting, credit balances, test credit purchases, and blocking requests for insufficient funds belong to Phase 2. They start only after the Phase 1 flow is verified.
* Phase 1 does not include file uploads, permanent image storage, WebSockets, or a second provider.
* Neither phase processes real payments.

## Technical Plan (Architecture)

Reuse the starter's sign-in, Inertia layout, and Laravel queue. The browser calls only our app. A single PixelForge client sends provider requests, a pricing service calculates estimates and charges, and one update service handles provider snapshots from every source.

```text
Generation form -> App: validate input and calculate estimate
                -> App: save queued generation and dispatch after commit
                   -> Queue job -> PixelForge: create with stable key and local ID

Create response ----+
Signed webhook -----+-> Shared update service -> Generation and charge records
Server recovery ----+                         -> Event ID if from a webhook

History page -> App: refresh user's active rows and read saved results
```

### Routes and generation submission

| Route | Purpose |
| --- | --- |
| `GET /generations/create` | Show the generation form. |
| `POST /generations/estimate` | Validate the inputs and return a price range, without creating a generation. |
| `POST /generations` | Save a queued request with its estimate, dispatch the submission job, and send the user to history. |
| `GET /generations` | Show the signed-in user's history and return updated rows for an Inertia refresh. |
| `POST /webhooks/pixelforge` | Receive signed provider events without requiring a browser session. |

The four user routes use the starter's authenticated route group. History queries are scoped to the signed-in user. Validate the prompt, size, quality, and up to four HTTP(S) reference URLs on the server. Forward reference URLs without fetching them. The active app layout adds links for generating and history; the starter's sign-in is unchanged.

Save the local generation and its inputs before dispatching the job. The job sends `POST /v1/generations` with `metadata.local_generation_id` and a stable `Idempotency-Key` for that local row. Retrying uses the same key and unchanged payload. Apply the returned provider snapshot through the shared update service: it can already be final on a replay, or older than a webhook that arrived first.

### Pricing and stored records

Calculate the estimate on the server using `GET /v1/pricing`. The exact output and reference-image counts are known from the settings. Use the published text and reasoning ranges for the unknown counts. Apply the 25% markup to both ends, label the result as an estimate, and keep it next to Generate. Recalculate from the input on the server, and do not submit when pricing is unavailable. No provider call is needed for each keystroke: reuse the current price list for estimates and refresh it when it expires. PixelForge fixes the rates when it accepts a request, so the final charge uses the rates returned with the generation, not the current price list.

On success, calculate provider cost using each of the four final token counts and its locked rate in US dollars per million tokens. Multiply the provider cost by `5/4` for the user charge. Use exact arithmetic and store whole trillionths of a US dollar, with no rounding before storage. Format the amounts for the UI without displaying a nonzero charge as zero.

| Records | What they retain |
| --- | --- |
| Generations | User, immutable request inputs and estimate bounds, local and provider IDs, status, final token counts and rates, exact provider cost, provider completion time in UTC, image URL and expiry, and failure reason where applicable. Keep a non-secret key label for later reconciliation. |
| Ledger entries | One charge for each successful generation, linked to its user and generation, with the exact amount. A unique generation link prevents a second charge. Failures have no charge entry. |
| Webhook events | A unique provider event ID. Saving the ID and applying its effect are part of the same database transaction. |

Use a string-backed PHP `GenerationStatus` enum and a normal string column cast to that enum. The states are `queued`, `processing`, `succeeded`, and `failed`. Allow a queued request to finish directly because it can receive a final snapshot before a processing snapshot. Never move a final state backward.

### Webhooks: process and commit before replying

Return `503` without reading or writing event data if the signing secret is unset or blank. Otherwise, verify the HMAC signature on the raw request body and check the signed timestamp before trusting any JSON. For a generation event, look up the exact local row using `data.object.metadata.local_generation_id`. Check `data.object.id` against the stored provider ID or bind it if still unset. Handle signed `ping` events without a generation lookup. Do not scan pending requests from the webhook route.

In a short database transaction, deduplicate the event by ID, apply the update, and write the charge if the request has just succeeded. Use a conditional state write so competing snapshots cannot both finish the same generation. Leave the uniqueness constraint on the ledger in place as an independent safeguard. Commit the state and charge together; a duplicate or stale event returns success without changing them. Do not call PixelForge inside the transaction. If a success snapshot cannot yet be priced, keep the request eligible for recovery instead of recording a partial charge.

Return `2xx` after the transaction commits, within PixelForge's 10-second response window. A failed transaction does not record the event or return success, so the provider can retry. If the commit succeeds but the reply is lost, the saved event ID makes the retry harmless.

### Recovery, history, and rollout

* **Submission recovery:** Respect `Retry-After` and bound retries for temporary errors. Treat exhausted daily quota differently from a short rate limit. If a timeout leaves acceptance unknown, replay with the original key; do not mark an ambiguous result failed merely because no response arrived.
* **Server polling:** Every 30 seconds, sweep up to five unfinished generations that are due. Use `GET /v1/generations/{id}` for known provider IDs, starting 60 seconds after acceptance. For rows without a provider ID, use the same submission action as the initial job; do not schedule independent queue retries. Pass provider snapshots through the same update service. Skip finished and parked requests.
* **Browser polling:** Refresh displayed active, non-parked rows every 5 seconds until none remain. Show a delayed message while checking and an explicit confirmation-unavailable message for parked rows, without claiming the generation failed or promising no charge. Permit manual refresh. After an image expires, retain status and cost.
* **Public deployment:** Run the web app, queue worker, and scheduler. Configure verification mail and create a verified evaluator account with sample generations; share access privately. Use separate PixelForge credentials per environment. Log failure codes and IDs, not prompts, signed URLs, or credentials. Follow the manual checks in the README before beginning Phase 2.
* **Phase 2 boundary:** Reuse the generation and ledger records for daily reporting and reconcile provider costs by key and UTC completion day. Add test credit purchases and the funds check only after core verification. Reserve credits before admitting concurrent requests, settle on success, and release on failure; Phase 1 has no balance check or reservations.

## Risks and Limits

* **Shared provider limits:** One PixelForge account serves all users, with 60 requests per minute, 3 generations in progress at once, and 500 generations per UTC day. Requests above the concurrency limit wait in our queue. After the daily limit, new requests fail with no charge until the next UTC day.
* **No per-user spending cap in Phase 1:** Any verified user can start billable generations. Generate is disabled while a submission is in flight, so a double click does not create two requests, but two deliberate submissions are two generations and two charges. Phase 2's funds check is the spending control.
* **Estimates are not binding:** The final charge follows actual usage and is not capped by the displayed range.
* **Clock skew:** Webhooks signed more than 300 seconds before or after our server time are rejected, so the server clock must stay synchronized. PixelForge retries rejected deliveries, and the recovery sweep catches any that never succeed.
* **Image retention:** PixelForge image links expire 24 hours after completion, and Phase 1 does not copy images. Status and cost stay visible after expiry.
* **Account deletion:** Accounts with generation or charge history cannot be deleted through self-service in Phase 1, including accounts with queued or failed generations. Keep those records for reconciliation and explain the restriction without signing the user out. Unused accounts can still be deleted.
* **Polling load:** History stops automatically refreshing once all visible rows are finished or parked for review.

## Alternatives Considered

### Show a fixed warning of 5%–20% above the estimate

* **Pros:** Short to explain and easy to display.
* **Cons:** The final charge can also be lower, and the difference depends on the settings and actual token use.
* **Reason for rejection:** PixelForge does not promise that percentage range. Calculate the estimate from the selected settings instead.

### Charge the estimate or cap the charge at the estimated maximum

* **Pros:** The user would know the charge or its ceiling before submitting.
* **Cons:** The amount can differ from the provider's final cost plus the required markup.
* **Reason for rejection:** The estimate helps users decide whether to submit. The assessment brief requires the final charge to equal the actual provider cost plus 25%.

### Store money in integer millionths of a dollar

* **Pros:** Integer arithmetic avoids floating-point error, and amounts are easy to convert to dollars.
* **Cons:** Some provider amounts and marked-up charges cannot be represented exactly at that scale.
* **Reason for rejection:** Rounding before storage would lose information needed for exact charges and eventual reconciliation. Use integer trillionths of a dollar instead.

### Rely on one provider update channel

* **Pros:** Webhooks alone avoid routine status requests. Polling alone avoids an inbound event handler.
* **Cons:** With webhooks alone, a missed event can leave a generation unfinished in our app. With polling alone, every status update waits for a request that uses the shared API quota, and the assessment brief requires webhook handling.
* **Reason for rejection:** Use signed webhooks for timely updates and poll only unfinished, overdue requests as a fallback.

### Save signed webhook events, reply, and process them in the background

* **Pros:** The response does not wait for the status and charge update. This gives the app more room if event processing becomes slow.
* **Cons:** After a successful reply, PixelForge stops retrying. The app must find and process saved events even if the worker stops or the job is never queued.
* **Reason for rejection:** Phase 1 only needs a short, local database transaction for a webhook. Process it and commit before replying, so a transient write failure can still trigger provider retry. Keep external calls off this path.

### Build a wallet, payment integration, or provider framework first

* **Pros:** These could support prepaid balances, real purchases, or multiple providers earlier.
* **Cons:** Each adds behavior and failure modes unrelated to running one provider and recording charges.
* **Reason for rejection:** Phase 1 needs a per-generation charge record, not a wallet or provider framework. Phase 2 can reuse the ledger for test credits and reporting. Neither phase needs real payments or another provider.

## Detailed Implementation Plan

Build the pricing, webhook, and submission logic first with their backend tests, then connect the form and history, then verify the rest manually. This plan covers Phase 1 only. No starter authentication replacement, dependencies, or queue driver are required.

### Provider contract (PixelForge API)

Source: the [PixelForge API reference](https://pixelforge.promptedit.dev/docs) and the [assessment brief](https://pixelforge.promptedit.dev/assessment). The plan depends on these facts:

| Topic | Contract |
| --- | --- |
| Base URL and auth | `https://pixelforge.promptedit.dev/v1`, stored as a config default rather than an environment value. Send `Authorization: Bearer <key>` on create, retrieve, and usage requests. `GET /v1/pricing` needs no key. |
| Create | `POST /v1/generations` takes `prompt` (at most 4,000 Unicode characters), `size`, `quality`, optional `reference_images` (at most 4 http(s) URLs), and `metadata` (string values). Unknown fields return `422`. Success returns `202` with the generation object. A replay with the same `Idempotency-Key` and body returns the existing generation with `Idempotent-Replayed: true` and does not count against the concurrency or daily limits. Keys never expire. Reusing a key with a different body returns `409 idempotency_key_reused`. |
| Generation object | `id` (`gen_…`), `status`, `metadata`, `image_url`, `image_url_expires_at`, `usage`, `rates`, `error` (`code`, `message`), and the ISO 8601 UTC times `created_at`, `started_at`, and `completed_at`. `usage` is null until success, then holds `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`, and `total_tokens`. `rates` holds decimal strings in USD per 1M tokens for the same four keys and is fixed at creation. |
| Errors | The body contains `error.type`, `error.code`, and `error.message`. Temporary: `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded` (`Retry-After: 5`), and `503 overloaded` (`Retry-After: 2`). Timeouts or connection errors can leave acceptance unknown. `400 content_policy_violation`, `422 validation_failed`, and `429 daily_limit_exceeded` reject the current attempt. `409 idempotency_key_reused` is a request/key conflict and `401 invalid_api_key` is a configuration error; neither resolves an earlier uncertain attempt. |
| Limits | Per account: 60 requests per minute, 3 generations queued or processing at once, and 500 generations per UTC day. |
| Webhooks | Events are `generation.processing`, `generation.succeeded`, `generation.failed`, and `ping`. The body is `{"id": "evt_…", "type", "data": {"object": <generation>}}`. `Pixelforge-Signature` has the form `t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, keyed with the key's `whsec_…` secret. Any `2xx` within 10 seconds counts as delivered. Otherwise PixelForge retries after 10 s, 30 s, 2 m, 10 m, and 15 m, for 6 attempts in total. Delivery is at least once and in any order, and can arrive before the create response. |
| Images | `image_url` expires 24 hours after completion. |
| Test keywords | `[blocked]` (400), `[overloaded]` (503), `[fail]`, `[succeed]`, `[slow]` (60–90 s), and `[duplicate]` (every event delivered twice). |

### Target files to create or modify

| File | Action | What changes and why |
| --- | --- | --- |
| `./.env.example` | Modify | Add empty `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` entries and a `PIXELFORGE_KEY_LABEL` entry (for example `production`). Each generation stores the label so that its cost can be matched to that key's `GET /v1/usage` report. Show what must be configured without including real credentials. |
| `./config/services.php` | Modify | Add a `pixelforge` config block with the documented `/v1` base URL, credentials, and non-secret key label. Read environment values in config, not in jobs or controllers. |
| `./database/migrations/2026_11_25_000001_create_generations_table.php` | Create | Use `uuid('id')->primary()` (the model uses `HasUuids` and `HasFactory`), `foreignId('user_id')->constrained()->restrictOnDelete()`, `string('provider_id')->nullable()->unique()`, `string('key_label')`, `text('prompt')`, `string('size')`, `string('quality')`, `json('reference_urls')`, `string('status')`, `unsignedBigInteger('estimated_min_picos')`, `unsignedBigInteger('estimated_max_picos')`, `unsignedBigInteger('provider_cost_picos')->nullable()`, `json('usage')->nullable()`, `json('rates')->nullable()`, nullable `timestamp` columns `accepted_at`, `completed_at`, `next_check_at`, and `image_expires_at`, `text('image_url')->nullable()`, `string('failure_code')->nullable()` (the provider error code, shown to users with a plain-language message), `unsignedSmallInteger('submit_attempts')->default(0)`, `boolean('acceptance_uncertain')->default(false)`, and `timestamps()`. For an unfinished row, a null `next_check_at` means automatic recovery has stopped and the request needs review. Add `index(['user_id', 'created_at'])` for history and `index(['status', 'next_check_at'])` for the sweep. Money columns are unsigned 64-bit because a 32-bit integer overflows at about $0.0021 in picos. |
| `./database/migrations/2026_11_25_000002_create_credit_transactions_table.php` | Create | Use `id()`, `foreignId('user_id')->constrained()->restrictOnDelete()`, `foreignUuid('generation_id')->nullable()->unique()->constrained()`, `string('kind')` (only `charge` in Phase 1), `unsignedBigInteger('amount_picos')`, and `timestamp('created_at')`. The unique generation link allows only one charge per generation; because it is nullable, Phase 2 purchase rows can exist without a generation. Phase 1 writes no purchase rows. |
| `./database/migrations/2026_11_25_000003_create_pixelforge_webhook_events_table.php` | Create | Use `id()`, `string('event_id')->unique()`, and `timestamp('accepted_at')`. Do not retain request bodies or signatures. |
| `./app/Enums/GenerationStatus.php` | Create | Define the four string-backed states and identify the two final states. This gives PHP code one source for the valid states without a database enum. |
| `./app/Models/Generation.php` | Create | Cast status, JSON, timestamps, and money fields; define the owner and charge relationships. Do not expose raw provider data as page props. |
| `./app/Models/CreditTransaction.php` | Create | Model the locally recorded charge and its user and generation links. A separate record lets Phase 2 use past charges without recalculating prices. |
| `./app/Services/PixelForge/PixelForgeClient.php` | Create | Use Laravel's HTTP client for pricing, creation, and status. Authenticate creation and status only. Cache the public price list for 60 seconds, set a 2-second connect timeout and 5-second total timeout per request, and expose provider error codes and `Retry-After` to callers. Do not retry internally. |
| `./app/Services/PixelForge/PixelForgePricing.php` | Create | Calculate estimate bounds and exact final amounts from supplied price or usage data. Format money as decimal strings without floats. Keep the math separate from HTTP calls for direct testing. |
| `./app/Services/PixelForge/ApplyGenerationSnapshot.php` | Create | Apply create, webhook, and polling snapshots through one transaction path. Deduplicate events, check provider binding, guard status writes against concurrent changes, and write the charge together with final success. |
| `./app/Services/PixelForge/SubmitGenerationAttempt.php` | Create | Run one eligible provider submission using the original UUID and payload. Persist the attempt count, uncertainty and next deadline on the generation. Run under `Cache::lock('pixelforge:generation:'.$id, 60)` and skip the row if the lock is held. Use this action from both provider jobs so retry decisions cannot diverge. |
| `./app/Jobs/SubmitGeneration.php` | Create | Run the first submission attempt after commit. Do not release the job for retries; the recovery sweep handles those from stored deadlines. |
| `./app/Jobs/PollOverdueGenerations.php` | Create | Read up to five due unfinished rows, oldest first. Retrieve rows with a provider ID; call the submission action for rows without one. Use `WithoutOverlapping('pixelforge-sweep')->expireAfter(60)`, take the same per-row lock as the submission action, and skip rows whose lock is held. |
| `./app/Http/Requests/GenerationInputRequest.php` | Create | Share prompt, size, quality, and HTTP(S) reference URL validation between estimate and submit. Validate against the documented limits rather than forwarding arbitrary fields. |
| `./app/Http/Controllers/GenerationController.php` | Create | `create` and `index` use `Inertia::render`. `estimate` returns JSON `{estimated_min_usd, estimated_max_usd}`. `store` recalculates the estimate, saves, and redirects to `generations.index`. `index` passes one prop, `generations`: the user's rows newest first via `simplePaginate(20)`, each with `id`, `prompt`, `size`, `quality`, `reference_count`, `status`, `estimated_min_usd`, `estimated_max_usd`, `charge_usd` (null until final, `"0.00"` for failed), `failure_message`, `image_url` (null once expired), `image_expired`, `is_delayed` (unfinished after 2 minutes), `needs_attention` (unfinished with no next check), and `created_at`. Do not return raw provider data. |
| `./app/Http/Controllers/PixelForgeWebhookController.php` | Create | Return `503` if no signing secret is configured. Otherwise verify the signature and timestamp on the untouched body and return `400` without any write if either check fails, validate the event shape, and dispatch to the synchronous updater. Return `204` after commit; return an error if local persistence fails. |
| `./app/Http/Controllers/Settings/ProfileController.php` | Modify | Refuse deletion with a profile error before logout if the user has generation or ledger history. Retain restrictive foreign keys as a backstop. |
| `./routes/web.php` | Modify | Register `generations.create`, `generations.estimate`, `generations.store`, and `generations.index` in the existing authenticated, verified group, mapped to `GenerationController@create`, `@estimate`, `@store`, and `@index`. Keep starter settings and authentication routes intact. |
| `./routes/webhooks.php` | Create | Register `POST /webhooks/pixelforge` outside the web middleware group. This route uses provider signatures rather than a browser session or CSRF token. Name it `pixelforge`, so the full route name is `webhooks.pixelforge`. |
| `./bootstrap/app.php` | Modify | Add `then: fn () => Route::name('webhooks.')->group(base_path('routes/webhooks.php'))` to `withRouting`. Routes loaded this way get no session, CSRF, auth, or throttle middleware. Do not exempt other web routes from CSRF protection. |
| `./routes/console.php` | Modify | Add `Schedule::job(new PollOverdueGenerations)->everyThirtySeconds()`. Queue the work; keep HTTP calls off the scheduler process. |
| `./resources/js/types/generation.ts` | Create | Type the form, estimate, and history rows. Keep money as server-formatted strings and distinguish a pending charge from a confirmed zero charge. |
| `./resources/js/pages/generations/Create.vue` | Create | Build the prompt and settings form using existing inputs and theme tokens. Use `useHttp` for the debounced estimate and `useForm` for submission. Disable Generate while `form.processing` is true or while no valid estimate is shown. Keep the estimate next to Generate, and show actionable errors without clearing the form. |
| `./resources/js/pages/generations/Index.vue` | Create | Show the private history with status, estimate, final charge, and image or expiry message. Use `usePoll(5000, { only: ['generations'] }, { autoStart: false })`. Poll only while a visible row is unfinished and not flagged `needs_attention`; surface confirmation unavailable for parked rows and allow manual refresh. |
| `./resources/js/components/AppSidebar.vue` | Modify | Add Generate and History links using named Wayfinder routes. Use the active starter sidebar rather than introducing a second navigation layout. The Wayfinder Vite plugin regenerates `resources/js/routes` and `resources/js/actions`; do not edit those files. |
| `./resources/js/pages/Dashboard.vue` | Modify | Replace the `PlaceholderPattern` blocks with links to Generate and History. Keep `/dashboard` as a working post-login entry. |
| `./database/factories/GenerationFactory.php` | Create | Provide a default `queued` row with a user, inputs, estimate bounds, and key label for the feature tests. |
| `./tests/Unit/PixelForgePricingTest.php` | Create | Assert the two worked examples in Behavior steps 1 and 3: estimate `0.35778125`–`0.46734375` and charge `381887500000` picos. Assert that a rate with more decimal places than can be stored exactly is rejected. |
| `./tests/Feature/PixelForgeWebhookTest.php` | Create | Use `uses(RefreshDatabase::class)` and sign fixtures inline with `hash_hmac`. Cover three cases: an invalid signature returns `400` with no writes; the same success event delivered twice, then a second success event with a new ID, leaves one charge; `generation.processing` after `generation.succeeded` leaves the row `succeeded`. |
| `./tests/Feature/SubmitGenerationAttemptTest.php` | Create | Use `uses(RefreshDatabase::class)` and `Http::fake`. Cover three cases: a connection failure, then a `202` replay with the same `Idempotency-Key`, binds the provider ID; `429 concurrency_limit_exceeded` leaves `submit_attempts` unchanged and the row due after `Retry-After`; a same-key `400 content_policy_violation` after an uncertain attempt marks the row `failed` with no charge. |
| `./README.md` | Update after implementation | Confirm the documented setup, usage, and manual verification commands against the running feature. Keep account secrets and signed URLs out of the file. |

### Behavior to implement

1. **Estimate and submit.** Validate a prompt of at most 4,000 Unicode characters, sizes `1024x1024`, `1536x1024`, or `1024x1536`, quality `low`, `medium`, or `high`, and at most four reference URLs. Read the token rules from `GET /v1/pricing`: `tokens.characters_per_text_token`, `tokens.text_token_variance`, `tokens.per_reference_image`, `tokens.output[quality][size]`, and `tokens.reasoning[quality].min|max`. Let `base = ceil(characters / characters_per_text_token)`. The text bounds are `floor(base × (1 − variance))` and `ceil(base × (1 + variance))`, computed without floats. Input image tokens are `per_reference_image × reference count`, and output tokens are exact. Price each bound at the current `rates` and apply `5/4`. Return `estimated_min_usd` and `estimated_max_usd` as decimal strings. Debounce estimates by 300 milliseconds and discard replies for older inputs. On submission, the server recomputes the bounds from the current price list and stores them. It does not accept bounds sent by the browser. Do not create or submit a generation if there is no valid estimate.

   Example at rates 5.00 / 10.00 / 40.00 / 12.50: `high`, `1536x1024`, a 400-character prompt, and 2 references give text 85–115, image 1,530, output 6,450, and reasoning 1,000–8,000 tokens. The provider cost is `0.286225000`–`0.373875000`, so the displayed estimate is `$0.35778125`–`$0.46734375`.
2. **Persist and submit.** Store the row as `queued` and due now. Dispatch the one-attempt job after commit; if the job is lost or skipped, the row remains due. The worker and the sweep share one submission action. It re-reads the row and only sends a due, unfinished request with no provider ID, using the original UUID as `metadata.local_generation_id` and `Idempotency-Key` and keeping the payload and configured key label unchanged. Park a row on key-label mismatch for operator review.

   * Before sending, durably increment `submit_attempts`, record possible acceptance, and set a 60-second recovery deadline so a crash does not lose the attempt. A rate-limit, concurrency-limit, or overload rejection restores the prior uncertainty; only the two `429` limits also restore the attempt count. A same-key `400`, `422`, or `daily_limit_exceeded` clears it, because an earlier accepted attempt would have been returned instead. A timeout, lost or unusable response leaves the outcome uncertain. A valid matching snapshot binds the provider ID and clears submission uncertainty.
   * `429 rate_limit_exceeded` and `429 concurrency_limit_exceeded` mean nothing was created: undo the increment and set `next_check_at` to at least `Retry-After` and the shared cooldown, so requests above the concurrency limit wait instead of failing. Overloads (`503`) and uncertain outcomes count toward a cap of four. Schedule counted retries at least 5, 15, and 30 seconds apart, honoring `Retry-After`; sweep cadence can make them later. At the cap, fail without charge if no attempt may have been accepted. If acceptance is still uncertain, keep replaying with the same key on the status-check backoff, up to five minutes apart; same-key replays do not count against the concurrency or daily limits. Park an uncertain row only after 24 hours without resolution: leave its status non-final, clear its next check time, record an internal reason, and still accept signed callbacks.
   * Treat a same-key `400 content_policy_violation`, `422 validation_failed`, or `429 daily_limit_exceeded` as final: mark the row `failed` with the provider code and no charge. On `409 idempotency_key_reused`, park and investigate instead of issuing a new key. On `401 invalid_api_key`, pause both jobs via shared cache key `pixelforge:auth-paused`; do not mark the generation failed. Follow the README to restore configuration and resume.
   * Guard local updates from a POST failure by requiring the row to still be unfinished and have no provider ID. If a signed webhook has already bound it, an older submission error must not change its state. Send a valid POST snapshot through the shared updater even if the provider ID was already bound.
3. **Verify, update, and settle.** Read `t` and `v1` from `Pixelforge-Signature`, check the Unix timestamp within 300 seconds, and compare `v1` in constant time to the hex HMAC-SHA256 of `"<t>.<raw body>"`, keyed with `PIXELFORGE_WEBHOOK_SECRET`. In one transaction, insert an event ID if present, bind or verify the provider ID, and apply only a forward change. A conflict on the event ID is a no-op. A lost conditional state write is retried against the current row or returned as a retryable local error; it must not leave a recorded event with an unapplied change. Keep a success with incomplete pricing data eligible for recovery and do not record a charge until the final four counts, rates, and UTC completion time are available. Parse each decimal rate in USD per million tokens into integer millionths of a dollar per million tokens; multiplying by that token count gives the cost in trillionths of a dollar. Sum the four integer costs and apply `5/4` exactly. Example: the docs' succeeded generation (usage 12 / 765 / 6,450 / 3,184 at the rates above) costs `0.305510000`, which is `305510000000` picos. The user charge is `381887500000` picos (`$0.3818875`). Reject invalid or unrepresentable provider pricing data rather than rounding it. Commit once, then return `204`; do not fetch anything from PixelForge in the webhook request.

   | Current status | Snapshot | Effect |
   | --- | --- | --- |
   | `queued` | `queued` | Bind the provider ID and `accepted_at` only. |
   | `queued` | `processing` | Set `processing` and bind the provider ID. |
   | `queued` or `processing` | `succeeded` with usage, rates, and `completed_at` | Set `succeeded`. Store the usage, rates, cost, image, and completion time, and insert the charge. |
   | `queued` or `processing` | `succeeded` with missing data | Bind the provider ID only and keep the row due for a check. |
   | `queued` or `processing` | `failed` | Set `failed` and store the error code and completion time. No charge. |
   | `processing` | `queued` or `processing` | No change. |
   | `succeeded` or `failed` | any | No change. Acknowledge the event and ignore it. |

   Write the new status with `UPDATE … WHERE id = ? AND status IN (<allowed current statuses>)`. If no row is affected, another snapshot won: re-read the row and apply this table to its new status.
4. **Recover and show progress.** Both provider jobs use a 45-second timeout, below the database queue's 90-second retry window. The sweep uses `WithoutOverlapping('pixelforge-sweep')->expireAfter(60)`, and each row's submission or status check runs under `Cache::lock('pixelforge:generation:'.$id, 60)`; a job that finds a row locked skips it, and the row stays due. Before any authenticated call, both jobs read `pixelforge:auth-paused` and `pixelforge:cooldown-until`, a Unix timestamp that rate-limit, concurrency-limit, and overload responses set to at least `Retry-After`. Stop the batch on such a response. Keep unauthenticated price lookups separate. Re-read each due row before calling the provider and advance its next status check after a response only if still unfinished. For known provider IDs, back off recurrent overdue checks up to five minutes. An unfinished row with no next check time exposes `needs_attention`; the UI shows confirmation unavailable and that no charge has been recorded yet. Do not poll parked rows; still allow late signed updates.
5. **Delete accounts safely.** Generation creation and profile deletion lock the same user row within their database transactions on MySQL. Before deleting, check for generation or ledger history and return a profile error without logging out if any exists. The foreign keys are a backstop. Users without history keep the starter's existing delete behavior.
6. **Run the deployed flow.** Deploy to Laravel Cloud with MySQL and a shared cache. Disable `APP_DEBUG`, configure a separate deployment key and signing secret and outbound verification mail, and run migrations, the worker, and the sub-minute scheduler. Register the public HTTPS webhook URL. Provision a verified evaluator account with the example generations and share access privately.

### Verification plan

* Run the three backend test files during implementation and `composer test` before release; the existing suite also covers the starter's account deletion, which `ProfileController` changes. Use the [README manual checklist](./README.md#manual-verification) for UI and deployed-flow checks; do not add frontend, browser, or end-to-end tests. Use `npm run build`, `npm run types:check`, and `composer lint:check` as separate build and lint checks.
* Check the layout on desktop and mobile. On disposable MySQL, deliver a callback while the sweep retrieves the same generation. Review one charge per successful generation, no charge for failures, and the exact provider totals for the key and UTC completion dates.
* Leave at least three sample generations for the verified evaluator account, including a failure. Confirm the evaluator can sign in and view them before handing over the deployment.
