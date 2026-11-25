# Goldfish Annotated Review Report

**Target:** `design_draft.md` (DetailedRange lines 131–182)
**Source findings:** `implementation_readiness_report.md` (Stage 9, round 1)

## Resolution status

Applied to `design_draft.md` with your approval:

* **Issues 1–4, 6–10, 12, 13, 15, 18–23.**
* **Issue 5:** the server recomputes the estimate on submit.
* **Issue 11:** Laravel Cloud with MySQL.
* **Issue 16:** no change; PixelForge reads only the status code.

Stage 7 and Stage 6 FeatureRange fixes, also applied:

* design-1-1: removed "Rejected by author".
* design-1-2: added `## Risks and Limits`.
* design-1-4: Generate is disabled while a submission is processing.
* design-1-6: added the rate-lock sentence.
* style-1-1 / C-4: cited the assessment brief.
* style-1-7: exact polling interval.
* style-1-10: named the records.
* C-1, C-5, C-7, C-9: the Overview now names the starter kit, Wayfinder, `composer ci:check`, and PixelForge's role.

Contested or declined: Issues 14, 17, and 24, and style-1-2.

## Evidence used for resolution

The reviewers did not have these sources. I found the first one in the workspace and fetched the other two:

* `../PXI Pixelforge assessment architecture plan-964eb68b.txt`: your build plan. It links the provider docs and says to "keep it simple enough to explain every line".
* <https://pixelforge.promptedit.dev/docs>: the PixelForge API reference.
* <https://pixelforge.promptedit.dev/assessment>: the assessment brief. This is "the assessment" that line 105 refers to.

### Facts from the docs that change the design

1. **Idempotency keys never expire.** Replaying with the same key and body returns the existing generation. A row that has no provider ID can therefore always be replayed safely. This removes the need for the `acceptance-uncertain` flag and closes Stage 5 IC-1 and Stage 7 design-1-3 (see Issue 6).
2. **The account limit is 3 concurrent generations, across all users.** The plan allows 4 fast attempts (waits of 5, 15, and 30 s) and does not say what happens after the last one. Under load, requests would stall. The sweep replay in Issue 6 turns them into a queue.
3. **Every rejection creates and bills nothing.** This includes `429` and `503`. Uncertainty comes only from timeouts and connection errors.
4. **The docs recommend responding quickly and doing slow work in a queued job.** The design commits a short database transaction before replying. That is still within the 10-second window, so no change is proposed. Expect to be asked about this choice in the interview.

## Prioritized Findings & AI Suggestions

