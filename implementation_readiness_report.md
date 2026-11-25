# Stage 9: Implementation Readiness Test, Round 1 — `design_draft.md`

* **Target:** `design_draft.md`, DetailedRange lines 131–182. I re-checked the bounds before this round.
* **GoldfishContext:** `concepts_brief.md` and the codebase at `pxi_task/` (read-only).
* **Reviewers:**
  * `egm-implementation-readiness` (`implementation-readiness-standard`): completed
  * `egm-codebase-details` (`implementation-readiness-standard`): completed
  * `egm-style` (persona rules): completed
* **Failed Reviewers:** none
* **Round status:** **Not clean.** Two Blockers and several Majors are unsettled.

## Consolidated findings

In each finding ID, `ir` = implementation readiness, `cd` = codebase details, `st` = style.

### 🔴 Blockers

| ID | Finding | Lines | Raised by | Lead note |
| --- | --- | --- | --- | --- |
| ir-1-1 | The PixelForge API contract is assumed but never provided. Missing: the base URL, input limits, signature format, snapshot JSON shape, error categories, the pricing example, and the test hooks. The repo contains no spec. | 140, 147, 149, 150, 152, 164, 172–175, 182 | ir; cd-1-2 (base URL); also Stage 6 C-2; structural-1-4 | Valid. This is now confirmed in three stages. Vendor the spec (for example `docs/pixelforge_api.md`) and link it. |
| ir-1-2 | The four token counts, the rate fields, and the estimation constants are never named. The required test against "the documented pricing example" cannot be written. | 148, 164, 172, 174 | ir; also Stage 6 C-3 | Valid. Add the field names and one worked numeric example with the expected min/max estimate and the final charge. |

### 🟡 Majors

| ID | Finding | Lines | Raised by | Lead note |
| --- | --- | --- | --- | --- |
| ir-1-3 | Migration column types, nullability, and index column lists are not decided | 141–143 | ir | Valid. **Correction to the reviewer's math:** a 32-bit integer overflows at about 2.1×10⁹ picos, which is **$0.0021**, not $2,147. `*_picos` must be an `unsignedBigInteger`, which holds up to about $18.4M. That makes the finding more serious than the reviewer stated. |
| ir-1-4 | "Forward change only" has no transition table. The plan does not say what happens when succeeded and failed compete, which fields each snapshot writes, or what the conditional-write predicate is. | 149, 174 | ir | Valid. Line 180 tests a rule that the plan never states. |
| ir-1-5 | The stale-estimate rejection rule is ambiguous. It does not say whether any difference counts, whether to compare strings or picos, or which error key and status to return. | 172 | ir; also Stage 5 ZO-3 | Valid. Either pin the rule (for example, exact equality of both bounds in picos) or drop the rejection, as ZO-3 suggests. |
| ir-1-6 | The lifecycle of the `acceptance-uncertain` flag is only half specified | 141, 150, 173 | ir; connects to Stage 5 IC-1 / Stage 7 design-1-3 | Valid. Define it together with the fix for rows that have no provider ID (Stage 8 lead caveat). |
| ir-1-7 | The retry mechanism for `SubmitGeneration` is not chosen: `$tries`/`$backoff`, `release()`, or `retryUntil` | 150, 173, 175 | ir | Valid. The choice interacts with the 45 s timeout and the 90 s `retry_after`. |
| ir-1-8 | The history page props, the partial-reload `only` keys, and the row shape are not pinned | 153, 159, 161, 175 | ir; cd-1-4 (`usePoll` not named) | Valid. |
| ir-1-9 | The middleware on the webhook route is not specified | 154, 156–157 | ir; cd-1-4 (`then:` not named) | Valid. Name `withRouting(then: ...)` and state that the route gets no session, CSRF, or throttle middleware (or name the throttle). |
| ir-1-10 | The tests list topics but not cases with assertions. The plan does not say whether the tests use Pest or PHPUnit. | 164–167 | ir | Valid. The repo uses Pest (`tests/Pest.php`). |
| ir-1-11 | The deployment database type is undecided, but the concurrency check at line 180 depends on it | 176, 180 | ir | Valid. |
| cd-1-3 | The file table leaves out factories, test fixtures/signing helpers, and the Wayfinder-generated `resources/js/routes` | 135–168 | cd | Valid for factories and fixtures. For Wayfinder: say that the Vite plugin regenerates the routes, and do not list the generated files as edits. |
| st-1-1 | "Debounce by **about** 300 ms": the number cannot be tested | 172 | st | Valid but low impact. **Proposed downgrade to Nit.** |
| cd-1-1 | "Inertia's HTTP helper" is said not to work for a JSON endpoint | 160, 172 | cd | **Proposed CONTEST.** Inertia v3 ships `useHttp` for plain HTTP/JSON requests. `node_modules/@inertiajs/vue3/types/index.d.ts:12` exports it. The approach works. The remaining gap is that the plan does not name `useHttp`, which belongs with ir-1-8/cd-1-4 as a Nit. |

