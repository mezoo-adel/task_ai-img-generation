# Stage 5: Initial Zoom Out Review — `design_draft.md`

* **Target:** `design_draft.md` (full file, lines 1–182)
* **GoldfishContext:** `concepts_brief.md`
* **Semantic split:** FeatureRange 1–130, DetailedRange 131–182
* **Reviewers:** `egm-zoom-out` (standard: `zoom-out-standard`), `egm-inventive-catalyst` (Mode: REVIEW)
* **Failed Reviewers:** none

## Summary

| # | Source | Severity | Finding | Lines |
| --- | --- | --- | --- | --- |
| IC-1 | Inventive Catalyst | 🟡 Major | Requests with an uncertain submission and no provider ID never reach a final state | 87–88, 151, 173 |
| IC-2 | Inventive Catalyst | 🟢 Nit | The reason given for rejecting the webhook inbox relies on recovery machinery the design already builds | 77–83, 119–123 |
| ZO-1 | Zoom Out | 🟢 Nit | Ledger table is named and shaped for Phase 2 | 72, 142, 146 |
| ZO-2 | Zoom Out | 🟢 Nit | A separate webhook route file and bootstrap callback for one route | 156–157 |
| ZO-3 | Zoom Out | 🟢 Nit | Rejecting stale estimates adds a round trip, and the doc does not justify it | 165, 172 |
| ZO-4 | Zoom Out | 🟢 Nit | Hard-coded constants in "Behavior to implement" | 172–175 |

---

## Part A: Inventive Catalyst (Mode: REVIEW)

### IC-1 — 🟡 Major: Uncertain, ID-less requests stay pending indefinitely

* **Affected scope:** Recovery (lines 87–88), `PollOverdueGenerations` (line 151), submission behavior (line 173).
* **False compromise:** Line 173 accepts "an uncertain outcome stays pending after the bounded retries" as the end state. The recovery sweep polls only unfinished generations that **have a provider ID** (lines 88, 151). If all four submission attempts time out ambiguously, the row has no provider ID, so nothing ever processes it again. The design gives up liveness ("every request reaches a final state") to protect safety ("never create a second generation"). The user then sees a row that stays queued or processing forever, which contradicts the Overview's promise of "the image and final charge or a failure reason" (line 11).
* **Proposed resolution:** The stable `Idempotency-Key` already removes most of the conflict. Replaying `POST /v1/generations` with the same key does not create a second generation, so safety does not prevent further retries.
  * **Slow fallback retries:** After the four fast retries, include ID-less rows flagged as acceptance-uncertain in the existing sweep and retry them on a slow schedule (minutes). The schedule must stay within the provider's idempotency-key retention window. The `acceptance-uncertain` flag (line 141) already identifies these rows.
  * **Final "unconfirmed" outcome:** Once the idempotency window has passed and acceptance is still unknown, move the row to a final state the user can see, with the reason "no charge, outcome unknown". This guarantees that every row reaches a final state.
* **Secondary liabilities:** Slow retries add scheduling state and spend shared quota (limit this with the existing `Retry-After` backoff). A final "unconfirmed" outcome needs either a fifth status or a failure-reason convention, and the UI, tests, and Phase 2 reporting must understand it. If a webhook later reports success for such a row, the "never move a final state backward" rule (line 75) needs a defined exception. Without one, the user is never charged for an image the provider generated.

### IC-2 — 🟢 Nit: The rejected inbox alternative is weaker than the doc claims

* **Affected scope:** Webhook processing (lines 77–83), alternative "Save signed webhook events, reply, and process them in the background" (lines 119–123).
* **False compromise:** The con given at line 122 ("the app must find and process saved events even if the worker stops or the job is never queued") describes machinery the design already builds: a bounded, lock-guarded 30-second sweep that passes results through the shared update service (line 88). The design does not need that machinery as a counter-argument. Processing webhooks synchronously ties the webhook response to the database transaction succeeding within PixelForge's 10-second window (line 83).
* **Proposed resolution (transactional inbox):** Verify the signature, insert the event row in a minimal transaction, return `2xx`, and apply the event through the shared update path using the existing sweep and queue.
* **Secondary liabilities:** After `2xx`, PixelForge stops retrying, so the local sweep alone must ensure the event is processed. A worker or scheduler outage then delays events without any external signal. The inbox also needs an index and an eligibility rule for unapplied events, and status updates can lag by one sweep interval. The synchronous choice is still defensible for Phase 1. However, the doc should give the real reason (simplicity and a single code path), not a survivability argument that its own recovery design contradicts.