### [Issue 1] [`egm-implementation-readiness`] - Severity: Blocker
*   **Affected Scope:** [Lines 140–182](design_draft.md#L140-L182) (file table, Behavior, Verification)
*   **Discrepancy Description:** The plan assumes the PixelForge API contract but never provides it. Missing: the base URL, input limits, signature format, snapshot shape, error categories, pricing example, and test hooks.
*   **Rationale:** An implementer would have to invent field names and response schemas.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Confirmed against the live docs. This also closes ir-1-12 (base URL as env or config), Stage 6 C-2, and Stage 7 structural-1-4. Two details only the docs reveal: the request field is `reference_images`, and the `[blocked]` and `[overloaded]` keywords can test the rejection paths.
*   ***Proposed Edit:***
    *   Location: insert after line 133, before `### Target files to create or modify`.
    *   Change: insert:

        ```markdown
        ### Provider contract (PixelForge API)

        Source: the [PixelForge API reference](https://pixelforge.promptedit.dev/docs) and the [assessment brief](https://pixelforge.promptedit.dev/assessment). The plan depends on these facts:

        | Topic | Contract |
        | --- | --- |
        | Base URL and auth | `https://pixelforge.promptedit.dev/v1`, stored as a config default rather than an environment value. Send `Authorization: Bearer <key>` on create, retrieve, and usage requests. `GET /v1/pricing` needs no key. |
        | Create | `POST /v1/generations` takes `prompt` (at most 4,000 Unicode characters), `size`, `quality`, optional `reference_images` (at most 4 http(s) URLs), and `metadata` (string values). Unknown fields return `422`. Success returns `202` with the generation object. A replay with the same `Idempotency-Key` and body returns the existing generation with `Idempotent-Replayed: true` and does not count against the concurrency or daily limits. Keys never expire. Reusing a key with a different body returns `409 idempotency_key_reused`. |
        | Generation object | `id` (`gen_…`), `status`, `metadata`, `image_url`, `image_url_expires_at`, `usage`, `rates`, `error` (`code`, `message`), and the ISO 8601 UTC times `created_at`, `started_at`, and `completed_at`. `usage` is null until success, then holds `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`, and `total_tokens`. `rates` holds decimal strings in USD per 1M tokens for the same four keys and is fixed at creation. |
        | Errors | The body is `{"error": {"type", "code", "message"}}`. Retryable: `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded` (`Retry-After: 5`), `503 overloaded` (`Retry-After: 2`), timeouts, and connection errors. Final: `400 content_policy_violation`, `422 validation_failed`, `409 idempotency_key_reused`, and `429 daily_limit_exceeded`. `401 invalid_api_key` is a configuration error. A rejected request creates nothing and costs nothing. |
        | Limits | Per account: 60 requests per minute, 3 generations queued or processing at once, and 500 generations per UTC day. |
        | Webhooks | Events are `generation.processing`, `generation.succeeded`, `generation.failed`, and `ping`. The body is `{"id": "evt_…", "type", "data": {"object": <generation>}}`. `Pixelforge-Signature` has the form `t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, keyed with the key's `whsec_…` secret. Any `2xx` within 10 seconds counts as delivered. Otherwise PixelForge retries after 10 s, 30 s, 2 m, 10 m, and 15 m, for 6 attempts in total. Delivery is at least once and in any order, and can arrive before the create response. |
        | Images | `image_url` expires 24 hours after completion. |
        | Test keywords | `[blocked]` (400), `[overloaded]` (503), `[fail]`, `[succeed]`, `[slow]` (60–90 s), and `[duplicate]` (every event delivered twice). |
        ```

    *   Location: line 182.
    *   Change: replace "use the provider's `[succeed]`, `[fail]`, `[slow]`, and `[duplicate]` tests" with "use the provider's `[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, and `[overloaded]` keywords".

### [Issue 2] [`egm-implementation-readiness`] - Severity: Blocker
*   **Affected Scope:** [Lines 164, 172, 174](design_draft.md#L164-L174) (pricing test, Behavior steps 1 and 3)
*   **Discrepancy Description:** The four token counts, the rate fields, and the estimation constants are never named. The "documented pricing example" test cannot be written.
*   **Rationale:** The pricing code cannot be written exactly without field names and a worked example.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The docs give the `tokens` object and a worked example. I checked the numbers below by hand. This also closes Stage 6 C-3.
*   ***Proposed Edit:***
    *   Location: line 172.
    *   Change: replace "Read token rules from pricing. Estimate text with `ceil(character count / characters per token)` and round the variance bounds outward. Use the exact output and reference-image token counts and the quality's reasoning bounds. Apply `5/4` to each bound." with:

        > Read the token rules from `GET /v1/pricing`: `tokens.characters_per_text_token`, `tokens.text_token_variance`, `tokens.per_reference_image`, `tokens.output[quality][size]`, and `tokens.reasoning[quality].min|max`. Let `base = ceil(characters / characters_per_text_token)`. The text bounds are `floor(base × (1 − variance))` and `ceil(base × (1 + variance))`, computed without floats. Input image tokens are `per_reference_image × reference count`, and output tokens are exact. Price each bound at the current `rates` and apply `5/4`.
        >
        > Example at rates 5.00 / 10.00 / 40.00 / 12.50: `high`, `1536x1024`, a 400-character prompt, and 2 references give these token counts:
        >
        > * text: 85–115
        > * image: 1,530
        > * output: 6,450
        > * reasoning: 1,000–8,000
        >
        > The provider cost is `0.286225000`–`0.373875000`, so the displayed estimate is `$0.35778125`–`$0.46734375`.

    *   Location: line 174, after "Sum the four integer costs and apply `5/4` exactly."
    *   Change: insert "Example: the docs' succeeded generation (usage 12 / 765 / 6,450 / 3,184 at the rates above) costs `0.305510000`, which is `305510000000` picos. The user charge is `381887500000` picos (`$0.3818875`)."
    *   Location: line 164.
    *   Change: replace "Assert exact values for the documented pricing example, a fractional charge," with "Assert the estimate example in Behavior step 1 (`0.35778125`–`0.46734375`) and the charge example in step 3 (`381887500000` picos), a fractional charge,".

### [Issue 3] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 141–143](design_draft.md#L141-L143) (migrations)
*   **Discrepancy Description:** Column types, nullability, and index column lists are not specified.
*   **Rationale:** These are schema decisions. A narrow integer type for `*_picos` would overflow.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** This is valid, and more serious than reported. A 32-bit integer overflows at about 2.1×10⁹ picos, which is **$0.0021**. The text below also removes the `acceptance-uncertain` flag (Issue 6) and renames "safe failure code" (st-1-3).
*   ***Proposed Edit:***
    *   Location: line 141 (generations migration), "What changes and why" cell.
    *   Change: replace with:

        > Use `uuid('id')->primary()` (the model uses `HasUuids`) and these columns:
        >
        > * `foreignId('user_id')->constrained()`
        > * `string('provider_id')->nullable()->unique()`
        > * `string('key_label')`
        > * `text('prompt')`, `string('size')`, `string('quality')`, `json('reference_urls')`
        > * `string('status')`
        > * `unsignedBigInteger('estimated_min_picos')`, `unsignedBigInteger('estimated_max_picos')`, `unsignedBigInteger('provider_cost_picos')->nullable()`
        > * `json('usage')->nullable()`, `json('rates')->nullable()`
        > * nullable `timestamp` columns `accepted_at`, `completed_at`, `next_check_at`, and `image_expires_at`
        > * `text('image_url')->nullable()`
        > * `string('failure_code')->nullable()`: the provider error code, shown to users with a plain-language message
        > * `timestamps()`
        >
        > Add `index(['user_id', 'created_at'])` for history and `index(['status', 'next_check_at'])` for the sweep. Money columns are unsigned 64-bit because a 32-bit integer overflows at about $0.0021 in picos.

    *   Location: line 142 (credit transactions migration).
    *   Change: replace with:

        > Use `id()` and these columns:
        >
        > * `foreignId('user_id')->constrained()`
        > * `foreignUuid('generation_id')->nullable()->unique()->constrained()`
        > * `string('kind')` (only `charge` in Phase 1)
        > * `unsignedBigInteger('amount_picos')`
        > * `timestamp('created_at')`
        >
        > The unique generation link allows only one charge per generation. Because the link is nullable, Phase 2 purchase rows can exist without a generation.

    *   Location: line 143 (webhook events migration).
    *   Change: replace with "Use `id()`, `string('event_id')->unique()`, and `timestamp('accepted_at')`. Do not keep request bodies or signatures."

### [Issue 4] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 149, 174](design_draft.md#L149-L174) (`ApplyGenerationSnapshot`, Behavior step 3)
*   **Discrepancy Description:** "Apply only a forward change" has no transition table, and line 180 tests a rule that is never stated.
*   **Rationale:** The conditional-write predicate and the fields each transition writes are left to the implementer.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** This is valid. The table below matches the docs' lifecycle and the state machine in your build plan.
*   ***Proposed Edit:***
    *   Location: after line 174 (end of step 3).
    *   Change: insert:

        ```markdown
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
        ```

### [Issue 5] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 165, 172](design_draft.md#L165-L172) (stale-estimate rejection)
*   **Discrepancy Description:** The rejection rule is ambiguous. It does not say whether any difference counts, whether to compare strings or picos, or which error key and status to return.
*   **Rationale:** An implementer would have to choose the comparison and the error surface.
*   ***AI Suggestion:*** PROPOSE FIX (simplification). You need to decide this one.
*   ***Justification:*** Stage 5 ZO-3 and Stage 8 also questioned this rule. The assessment asks only to "show them what it will cost before they submit". Your build plan asks for code simple enough to explain every line. Recomputing on the server removes a failure path, a resubmission step, and a test case. The trade-off is that if rates change between the estimate and the submission, history stores the newer estimate. Rates fixed at creation still govern the final charge. If you want users to confirm the exact range, choose "Discuss" instead, and I will pin the rule as exact equality of both bounds in picos, with a validation error on `estimate`.
*   ***Proposed Edit:***
    *   Location: line 172.
    *   Change: replace "Pass the displayed bounds with the submission. If the server recomputes different bounds, return a form error and have the browser refresh the estimate for review." with "On submission, the server recomputes the bounds from the current price list and stores them. It does not accept bounds sent by the browser."
    *   Location: line 165.
    *   Change: delete "stale estimates, ".

### [Issue 6] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 141, 150, 151, 167, 173, 175](design_draft.md#L141-L175). The fix also requires a matching edit at [line 88](design_draft.md#L88), which is outside DetailedRange.
*   **Discrepancy Description:** The lifecycle of the `acceptance-uncertain` flag is only half specified. This connects to Stage 5 IC-1 and Stage 7 design-1-3: a request with no provider ID after the retries run out never reaches a final state.
*   **Rationale:** The plan does not say when the flag is set or cleared, or how the sweep treats it.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The docs say that idempotency keys never expire and that a replay returns the existing generation. A replay with the same key and body is therefore always safe, and the flag is not needed. One sweep rule covers all four of these cases:
    * a timeout
    * a crashed worker
    * an exhausted concurrency retry
    * a lost job

    A final rejection on a replay means nothing was created, because an accepted original would have been returned instead. The exception is `401`: if the key was revoked, an earlier attempt may have been accepted, so leave the row pending.
*   ***Proposed Edit:***
    *   Location: line 150 (`SubmitGeneration`).
    *   Change: replace the cell with "Load the request and stop if it is final or already has a provider ID. Send the unchanged payload with its UUID as `Idempotency-Key`, and pass a `202` response to the updater. Handle retryable and final results as described in Behavior step 2."
    *   Location: line 151 (`PollOverdueGenerations`).
    *   Change: replace the cell with "Read up to five unfinished rows whose `next_check_at` is due, oldest first. For a row with a provider ID, call `GET /v1/generations/{id}`. For a row without one, replay `POST /v1/generations` with the stored key and payload. Pass each result to the updater. Stop the batch on a `429` and defer the next check until `Retry-After` allows."
    *   Location: line 173 (step 2).
    *   Change: replace the step with:

        > 2. **Persist and submit once to the provider.** Save the request as `queued` with `next_check_at` 60 seconds ahead, and use its UUID for `metadata.local_generation_id` and `Idempotency-Key`. `SubmitGeneration` implements `ShouldQueueAfterCommit` and sets `$tries = 4` and `$timeout = 45`.
        >
        > * **Retryable result** (see Provider contract): call `$this->release(max([5, 15, 30][attempt − 1], Retry-After))`. On the fourth attempt, return without releasing and leave the row to the sweep.
        > * **Final rejection:** mark the row `failed` with the provider code and no charge.
        > * **`401 invalid_api_key`:** log it and leave the row to the sweep, because an earlier attempt may have been accepted.
        >
        > A replay with the same key and payload never creates a second generation, so a row without a provider ID is always safe to replay, and a timeout never marks it failed. The same path also queues requests that hit the limit of three concurrent generations per account.

    *   Location: line 175 (step 4).
    *   Change: replace "Set the first status check for 60 seconds after provider acceptance." with "Advance `next_check_at` after each check." Replace "with the sweep lock lasting at least 60 seconds" with "with `WithoutOverlapping('pixelforge-sweep')->expireAfter(60)` as the only overlap guard".
    *   Location: line 167 (jobs test).
    *   Change: replace "provider rejection versus uncertain acceptance" with "final rejection, a timeout followed by a replay that returns the existing generation, a sweep replay of a row without a provider ID, a `401` that leaves the row pending".
    *   Location: line 88. This is in FeatureRange, but the change is needed for consistency.
    *   Change: replace "sweep a bounded batch of unfinished generations with a provider ID accepted at least 60 seconds ago" with "sweep a bounded batch of unfinished generations that are due for a check, starting 60 seconds after submission. Retrieve those that have a provider ID, and replay creation with the original key for those that do not".
    *   Location: line 141. The flag removal is already covered by Issue 3.

### [Issue 7] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 150, 173, 175](design_draft.md#L150-L175)
*   **Discrepancy Description:** The retry mechanism for `SubmitGeneration` is not chosen.
*   **Rationale:** Laravel's `$tries`/`backoff`, `release()`, and `retryUntil` behave differently.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Resolved by the step 2 rewrite in Issue 6: `$tries = 4`, `release(max(backoff, Retry-After))`, and `$timeout = 45`, which is below the 90 s `retry_after` (`config/queue.php:43`).
*   ***Proposed Edit:*** Covered by Issue 6.

### [Issue 8] [`egm-implementation-readiness`, `egm-codebase-details`] - Severity: Major
*   **Affected Scope:** [Lines 153, 155, 160, 161](design_draft.md#L153-L161)
*   **Discrepancy Description:** The history page props, partial-reload keys, and row shape are not pinned. The Inertia APIs (`useForm`, `usePoll`) are not named. This also covers ir-1-13 (the route-to-method mapping).
*   **Rationale:** The implementer would be designing the page-prop API.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** This is valid. `usePoll(interval, reloadOptions, { autoStart })` exists in `@inertiajs/vue3` (`types/usePoll.d.ts:3`; `@inertiajs/core/types/types.d.ts:496`). Disabling Generate while the form is processing also closes Stage 7 design-1-4 with the small fix Stage 8 recommended.
*   ***Proposed Edit:***
    *   Location: line 153 (`GenerationController`).
    *   Change: replace the cell with:

        > * `create` and `index` use `Inertia::render`.
        > * `estimate` returns JSON `{estimated_min_usd, estimated_max_usd}`.
        > * `store` recalculates the estimate, saves, and redirects to `generations.index`.
        > * `index` passes one prop, `generations`: the user's rows newest first, using `simplePaginate(20)`. Each row has these fields:
        >   * `id`, `prompt`, `size`, `quality`, `reference_count`, `status`
        >   * `estimated_min_usd`, `estimated_max_usd`
        >   * `charge_usd`: null until final, `"0.00"` for failed
        >   * `failure_message`
        >   * `image_url`: null once expired
        >   * `image_expired`
        >   * `is_delayed`: unfinished after 2 minutes
        >   * `created_at`

    *   Location: line 155.
    *   Change: append "Map them to `GenerationController@create`, `@estimate`, `@store`, and `@index`."
    *   Location: line 160 (`Create.vue`).
    *   Change: replace "Use Inertia's HTTP helper for a debounced estimate and its form helper for submission." with "Use `useHttp` for the debounced estimate and `useForm` for submission. Disable Generate while `form.processing` is true or while no valid estimate is shown."
    *   Location: line 161 (`Index.vue`).
    *   Change: replace "Use Inertia partial polling and only run it when an active row is visible." with "Use `usePoll(5000, { only: ['generations'] }, { autoStart: false })`. Start it while any row is `queued` or `processing`, and stop it otherwise."

### [Issue 9] [`egm-implementation-readiness`, `egm-codebase-details`] - Severity: Major
*   **Affected Scope:** [Lines 156–157](design_draft.md#L156-L157)
*   **Discrepancy Description:** The webhook route's middleware is not specified, and "the routing callback" is not named.
*   **Rationale:** Leaving it vague invites registering the route inside the `web` group by mistake.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** This is valid. The docs allow registering the route outside the `web` group or exempting it from CSRF. The plan already chose the first option.
*   ***Proposed Edit:***
    *   Location: line 157.
    *   Change: replace "Load the dedicated webhook route with the routing callback." with "Add `then: fn () => Route::name('webhooks.')->group(base_path('routes/webhooks.php'))` to `withRouting`. Routes loaded this way get no session, CSRF, auth, or throttle middleware."
    *   Location: line 156.
    *   Change: append "Name it `pixelforge`, so the full route name is `webhooks.pixelforge`."

### [Issue 10] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 164–167, 180](design_draft.md#L164-L180)
*   **Discrepancy Description:** The tests list topics but not cases with assertions. Pest or PHPUnit is not stated.
*   **Rationale:** The standard requires specific cases with expected outcomes.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** This is valid. The suite uses Pest, and existing feature tests add `RefreshDatabase` per file (for example `tests/Feature/DashboardTest.php:5`) because it is commented out in `tests/Pest.php:18`. I am adding the expected outcomes for the money and ordering cases only. Each test row would otherwise become a spec of its own.
*   ***Proposed Edit:***
    *   Location: line 180.
    *   Change: append:

        > Write the four files as Pest tests with `uses(RefreshDatabase::class)`, as the existing feature tests do. Expected outcomes:
        >
        > * A duplicate event leaves one event row and one charge.
        > * `processing` after `succeeded` leaves `succeeded`.
        > * A success webhook before the create response leaves `succeeded`, and the later create snapshot changes nothing.
        > * A failed transaction returns `500` and stores no event ID; the retry returns `204` with one charge.
        > * Webhook-then-poll and poll-then-webhook both end with one charge of the same amount.

### [Issue 11] [`egm-implementation-readiness`] - Severity: Major
*   **Affected Scope:** [Lines 176, 180](design_draft.md#L176-L180)
*   **Discrepancy Description:** The deployment database type is undecided, but the concurrency check depends on it.
*   **Rationale:** Conditional-write behavior differs between database engines.
*   ***AI Suggestion:*** ESCALATE
*   ***Justification:*** The finding is valid, but answering it needs your decision, not a document fact. `.env.example:23` uses SQLite. Your build plan says "MySQL or Postgres" and mentions Laravel Cloud. Tell me the host and engine, and I will add one line to step 5. For example: "Laravel Cloud with MySQL 8, database cache and queue."
*   ***Proposed Edit:***
    *   Location: line 176.
    *   Change: pending your answer.

### [Issue 12] [`egm-codebase-details`] - Severity: Major
*   **Affected Scope:** [Lines 135–168](design_draft.md#L135-L168) (file table)
*   **Discrepancy Description:** The file table leaves out factories, the fixture and signing helper, and the Wayfinder-generated route helpers.
*   **Rationale:** The file table is meant to be an exact list of every file the work touches.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** This is valid for the factory and the signing helper. The Wayfinder output (`resources/js/routes`, `resources/js/actions`) is generated by the Vite plugin (`vite.config.ts:30`) and listed under lint ignores (`vite.config.ts:52–55`). It should be described, not listed as edited files.
*   ***Proposed Edit:***
    *   Location: after line 163.
    *   Change: insert two rows:
        * `| ./database/factories/GenerationFactory.php | Create | Provide a state for each status and a succeeded state with usage and rates for the tests. |`
        * `| ./tests/Pest.php | Modify | Add a pixelforgeSignature(string $body, int $timestamp) helper that signs fixtures with the test secret. Leave the global RefreshDatabase line commented. |`
    *   Location: line 162 (`AppSidebar.vue`).
    *   Change: append "The Wayfinder Vite plugin regenerates `resources/js/routes` and `resources/js/actions`. Do not edit those files."

### [Issue 13] [`egm-style`] - Severity: Major
*   **Affected Scope:** [Line 172](design_draft.md#L172)
*   **Discrepancy Description:** "Debounce estimates by about 300 milliseconds" cannot be tested.
*   **Rationale:** Numbers in a plan should be exact.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The fix is a single word.
*   ***Proposed Edit:***
    *   Location: line 172.
    *   Change: replace "about 300 milliseconds" with "300 milliseconds".

### [Issue 14] [`egm-codebase-details`] - Severity: Major
*   **Affected Scope:** [Lines 160, 172](design_draft.md#L160-L172)
*   **Discrepancy Description:** The reviewer said "Inertia's HTTP helper" cannot call a JSON endpoint, so the implementer must use `fetch`, add axios, or restructure.
*   **Rationale:** According to the reviewer, Inertia visits require Inertia responses.
*   ***AI Suggestion:*** CONTEST
*   ***Justification:*** Inertia v3 ships `useHttp` for plain HTTP/JSON requests. `node_modules/@inertiajs/vue3/types/index.d.ts:12` exports it, and the overloads are in `types/useHttp.d.ts:59–63`. The plan's approach works. Issue 8 adds the missing API name.
*   ***Proposed Edit:*** None beyond Issue 8.

### [Issue 15] [`egm-codebase-details`] - Severity: Nit
*   **Affected Scope:** [Lines 150, 153](design_draft.md#L150-L153)
*   **Discrepancy Description:** The plan does not say how "dispatch after commit" happens. `config/queue.php:44` sets `after_commit => false`.
*   **Rationale:** Without it, a job from a rolled-back write could run.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` exists in the installed framework.
*   ***Proposed Edit:*** Covered by the step 2 rewrite in Issue 6 (`SubmitGeneration` implements `ShouldQueueAfterCommit`).

### [Issue 16] [`egm-codebase-details`] - Severity: Nit
*   **Affected Scope:** [Lines 154, 174](design_draft.md#L154-L174)
*   **Discrepancy Description:** Webhook errors may be rendered as HTML, because JSON is only used for `api/*` or `expectsJson()` (`bootstrap/app.php:27–29`).
*   **Rationale:** The reviewer expected a defined error response.
*   ***AI Suggestion:*** ESCALATE
*   ***Justification:*** The finding's premise is inaccurate, but the evidence is in the provider docs, which the reviewer did not have. PixelForge reads only the status code: "Any `2xx` response within 10 seconds counts as delivered. Anything else … is retried." The body format does not matter. Confirm and I will leave the plan unchanged.
*   ***Proposed Edit:*** None.

### [Issue 17] [`egm-codebase-details`] - Severity: Nit
*   **Affected Scope:** [Line 139](design_draft.md#L139)
*   **Discrepancy Description:** `.env` is not listed next to `.env.example`.
*   ***AI Suggestion:*** DECLINE
*   ***Justification:*** `.env` is local, holds secrets, and is ignored by git. It does not belong in the file table. The README row (line 168) covers local setup.

### [Issue 18] [`egm-implementation-readiness`] - Severity: Nit
*   **Affected Scope:** [Lines 139–141](design_draft.md#L139-L141)
*   **Discrepancy Description:** The purpose of the key label is only implied.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** One clause defines it. This also closes Stage 6 C-6.
*   ***Proposed Edit:***
    *   Location: line 139.
    *   Change: replace "and a `PIXELFORGE_KEY_LABEL` entry" with "and a `PIXELFORGE_KEY_LABEL` entry (for example `production`). Each generation stores it so that its cost can be matched to that key's `GET /v1/usage` report".

### [Issue 19] [`egm-implementation-readiness`] - Severity: Nit
*   **Affected Scope:** [Lines 151, 158](design_draft.md#L151-L158)
*   **Discrepancy Description:** The scheduler API is not named, and two overlap guards are mentioned.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** `withoutOverlapping()` on a scheduled job only guards the dispatch. The job middleware guards the run itself, so it should be the only guard.
*   ***Proposed Edit:***
    *   Location: line 158.
    *   Change: replace "Schedule the recovery sweep every 30 seconds." with "Add `Schedule::job(new PollOverdueGenerations)->everyThirtySeconds()`. The job's `WithoutOverlapping` middleware is the only overlap guard."

### [Issue 20] [`egm-implementation-readiness`] - Severity: Nit
*   **Affected Scope:** [Line 163](design_draft.md#L163)
*   **Discrepancy Description:** "Replace the starter placeholders" is loose.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:***
    *   Location: line 163.
    *   Change: replace "Replace the starter placeholders" with "Replace the `PlaceholderPattern` blocks".

### [Issue 21] [`egm-style`] - Severity: Nit
*   **Affected Scope:** [Line 182](design_draft.md#L182)
*   **Discrepancy Description:** "A few real simulator results" cannot be counted.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The assessment says "Leave a few generations". A count that includes a failure lets reviewers compare costs and zero charges.
*   ***Proposed Edit:***
    *   Location: line 182.
    *   Change: replace "leave a few real simulator results" with "leave at least three generations created through the deployed app, including one failure".

### [Issue 22] [`egm-style`] - Severity: Nit
*   **Affected Scope:** [Line 141](design_draft.md#L141)
*   **Discrepancy Description:** "Safe failure code" does not say what makes it safe.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:*** Covered by Issue 3 (`failure_code`: the provider error code, shown to users with a plain-language message).

### [Issue 23] [`egm-style`] - Severity: Nit
*   **Affected Scope:** [Line 151](design_draft.md#L151)
*   **Discrepancy Description:** "Space out retries" is vague compared with line 175.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:*** Covered by Issue 6 (the line 151 rewrite uses the `Retry-After` wording).

### [Issue 24] [`egm-style`] - Severity: Nit
*   **Affected Scope:** [Lines 160, 181, 182](design_draft.md#L160-L182)
*   **Discrepancy Description:** The reviewer flagged "actionable errors" as filler and suggested dropping two sentences as defensive: "No image-generation tool…" (181) and "A comparison during verification does not add a reporting feature…" (182).
*   ***AI Suggestion:*** DECLINE
*   ***Justification:*** These are Nits. The line 182 sentence keeps the Phase 1 scope boundary in view for whoever does the deployment.

## Not in this resolution's scope

Stage 7 FeatureRange findings (lines 1–130) remain open because you chose to proceed at the Stage 7 gate:

* design-1-1: "Rejected by author"
* design-1-2: no Risks & Limits section
* style-1-1 / C-4: "the assessment"
* the Stage 6 Blockers C-1 (the starter is never named) and C-2 (Overview link)

Issue 1 adds the assessment link inside DetailedRange. Reply with a custom instruction if you want these FeatureRange fixes applied in the same batch.
