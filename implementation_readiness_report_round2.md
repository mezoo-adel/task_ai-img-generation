# Stage 9 Re-audit (Round 2): `design_draft.md`

* **Target:** the whole `design_draft.md` after your manual rewrite. The Detailed Implementation Plan starts at line 143. I also checked `README.md`, which the plan now uses as its verification checklist.
* **Context:** `concepts_brief.md`, the live [assessment brief](https://pixelforge.promptedit.dev/assessment), the PixelForge docs (used for the provider contract in round 1), and the codebase.
* **Reviewer:** the lead agent. This is **not** a Goldfish-isolated review. I helped write the earlier fixes, so I am not a fresh reader. The findings below are checked against the docs and the code, but they can still miss what a fresh reviewer would catch.
* **Re-audit cap:** this was the single allowed Stage 9 re-audit.
* **Round status:** **Not clean.** There is 1 Blocker and 4 Major findings.

## What improved

These changes from your rewrite hold up:

* **Missing signing secret:** the webhook returns `503` before any read or write (lines 81, 187). PixelForge retries, so nothing is lost while the secret is fixed.
* **Stale POST errors:** an error from an older submission attempt cannot overwrite a row that a webhook has already bound (line 210).
* **Unknown attempts after a crash:** the attempt is recorded before the call goes out (line 207).
* **Key-label mismatch:** the row is parked instead of being resent under a different key (line 205).
* **Account deletion:** restrictive foreign keys plus a profile error (lines 102, 188, 225). Without this, the starter's delete-account feature would fail with an FK error once a user has history. The README check covers it (README line 47).
* **Evaluator access:** a verified evaluator account with sample generations (lines 92, 226, 232). The brief says the reviewers will compare our numbers with PixelForge's records.
* **Resending events:** the README tells the operator to resend events from the dashboard. The brief confirms the dashboard "can resend events".

## Findings

| ID | Severity | Finding | Lines |
| --- | --- | --- | --- |
| R2-1 | 🔴 Blocker | Concurrency-limit and rate-limit responses count toward the 4-attempt cap. A fourth request made while three generations are running will fail. | 97, 156, 208 |
| R2-2 | 🟡 Major | Parking brings back the stuck-row problem in cases where a resend would settle the outcome. | 24, 26, 103, 208–209 |
| R2-3 | 🟡 Major | The global lock with `dontRelease()` throws away first-attempt jobs. The sweep handles at most 5 rows per 30 seconds, and submissions and status checks share that capacity. | 184, 224 |
| R2-4 | 🟡 Major | No automated tests, and the plan never runs the existing suite even though it changes `ProfileController`. | 145, 181, 188, 230 |
| R2-5 | 🟡 Major | Complexity has grown past the brief's "2 to 4 hours, understand every line". | 172, 205–210, 224–225 |
| R2-6 | 🟢 Nit | The shared cooldown has no named cache key, although the auth pause does (`pixelforge:auth-paused`). | 224 |
| R2-7 | 🟢 Nit | The paragraph at line 172 splits the file table in two. `submit_attempts` and `acceptance_uncertain` are missing from the generations row. | 168, 172–175 |
| R2-8 | 🟢 Nit | Line 181 still says "for direct testing", but the plan adds no tests. | 181 |
| R2-9 | 🟢 Nit | "Unsigned public price lookups" should be "unauthenticated". `GET /v1/pricing` needs no key; signatures apply only to webhooks. | 224 |

### R2-1: Concurrency and rate limits cause the request to fail

**What happens.** Line 208 allows "four automatic outbound POST attempts total". It marks the row failed once the cap is reached and every attempt is known to have been rejected.

**Why that is wrong for these errors.** `429 concurrency_limit_exceeded` (`Retry-After: 5`) and `429 rate_limit_exceeded` are certain rejections. Nothing was created and nothing costs anything. They mean "wait", not "this request is bad".

**Example.** Three `[slow]` generations (60–90 s each) are running and a fourth user submits. The four attempts run at roughly 0 s, 5 s, 20 s, and 50 s, plus sweep delay. All four get the concurrency limit, and the fourth user's request fails.

**Contradictions:**

* Risks and Limits says "Requests above the concurrency limit wait in our queue" (line 97).
* The brief lists concurrency limits as one of the failure modes it tests.

**Fix:**

* Do not count `429 rate_limit_exceeded` or `429 concurrency_limit_exceeded` toward `submit_attempts`. Set `next_check_at` to at least `Retry-After`, and also to the shared cooldown.
* Count only overload (`503`) and attempts with an uncertain outcome. Four overloads then still end in failure with no charge, which matches README line 42.

### R2-2: Parked rows can stay stuck forever

**The provider contract settles most of these cases.** The contract (line 154) says a replay with the same key and body returns the existing generation, does not count against the concurrency or daily limits, and that keys never expire. This gives two consequences:

* **A rejection on a same-key replay proves nothing was created before.** This applies to `400 content_policy_violation`, `422 validation_failed`, and `429 daily_limit_exceeded`. If an earlier attempt had been accepted, the replay would have returned it.
  * Line 209 parks these rows when an earlier attempt "may have been accepted". They should be marked `failed` with no charge.
  * Line 207's "A documented rejection restores the prior uncertainty" should become "clears uncertainty".
* **After four timeouts, resending is still safe and free.** Line 208 parks the row and clears `next_check_at`. Phase 1 has no operator page, so the row stays at "confirmation unavailable" until someone notices it and resends an event from the dashboard. If PixelForge never created the generation, there is no event to resend.

**Contradictions:**

* Goal line 24 says history stays current "without a manual refresh".
* Goal line 26 says the app reaches the same final state "when provider updates are … missing".

**Fix:**

* Mark a same-key `400`, `422`, or `daily_limit_exceeded` response as `failed` with no charge.
* For rows that are still uncertain after the cap, keep resending on the status-check backoff, up to 5 minutes between tries.
* Park only on `409 idempotency_key_reused` or a key-label mismatch. Both indicate a local bug or config change that a person must look at. Keep `401` as a global pause.
* Optional: park uncertain rows after a long limit, for example 24 hours, so a provider bug cannot keep them retrying forever.

### R2-3: The global lock drops first-attempt jobs

**The setup.** Both jobs use `WithoutOverlapping('pixelforge-operations')->shared()->expireAfter(60)->dontRelease()` (line 224).

**What happens to a new submission:**

* While a sweep holds the lock, which can take up to 5 rows × 5 s each, every new `SubmitGeneration` job is deleted.
* Line 205 accepts this ("the row remains due"), so the first attempt waits for the next sweep.

**Why this is slow under load:**

* One sweep handles 5 due rows, so the whole system does at most about 10 provider operations a minute.
* Every accepted row also gets a status check 60 s after acceptance, even when webhooks are working (line 90), and those checks take sweep slots too.
* Example: an evaluator submits 8 prompts quickly. Some of them stay `queued` for a minute or more with no provider activity.

The `->shared()` and `dontRelease()` methods do exist on Laravel's `WithoutOverlapping` middleware.

**Fix: lock each row instead of the whole provider.**

* The submission action takes `Cache::lock('pixelforge:generation:'.$id, 60)`, and the sweep tries the same lock for each row.
* Keep the sweep-level `WithoutOverlapping('pixelforge-sweep')`.
* Provider back-off goes through the shared cooldown key (R2-6), not through a global lock.
* The conditional writes in step 3 already make any remaining races safe.

### R2-4: No automated tests

The brief does not require tests, so this is your call. Three points are worth weighing:

* **Correct money is the first thing the brief grades** ("Put correctness ahead of polish"). Several key cases cannot be reproduced reliably by hand:
  * a crash between saving a row and its event
  * a failed commit followed by a retry
  * a webhook arriving before the create response

  README line 46 already admits this ("when reproducible").
* **The plan changes `ProfileController::destroy`** (line 188). The existing `tests/Feature/Settings/ProfileUpdateTest.php` (lines 96–119) covers account deletion. The verification plan (line 230) runs no tests at all, so a regression there would go unnoticed.
* **Your own global rules say:** "Add or update tests for meaningful behavior changes."

**Recommended minimum:**

1. Run the existing `composer test` before release.
2. Add three Pest files:
   * **Pricing unit test:** the two worked examples at lines 204 and 211.
   * **Webhook feature test:** a valid and an invalid signature, a duplicate event, and late processing after success.
   * **Submission action test** with `Http::fake`: a timeout followed by a replay, a concurrency `429`, and a same-key rejection.

These cover the money and ordering cases that manual checks cannot.

### R2-5: Complexity vs. scope (zoom-out)

The brief expects 2 to 4 hours of work and says "make sure you understand every line". The rewrite adds all of these:

* an attempt counter
* an uncertainty flag
* parking
* `needs_attention`
* a global auth-pause cache key
* a global shared lock
* a shared cooldown
* user-row locking for account deletion

Each one answers a real edge case. Together they make the submission path the hardest part of the app to explain. Fixing R2-1 to R2-3 removes most of the parking states and the global lock.

**Simpler path that is still correct:**

* one submission action with a per-row lock
* a shared cooldown key
* same-key replays until the outcome is known
* a pause on `401`
* parking only on `409` or a key-label mismatch

Keep the account-deletion guard. Without it the starter's delete-account feature fails.

### R2-6 to R2-9: Nits

* **R2-6:** Name the cooldown key, for example `pixelforge:cooldown-until`, holding a Unix timestamp. Both jobs read it before any authenticated call.
* **R2-7:** Move the line 172 sentences into the generations migration row at line 168, and keep one file table.
* **R2-8:** Line 181 should read "Keep the math separate from HTTP calls." Alternatively, keep "for direct testing" if you take the R2-4 recommendation.
* **R2-9:** In line 224, change "unsigned" to "unauthenticated".

## Resolution (applied to `design_draft.md`)

* **R2-1:** `429` rate and concurrency limits no longer count toward the cap. The row waits until `Retry-After` and the shared cooldown allow another try.
* **R2-2:** A same-key `400`, `422`, or `daily_limit_exceeded` now marks the row `failed` with no charge. A row that is still uncertain after the cap keeps replaying, up to 5 minutes apart, and is parked only after 24 hours. Rows are also parked on `409` or a key-label mismatch, and `401` remains a global pause.
* **R2-3:**
  * The global `pixelforge-operations` lock is replaced by a per-row `Cache::lock('pixelforge:generation:'.$id, 60)`, plus `WithoutOverlapping('pixelforge-sweep')` on the sweep.
  * `dontRelease` is removed.
* **R2-4:** You chose minimal backend tests only:
  * `PixelForgePricingTest`
  * `PixelForgeWebhookTest` (3 cases)
  * `SubmitGenerationAttemptTest` (3 cases)
  * `GenerationFactory`
  * `composer test` before release
  
  There are no frontend, browser, or end-to-end tests. Everything else stays in the README's manual checklist.
* **R2-5:** Addressed through the R2-1 to R2-3 fixes. The account-deletion guard is kept.
* **R2-6:** The cooldown key is now `pixelforge:cooldown-until`.
* **R2-7:** The file table is merged into one, and the new columns are in the generations row.
* **R2-8:** "For direct testing" is kept, because the pricing unit test now exists.
* **R2-9:** Line 224 now says "unauthenticated".
* **Also specified:** the webhook returns `400` with no write when the signature check fails. The test cases needed this.

## Iteration state

* **Re-audit cap:** this was the single allowed Stage 9 re-audit, so the cap is reached. Any findings you leave unresolved should be recorded as accepted trade-offs.
* **Decisions needed from you:**
  * R2-4 (tests).
  * Whether to adopt the simplifications in R2-1 to R2-3 and R2-5.