### Cleared (no false compromise)

* Estimate versus locked rates is handled by the server recomputing the estimate (line 172).
* Duplicate charges are prevented by committing the event, state, and charge in one transaction, backed by a separate uniqueness constraint on the ledger (lines 73, 81).
* Using both webhooks and polling is deliberate redundancy, not a compromise.

---

## Part B: Zoom Out (standard: `zoom-out-standard`)

### Context sufficiency — Pass

Lines 9–31 and `concepts_brief.md` together make the objective, the constraints, and the phase boundary clear to a new reader.

### Root problem focus — Pass

The design addresses the root problems: the final price is unknown up front, provider updates can arrive out of order or more than once, and each success must be charged exactly once. The shared update service for create, webhook, and poll snapshots (lines 35, 43–44, 149) fixes the structure rather than working around symptoms. Phase 2 is explicitly deferred (lines 29–31, 91, 129).

### ZO-1 — 🟢 Nit: The ledger table is shaped for Phase 2

* **Scope:** Lines 72, 142, 146.
* The `credit_transactions` name, the `charge` kind column, and the `CreditTransaction` model anticipate Phase 2 purchases. Phase 2 is out of scope, and its reserve, settle, and release design (line 91) is still open, so it may need a different shape anyway. A plain `generation_charges` table (a unique generation foreign key plus an amount) meets Phase 1 and leaves Phase 2 free to choose.

### ZO-2 — 🟢 Nit: A separate webhook route file for one route

* **Scope:** Lines 156–157.
* The design adds a new `routes/webhooks.php` and a `then:` routing callback in `bootstrap/app.php` for a single POST route. The usual Laravel 11+ approach keeps the route in `routes/web.php` and exempts it through `->withMiddleware(fn ($m) => $m->validateCsrfTokens(except: [...]))` in `bootstrap/app.php`, which the plan already modifies. Reviewer note: the web group also starts a session and sets cookies for this route. Choosing between the two options is a trade-off, not a defect.

### ZO-3 — 🟢 Nit: Stale-estimate rejection is not justified

* **Scope:** Lines 165, 172.
* The design rejects a submission when the server recomputes different bounds from the ones displayed. This adds a failure path, a resubmission step, and a test case. The estimate is explicitly not binding (brief, line 12). Either state the requirement that the user agrees to the range shown, or recompute on the server and continue.

### ZO-4 — 🟢 Nit: Hard-coded constants in "Behavior to implement"

* **Scope:** Lines 172–175.
* The 5/15/30-second backoff, the 45 s versus 90 s timeout calculation, the 300 ms debounce, and the token formula will drift from the code. Keep the policies in the doc (bounded retries that honor `Retry-After`, forward-only status writes) and move the numbers to config. This section is the implementation plan, so some detail is expected here. Stage 9 will judge how much.

### Verified reuse — no reinvention found

The starter (`laravel/vue-starter-kit`) has only `User` in `app/Models` and has no services, jobs, enums, or HTTP/pricing utilities. The plan reuses what exists: the `auth, verified` group, Fortify, Wayfinder, `AppSidebar.vue`, Laravel's HTTP client, the database queue, and the scheduler. One `GenerationInputRequest` serves both estimate and submit (line 152).

### Cleared non-findings

* The recovery sweep and cache lock are the smallest mechanism that meets the missed-webhook requirement.
* The 60-second price-list cache avoids a provider call for each estimate.
* The webhook events table stores only IDs, which is the minimum needed for deduplication.
* Integer trillionths are justified because millionths lose information.
* The `GenerationStatus` enum with a string column is standard practice.
* Browser polling every 5 s and server polling every 30 s serve different requirements (UX and recovery).

### Verdict

No Blocker. One Major (IC-1) should be resolved before implementation, because it leaves a reachable state with no exit. The Nits are optional simplifications.
