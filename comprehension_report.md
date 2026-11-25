# Stage 6: Comprehension Test — `design_draft.md`

* **Target:** `design_draft.md`. FeatureRange is lines 1–130 and DetailedRange is lines 131–182.
* **Reviewer:** `egm-comprehension`. It read the target document only: no GoldfishContext, no codebase, no document standard.
* **Failed Reviewers:** none

## Paraphrase — FeatureRange (lines 1–130)

**What this proposes**
Build a Phase 1 image-generation feature in an existing Laravel/Vue/Inertia app that talks to a simulated provider called PixelForge. Signed-in users submit a prompt, size, quality, and up to four reference-image URLs; the server shows a USD price estimate range (provider cost plus a 25% markup) before submission, tracks the request through queued/processing/succeeded/failed states, and records exactly one ledger charge equal to actual provider cost × 1.25 on success and nothing on failure. Provider updates arrive via three channels — the create response, signed webhooks, and a recovery polling sweep — all funneled through one shared update service so early, late, duplicate, or missing updates converge to the same final state. Phase 2 (balances, credit purchases, admin reporting) is explicitly deferred.

**How the existing system works, per this document**
The app is a starter project that already provides sign-in, an Inertia layout, an authenticated and verified route group, a Laravel queue, a sidebar, and a dashboard page; the plan reuses all of these without changes. PixelForge is an external simulator exposing `POST /v1/generations`, `GET /v1/pricing`, `GET /v1/generations/{id}`, and `GET /v1/usage`; it signs webhooks with HMAC and a timestamp, supports idempotency keys and `Retry-After` headers, can deliver events early/late/duplicated/not at all, and provides built-in `[succeed]`/`[fail]`/`[slow]`/`[duplicate]` test hooks. The web app updates its history via Inertia polling rather than WebSockets, and money is stored as integer trillionths of a dollar.

## Paraphrase — DetailedRange (lines 131–182)

**What this proposes**
A concrete file-by-file build list (env vars, three migrations, an enum, two models, three services, two jobs, a form request, two controllers, route/bootstrap/console wiring, two Vue pages, sidebar/dashboard edits, and four test files) followed by five numbered behavior specifications. These pin down: input limits and the estimate formula (`ceil(chars/chars-per-token)`, reasoning bounds, ×5/4); a four-attempt submission policy with fixed backoffs and an uncertainty flag; webhook signature verification (`t`/`v1`, 300-second window, constant-time compare) and a single commit-before-reply transaction that deduplicates events and writes the charge only when complete pricing data exists; a 30-second recovery sweep over at most five due rows with backoff and a cache lock; and deployment/verification steps including UI checks and provider test hooks. Verification requires `composer ci:check`, `npm run build`, concurrency checks on the deployment database type, and reconciliation against `GET /v1/usage`.

**How the existing system works, per this document**
The starter already supplies authentication, verified route middleware, CSRF protection, a queue driver with a 90-second retry window, a scheduler that supports sub-minute tasks, existing input components and theme tokens, and a sidebar — none of which need replacement. Tests fake provider HTTP; the webhook route is registered outside the web middleware group because it relies on provider signatures rather than session/CSRF.

## Findings

| # | Severity | Finding | Lines |
| --- | --- | --- | --- |
| C-1 | 🔴 Blocker | "The starter" is never identified | 35, 59, 133, 155, 162, 163 |
| C-2 | 🔴 Blocker | The PixelForge API contract is assumed but never linked or summarized | 61, 65, 79, 87, 140, 152, 164, 172–175, 182 |
| C-3 | 🟡 Major | The four token counts are never listed | 67, 149, 174 |
| C-4 | 🟡 Major | "The assessment" is an unidentified requirements source | 105 |
| C-5 | 🟡 Major | "Wayfinder" is used without definition | 162 |
| C-6 | 🟢 Nit | The purpose of "key label" is only implied | 71, 139–141 |
| C-7 | 🟢 Nit | `composer ci:check` is assumed to exist | 180 |
| C-8 | 🟢 Nit | The shape of the `/v1/pricing` response is assumed | 172 |
| C-9 | 🟢 Nit | The doc does not say whether PixelForge is the app or an external service | 1, 11, 35 |

