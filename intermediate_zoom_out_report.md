# Stage 8: Intermediate Zoom Out Review — `design_draft.md`

* **Target:** `design_draft.md`, full file (lines 1–182). No edits were applied after Stages 5–7.
* **GoldfishContext:** `concepts_brief.md`, `zoom_out_report.md`, `comprehension_report.md`, `critic_report.md`
* **Reviewer:** `egm-zoom-out` (standard: `zoom-out-standard`). Completed.
* **Failed Reviewers:** none
* **Codebase check by reviewer:** The starter is `laravel/vue-starter-kit` on Laravel 13. Fortify and Wayfinder (`^0.1.14`) are installed. `bootstrap/app.php` uses the standard `withRouting`/`withMiddleware` setup.

## 1. High-altitude re-evaluation

The architecture still meets the standard. All three snapshot sources feed one update service, each generation gets at most one ledger write, and Phase 2 is explicitly deferred. The component count fits the problem: 3 services, 2 jobs, 2 controllers, and 3 tables. The design adds no speculative abstractions, no queues beyond Laravel's, and no cache beyond the 60-second price list.

* 🟢 **Nit (lines 141, 147, 172–175):** Implementation constants are written into the doc: the `picos` naming, the fixed backoffs, and the token formula. This is ZO-4 and it is still open.
* 🟢 **Nit (line 176):** The deployment steps read like an operations runbook. They are acceptable in an implementation plan, but the section should not grow further.

## 2. Audit of proposed remedies (scope-creep check)

| Remedy | Source | Verdict | Minimal adequate form |
| --- | --- | --- | --- |
| Retry uncertain rows with no provider ID through the existing sweep | IC-1, design-1-3 | **Adopt** | Extend the sweep's eligibility to `acceptance_uncertain` rows without a provider ID. Replay them with the original `Idempotency-Key` on a slow schedule that stays within the provider's key retention window. The flag (141), sweep (88), lock, and `Retry-After` handling already exist. |
| Add a fifth `unconfirmed` status | IC-1 | **Reject** | After the slow retries run out, use `failed` with a distinct failure code ("submission outcome unknown, no charge"). This avoids changing the enum, the UI, and Phase 2 reporting. |
| Add a Risks & Limits section | design-1-2, structural-1-1 | **Adopt, prose only** | Write a short subsection covering the timestamp tolerance, the input limits, browser polling limited to active rows, and per-user abuse control on `POST /generations`. For that last item, either state that Phase 1 has none or add Laravel's built-in `throttle:` middleware. Do not build a rate-limiting subsystem. |
| Guard against duplicate submissions | design-1-4 | **Adopt the UI guard only** | Disable Generate while the Inertia form is `processing` (`Create.vue`, line 160). Reject a server-side dedupe window as over-engineering for Phase 1. Two submissions are two legitimate generations. |
| Process webhooks through an inbox | IC-2 | **Reject** | Keep synchronous commit-before-reply. Rewrite the rationale at lines 122–123: the real reasons are one code path and provider retries that cover local write failures, not survivability. |
| Remove "Rejected by author"; name the starter and the requirements source | design-1-1, C-1, C-4 | **Adopt** | One sentence each. C-5 (Wayfinder) only needs a note that Wayfinder is already in `composer.json`. |
| Stale-estimate rejection | ZO-3 | **Justify or simplify** | Either recompute the estimate on the server at submit and proceed, or add one sentence saying the user must confirm the range shown. |

### Lead caveat on the "terminal failed" remedy

The reviewer says to keep a `failed` row recoverable in the sweep until the idempotency window has passed. That contradicts the rule at line 75 ("Never move a final state backward"). Do not mark the row `failed` while acceptance is still uncertain. Keep it non-final (`queued` with `acceptance_uncertain`) through the slow retries. Mark it `failed` only once a replay can no longer reach the provider's original request. Then add one sentence that states either:

* a success webhook that arrives later for that row is logged and not charged, or
* it is applied as a documented exception to line 75.

The doc must pick one of these. If it stays silent, an image the provider generated may never be charged.

## 3. Status of earlier Zoom Out findings

All four are **open** because no edits have been applied:

* **ZO-1 (🟢; lines 72, 142, 146):** The `credit_transactions` table is shaped for Phase 2. It is now easier to defend because line 91 documents the Phase 2 direction. It can be downgraded to a naming note.
* **ZO-2 (🟢; lines 156–157):** A separate webhook route file versus a CSRF exemption. This is a trade-off, not a defect; the doc should pick one.
* **ZO-3 (🟢; line 172):** The stale-estimate rejection is still unjustified.
* **ZO-4 (🟢; lines 172–175):** Hard-coded constants are still in the doc.

## Verdict

This pass found no new Blocker or Major. The only remedy with real design weight is the fix for IC-1/design-1-3, and the adequate version of it is small. The other remedies are sentence-level documentation fixes.

Open items carried forward:

* **🔴 Blockers:** Stage 6 C-1 (the starter is never named) and C-2 (the PixelForge spec is not linked).
* **🟡 Majors:** the Stage 7 Majors and Stage 6 C-3 (the four token counts are never listed).
