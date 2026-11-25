# PixelForge: Image generation and usage charges

* **Author:** mezoo_adel
* **Date:** 2026-11-25

## Overview

Signed-in users can describe an image, choose its size and quality, and optionally paste up to four reference image links. The app shows an estimated price in US dollars before they generate. Their history updates while they wait and shows the image and exact final charge on success, or a reason and no charge on failure. PixelForge simulates the images and costs; no real money moves.

## Analysis

**Tell users what they can know before they commit.** Use the current PixelForge price list, the selected size and quality, the number of reference images, and the documented ranges for text and reasoning tokens to calculate a price range. Both ends include the 25% markup. Put the range beside the Generate button, label it “Estimated cost,” and make clear that the final charge depends on actual usage. The range is not a guarantee: PixelForge locks rates when it accepts the request, not when the app shows the estimate. If pricing is unavailable, do not show a stale estimate as current or submit without a valid estimate.

**Keep the estimate, provider cost, and user charge separate.** On success, use the four final token counts and the rates locked for that generation, not the current price list or the estimate. Store dollar amounts as integers in trillionths of a dollar; this preserves the provider's nine-decimal amounts and the extra precision that multiplying by 1.25 can require. Do not round before saving. Make small nonzero charges visible in history. Save the usage, applied rates, provider cost, final user charge, user, and provider completion time in UTC so the report can use recorded facts later.

**Let different messages reach one final result.** Save the local generation before contacting PixelForge. Send its ID as `metadata.local_generation_id` and reuse the same idempotency key and request data on submission retries. Verify each webhook's signature on the raw body and check its timestamp before accepting it. For generation events, find the row using the signed `data.object.metadata.local_generation_id` and check that `data.object.id` matches the stored provider ID if one is already set. A signed `ping` is a connectivity check and does not belong to a generation. Pass webhook, polling, and create responses through the same status-update path. Save an accepted event ID only once. Commit final success and the charge together, with a database constraint allowing only one charge per generation. Do not let a late event move a finished generation backward. If a success update lacks the data needed to price it, recover that data from PixelForge rather than guessing.

**Keep generation states explicit.** Use a PHP string-backed `GenerationStatus` enum with `queued`, `processing`, `succeeded`, and `failed`. Store the value in a normal string column and cast it to the enum in Laravel. `succeeded` and `failed` are final: a signed success can arrive while the local row is still `queued`, but a later processing update cannot reopen it.

**Recover without starting a second generation.** The browser refreshes active requests from our app every 5 seconds, not from PixelForge. The server sweeps due requests every 30 seconds. It retrieves known provider IDs starting 60 seconds after acceptance and retries submissions without an ID using only the original key and payload. A shared lock and cooldown keep the initial job and sweep from overlapping or ignoring `Retry-After`. After four automatic submission attempts, unresolved requests stop retrying, stay non-final, and can still receive signed callbacks. History explains that confirmation is unavailable and does not keep polling a parked row.

**Design for the wait and the return visit.** Reuse the starter's sign-in and app layout. Put the image workspace first: a clear prompt, named aspect ratios with pixel dimensions, quality settings, and an optional link input. Keep the estimate and Generate button together on desktop and mobile. Show queued and processing as distinct states, explain failures in plain language, and preserve enough input to retry. Reference URLs are validated and forwarded, not fetched by the app. Offer the image while its link is valid and show an expiry message in history after 24 hours; keep the cost and status available.

The goal is a flow where users know what they might pay before they click and can always tell what happened afterward. A delayed or repeated provider message must not create a second charge or hide a successful result.

## Goals vs. Non-Goals

* **Phase 1 — In scope:** Existing Laravel, Vue, and Inertia app and sign-in; generation form and price estimate; live history with images and exact charges; secure webhooks and recovery; public deployment with a queue worker and scheduler.
* **Phase 1 — Out of scope:** Admin reporting, balances, purchases, permanent image storage, uploads, WebSockets, and support for another provider.
* **Phase 2 — In scope after Phase 1 passes:** An operator-only report of user charges, provider costs, and profit by user and UTC completion day; comparison of daily provider totals with `GET /v1/usage` for the same environment key; test credit purchases and a check that blocks requests users cannot afford.
* **Phase 2 — Out of scope:** Real payments, invoicing, and allocating a provider totals mismatch to individual users. PixelForge's usage endpoint does not provide a per-user breakdown.

## Alternatives Weighed

* **Fixed warning of 5%–20% above estimate:** Not adopted. The difference can go either way and depends on the generation settings and usage.
* **Charge the estimate or cap the charge at the displayed range:** Not adopted. The assessment requires the actual provider cost plus 25%, even if that differs from the estimate.
* **Use integer millionths of a dollar:** Not adopted. They lose fractional amounts that must reconcile with PixelForge.
* **Use only webhooks or only polling:** Not adopted. Webhooks can go missing, and frequent provider polling would consume shared quota. Use signed events with a bounded recovery poll.
* **Build a wallet, payment integration, or provider framework first:** Not adopted. There is one provider and no real money. Saving the charge and cost now lets the bonus features build on the same records without adding those systems to Phase 1.

## Key Decisions & Staging for Next Steps

1. **Build the core on the existing starter.** Use a single PixelForge client, a pricing service, and a shared status-and-charge path. Keep three sets of records: generations, ledger entries, and processed webhook IDs. Keep credentials out of source control; use separate development and deployment keys and signing secrets. Log generation and event IDs for troubleshooting without logging prompts, signed image links, or secrets.
2. **Prove Phase 1 before starting Phase 2.** Follow the [manual checklist](./README.md#manual-verification) rather than adding automated test cases. Verify isolated history, exact token-based charges, zero on failure, event deduplication, overlapping polling and webhook updates, and recovery from a missing callback or a retried creation. Reject webhooks when no signing secret is configured. Keep generation and ledger history if a user requests account deletion. On the public host, verify the worker, scheduler, and signed webhook, and give the evaluator private access to a verified account with examples.
3. **Add reporting from stored amounts.** Sum the exact provider costs by key and UTC completion day and compare them with PixelForge's totals. Report any discrepancy separately. Use the locally stored user and charge to make the per-user breakdown; do not reconstruct old costs with today's rates.
4. **Design credit admission before enabling it.** Checking a balance without setting credits aside lets two simultaneous requests spend the same money. Phase 2 should reserve credits atomically before admission, settle the exact amount on success, and release credits on failure. Resolve how existing users and pending Phase 1 jobs carry over, and what happens if actual use exceeds the reserve, before turning this check on. Phase 1 makes no reservations and does not change how past charges are recorded.