### C-1 — 🔴 Blocker: "the starter" is undefined

The document repeatedly relies on an unnamed "starter" project: "the starter's sign-in," "the starter's authenticated route group," "the active starter sidebar," "starter placeholders," and "No starter authentication... replacement is required." A new reader cannot tell what the starter is or what it provides. Add one line that names it (for example, the Laravel Vue starter kit with Fortify and Wayfinder) and lists the parts the plan relies on.

### C-2 — 🔴 Blocker: the PixelForge API contract is assumed

The document depends on a provider contract that it never includes or links:

* "published text and reasoning ranges" (65)
* "the documented `/v1` base URL" (140)
* "the documented pricing example" (164)
* "the documented limits" (152)
* the `t`/`v1` format of the `Pixelforge-Signature` header (174)
* `ping` events (79)
* the error categories: short rate or concurrency limits, temporary overload, and exhausted daily quota (173)
* the `[succeed]`/`[fail]`/`[slow]`/`[duplicate]` test hooks (182)

Without that contract, a reader cannot implement or verify the pricing math, the signature check, or the retry policy from this document alone.

**Note:** I searched the workspace for `Pixelforge-Signature` and `local_generation_id`. They appear only in `design_draft.md` and `concepts_brief.md`, and no copy of the PixelForge spec is in the repository. Link or vendor the spec (for example, `docs/pixelforge_api.md`). Then add it to the GoldfishContext for Stages 7–9. Otherwise the critic and implementation-readiness reviewers cannot check these provider claims either.

### C-3 — 🟡 Major: the four token counts are never listed

The document refers to "each of the four final token counts" and "the final four counts, rates, and UTC completion time," but never names them. Line 65 mentions text and reasoning ranges, and line 172 mentions output and reference-image counts, so the reader has to piece the list together. The pricing code depends on this list, so state it once.

### C-4 — 🟡 Major: "the assessment" is unidentified

"The assessment requires the final charge to use actual provider cost plus 25%." The document never identifies this source. A reader cannot tell whether it is a grading rubric, a client spec, or internal policy, or where to find it. The 25% markup and "charge actual cost" rules are core requirements, so cite the source.

### C-5 — 🟡 Major: "Wayfinder" is undefined

"Add Generate and History links using named Wayfinder routes." Wayfinder is Laravel's typed route-helper generator. The document does not define it, and it does not say that the project already installs it.

### C-6 — 🟢 Nit: the purpose of "key label" is implied

`PIXELFORGE_KEY_LABEL`, the "non-secret key label for later reconciliation," appears three times. Its purpose is only implied by line 91: it records which API key incurred a cost. Add one sentence that defines it.

### C-7 — 🟢 Nit: `composer ci:check` is assumed

The release gate calls a project-specific Composer script without saying that it exists or what it runs. This is part of C-1.

### C-8 — 🟢 Nit: the pricing response shape is assumed

"Read token rules from pricing" assumes the reader already knows the `GET /v1/pricing` response: a characters-per-token field, per-quality reasoning bounds, and exact output and reference-image counts. This is part of C-2.

### C-9 — 🟢 Nit: PixelForge's role is ambiguous

The title and architecture use "PixelForge" for both the feature and the provider. Line 11 says it "simulates generation and billing," and line 176 refers to "the PixelForge dashboard," which implies an external service. Add one sentence to the Overview that says PixelForge is a third-party provider the app calls.

## Terms cleared

Each of these is either defined inline or a standard engineering term:

* `picos`/trillionths (67 → 141)
* HMAC-SHA256
* CSRF
* idempotency keys
* `Retry-After`
* enums
* Inertia polling
