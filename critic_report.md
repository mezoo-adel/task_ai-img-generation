# Stage 7: Critic Review, Round 1 — `design_draft.md`

* **Target:** `design_draft.md`, FeatureRange lines 1–130. I re-checked the bounds before this round: `## Detailed Implementation Plan` is still at line 131.
* **GoldfishContext:** `concepts_brief.md`
* **Reviewers:** `egm-design` (`feature-spec-standard`), `egm-structural` (`feature-spec-standard`), and `egm-style` (persona rules only). All three completed.
* **Failed Reviewers:** none
* **Round status:** **Not clean.** The round has unsettled Major findings.

## Consolidated findings

I merged duplicate findings and noted each merge in the **Raised by** column. The **Lead note** column gives my own assessment of each finding.

| ID | Severity | Finding | Lines | Raised by | Lead note |
| --- | --- | --- | --- | --- | --- |
| design-1-1 | 🟡 Major | "Rejected by author" is an unlinked attribution | 123 | design, structural (structural-1-2), style (style-1-3, Nit) | Valid. The technical reason that follows is enough on its own, so the fix is to delete the three words. |
| design-1-2 | 🟡 Major | No explicit Risks & Limits section | 27–31, 33–91 | design, structural (structural-1-1) | Valid against the standard. Some items the reviewer lists as missing are already in the DetailedRange: the 300 s timestamp window (174) and the 4,000-character prompt limit (172). The feature spec still needs to say there are limits, without the numbers. |
| design-1-3 | 🟡 Major | A row with no provider ID after the submission retries are used up never reaches a final state | 61, 87–88 | design; also Stage 5 IC-1 | Valid. Two separate reviewers raised this. It is the most important finding of the round. |
| design-1-4 | 🟡 Major | No guard against a user submitting twice | 55 | design | Partly valid. Two rows from a double click are two separate generations with two charges each, not a double charge for one generation. It is still a real risk for users. The doc states no mitigation, such as disabling the button while the request is processing or ignoring identical submissions within a short window. |
| style-1-1 | 🟡 Major | "The assessment" is used as a justification without saying what it is. "Required webhook handling" at line 116 is also uncited. | 105, 116 | style; also Stage 6 C-4 | Valid. State the requirement directly or cite where it comes from. |
| style-1-2 | 🟡 Major | "Each keystroke" is called hyperbole | 65 | style | **Proposed CONTEST or downgrade to Nit.** The plan updates the estimate on input changes with a debounce (172), so per-keystroke estimate requests are possible. The sentence describes a real failure mode that the cache prevents. |
| design-1-5 | 🟢 Nit | `POST /generations/estimate` is used for a calculation with no side effects | 54 | design | POST matches Inertia's CSRF and form helpers. Keeping it is defensible. |
| design-1-6 | 🟢 Nit | The spec does not give the price-list cache lifetime or say that rates lock when the provider accepts the request | 65 | design | Valid. The brief states the rate lock (line 12) but the draft does not. Add that sentence. |
| design-1-7 | 🟢 Nit | The webhook timestamp tolerance is missing from the feature spec | 79 | design | It is specified at line 174 (300 s). Optional: mention it at line 79. |
| design-1-8 | 🟢 Nit | Browser polling never stops for a row that is stuck | 46, 89 | design | Fixing design-1-3 also fixes this. |
| structural-1-3 | 🟢 Nit | Headings differ from the standard's names: "The Problem" and "Technical Plan (Architecture)" | 13, 33 | structural | The meaning matches. Renaming is optional. |
| structural-1-4 | 🟢 Nit | There are no links to the PixelForge API spec or the concepts brief | 1–130 | structural; also Stage 6 C-2 (Blocker there) | Stage 6 rates the missing provider spec as a Blocker. Fix it there. |
| style-1-4 | 🟢 Nit | "clearly labeled" uses a filler word | 21 | style | Optional. |
| style-1-5 | 🟢 Nit | "without submitting it again to find out" is wordy | 15 | style | Optional. |
| style-1-6 | 🟢 Nit | "merely" is a filler word | 87 | style | Optional. |
| style-1-7 | 🟢 Nit | Polling intervals mix exact and approximate wording: "Every 30 seconds" versus "about every 5 seconds" | 88–89 | style | Valid. Use one convention for both intervals. |
| style-1-8 | 🟢 Nit | "The active app layout adds links for generating and history" | 59 | style | Optional rewording. |
| style-1-9 | 🟢 Nit | The antecedent of "those messages" is too far back | 17 | style | Optional. |
| style-1-10 | 🟢 Nit | It is unclear which tables "the records" means in the Phase 2 bullet | 91 | style | Valid. Name the generation and ledger records. |

## Reviewer detail

### egm-design (feature-spec-standard)

* **🟡 design-1-1 (line 123):** "Rejected by author:" breaks the Alternatives Rule because no issue, discussion, or PR is linked. Delete the attribution and keep the technical reason.
* **🟡 design-1-2 (lines 27–31, 93–130):** The standard requires the doc to call out its constraints explicitly. The reviewer found these missing or only implied:
  * how much clock skew the replay check tolerates
  * a ceiling on browser pollers
  * rate or abuse control on `POST /generations`, which triggers billable provider work
  * limits on prompt length and on requests per user

  Add a short "Risks & Limits" subsection, or explain why each item is out of scope.
* **🟡 design-1-3 (lines 61, 87–88):** A submission job that is lost, or that uses up its retries before a provider ID is known, leaves the row `queued` forever. The sweep only covers rows that have a provider ID. Define a final outcome for this case.
* **🟡 design-1-4 (line 55):** Two quick submissions create two rows, two jobs, two provider generations, and two charges. Add a guard in the UI, a server-side dedupe window, or both.
* **🟢 design-1-5 to design-1-8:** POST is used for the estimate, the cache lifetime and rate-lock statement are missing, the timestamp tolerance is missing from the feature spec, and browser polling runs indefinitely on stuck rows.

### egm-structural (feature-spec-standard)

* The heading hierarchy, tables, code fence, and bullet style are all compliant.
* **🟡 structural-1-1:** There is no section where a reader can find the risks; they are only implied in the architecture text. Merged into design-1-2.
* **🟡 structural-1-2 (line 123):** Unlinked "Rejected by author". Merged into design-1-1.
* **🟢 structural-1-3:** The heading names differ from the standard's names.
* **🟢 structural-1-4:** The doc has no links to the PixelForge API or the concepts brief.

### egm-style

* **🟡 style-1-1 (105, 116):** Proposed rewrite for line 105: "The final charge must equal actual provider cost plus 25%, which the estimate cannot guarantee."
* **🟡 style-1-2 (65):** Proposed rewrite: "Do not call the provider for each estimate recalculation: reuse the current price list and refresh it when it expires." The lead notes above propose contesting the severity.
* **🟢 style-1-3 to style-1-10:** The redundant "Rejected by author", filler words ("clearly", "merely", "to find out"), inconsistent wording of the polling intervals, the "links for generating" wording, the distant antecedent at line 17, and the unnamed records at line 91.

## Carried over from earlier stages (not yet fixed)

* **Stage 5 IC-1 (Major):** Same as design-1-3.
* **Stage 6 C-1 and C-2 (Blockers):** The starter is never identified, and the PixelForge API spec is not linked.
* **Stage 6 C-3 (Major):** The four token counts are never listed.

## Iteration state

* Round 1 of a maximum of 5. The round is not clean, so the next round will not count toward convergence.
* Settled findings: none yet.