### 🟢 Nits

| ID | Finding | Lines | Raised by | Lead note |
| --- | --- | --- | --- | --- |
| cd-1-4 | Framework APIs are not named: `then:`, `useForm`, `usePoll` | 157, 160, 161 | cd | Merged into ir-1-8/ir-1-9. |
| cd-1-5 | "Dispatch after commit" needs `afterCommit()` because the database queue has `after_commit => false` (`config/queue.php:44`) | 150, 153 | cd | Valid. Name the mechanism. |
| cd-1-6 | Webhook errors may be rendered as HTML. JSON rendering applies only to `api/*` or `expectsJson()` (`bootstrap/app.php:27–29`). | 154, 174 | cd | Valid. One sentence is enough. |
| cd-1-7 | `.env` is not listed next to `.env.example` | 139 | cd | **Proposed DECLINE.** `.env` is local, holds secrets, and is ignored by git. It does not belong on a write allowlist. The README step at line 168 already covers local setup. |
| ir-1-12 | `PIXELFORGE_BASE_URL` is not decided as either an env value or a config default | 139–140 | ir | Merged into ir-1-1. |
| ir-1-13 | The route-to-controller-method mapping is not stated, and neither is the choice between `Route::inertia` and `Inertia::render` | 155 | ir | Valid. |
| ir-1-14 | The purpose of the key label is only implied | 141, 173 | ir; also Stage 6 C-6 | Valid. |
| ir-1-15 | The scheduler API (`everyThirtySeconds()`) is not named. The plan does not say whether `withoutOverlapping()` or the job's own cache lock is the authoritative guard. | 151, 158 | ir | Valid. Pick one. |
| ir-1-16 | "Replace the starter placeholders": name `PlaceholderPattern` | 163 | ir | Valid. |
| st-1-2 | "A few real simulator results" cannot be counted | 182 | st | Valid. |
| st-1-3 | "Safe failure code" does not say what makes it safe | 141 | st | Valid. Say that it is a code shown to users with no provider internals. |
| st-1-4 | "Actionable errors" is filler | 160 | st | Optional. |
| st-1-5 | "Space out retries" should use the `Retry-After` wording from line 175 | 151 | st | Valid. Keeps both lines consistent. |
| st-1-6 | Drop the sentence "A comparison during verification does not add a reporting feature…" | 182 | st | Optional. The sentence draws a scope boundary; keeping it is defensible. |
| st-1-7 | Drop the sentence "No image-generation tool or provider key is needed…" | 181 | st | Optional. |

## Verified accurate (codebase-details)

* `composer ci:check` exists (`composer.json:64–69`). `npm run build` exists (`package.json:6–11`).
* The `auth` + `verified` group is in `routes/web.php:7–9`. Email verification is enabled (`config/fortify.php:166`).
* The database queue has `retry_after = 90` (`config/queue.php:43`), so a 45 s job timeout fits under it.
* `AppSidebar.vue` uses Wayfinder imports from `@/routes`. `Dashboard.vue` contains only placeholders. `routes/console.php` contains only `inspire`.
* `CACHE_STORE=database` supports the sweep lock. Laravel 13 supports sub-minute scheduling.
* All new paths are consistent with PSR-4 autoloading.

## Open items from all stages

| Severity | Items |
| --- | --- |
| 🔴 Blocker | C-1: the starter is never named. ir-1-1 / C-2: the PixelForge spec is missing. ir-1-2 / C-3: the token fields and worked example are missing. |
| 🟡 Major | IC-1 / design-1-3 / ir-1-6: requests with no provider ID after uncertain submission are never finalized. design-1-2: no Risks & Limits section. design-1-4: no UI guard against double submission. design-1-1: "Rejected by author". style-1-1 / C-4: "the assessment". C-5: Wayfinder. ir-1-3 through ir-1-11. cd-1-3. |
| 🟢 Nit | See the Stage 5, 7, 8, and 9 tables. |

## Iteration state

* Round 1 of a maximum of 5. The round is not clean.
* Proposed settlements, pending your decision:
  * cd-1-1: CONTEST
  * cd-1-7: DECLINE
  * st-1-1: downgrade to Nit
  * style-1-2 (Stage 7): CONTEST
