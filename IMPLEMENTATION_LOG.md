# Implementation Log

## 2026-08-27 — Paynow v3 payment initiation hardening

- Migrated Paynow payment creation from deprecated `/v1/payments` to stable `/v3/payments`.
- Implemented Paynow v3 canonical request signatures using `Api-Key`, `Idempotency-Key`, query parameters, and the exact request body.
- Added stable per-payment idempotency keys, request timeouts, strict response validation, safe provider failures, and removal of payment redirect tokens/provider payloads from checkout success logs.
- Added Paynow signature contract coverage against Paynow's published example plus gateway tests for `NEW`, `PENDING`, optional missing initial status, `ERROR`, malformed payloads, HTTP failures, production/sandbox endpoints, stable idempotency, connection failures, and missing credentials.
- Persist initial provider status through `StartPaymentService` when the gateway returns it.

## 2026-08-27 — Payment initialization retry and recovery

- Added a customer-safe retry path for orders that were durably created but whose payment initialization did not complete.
- Reuse the same unpaid local payment row for initialization retries so Paynow receives the same stable idempotency key after transport failures/timeouts and cannot create a duplicate provider payment for the retry.
- Added ownership checks for authenticated customers, checkout-session guests, and guest order tracking sessions, plus request throttling on the retry endpoint.
- Added retry UI to checkout return, account order, and guest order pages only while the order remains eligible for initialization recovery.
- Dispatch the order confirmation event as soon as the order is durably created, so a temporary payment-provider outage does not prevent the customer from receiving the order number.
- Added focused recovery coverage for successful retry, duplicate retry prevention, access isolation, order/payment amount integrity, checkout recovery UI, and provider-initialization failure behavior.

## 2026-08-27 — Paynow webhook lifecycle hardening

- Added explicit Paynow v3 notification handling for `NEW`, `PENDING`, `CONFIRMED`, `REJECTED`, `ERROR`, `EXPIRED`, and `ABANDONED`.
- Scope notification lookup by provider plus Paynow `paymentId`, verify the signed payload, and require `externalId` to match the local order before applying any state transition.
- Use Paynow `modifiedAt` to ignore exact replays and stale out-of-order notifications without generating duplicate payment/order events, and prevent paid/refunded local state from regressing on later failure notifications.
- Map terminal Paynow failures back to a retryable unpaid order while preserving the failed payment attempt; the next customer retry creates a fresh local payment row and therefore a fresh Paynow idempotency key.
- Made payment/order pending/paid/failed transitions idempotent and changed the Paynow notification endpoint to return the empty `200 OK` response expected by the provider.
- Added focused coverage for all Paynow statuses, replay/out-of-order delivery, provider isolation, external ID mismatch, signature failure, empty webhook acknowledgement, and retry after provider-declared failure.

## 2026-08-27 — Paynow provider-backed withdrawal refunds

- Replaced the local-only withdrawal refund action with a Paynow v3 refund workflow backed by a durable `payment_refunds` ledger.
- Use a stable refund idempotency key across ambiguous transport retries, persist Paynow `refundId` and provider status, and create a fresh attempt only after Paynow definitively reports `FAILED` or `CANCELLED`.
- Keep withdrawals in `refund_pending` while Paynow reports `NEW`/`PENDING`; payment/order refund state and the customer refund email are finalized only after Paynow reports `SUCCESSFUL`.
- Added provider-status reconciliation through `paynow:reconcile-refunds`, scheduled every fifteen minutes, including recovery of a provider-successful refund whose local finalization was interrupted.
- Preserve prior withdrawal statuses when a provider refund fails, prevent cumulative refunds from exceeding the captured Paynow payment, and require the source Paynow payment to be locally paid with external status `CONFIRMED`.
- Added admin feedback for pending Paynow refunds plus focused coverage for request signing, status polling, immediate success, pending-to-success reconciliation, terminal provider failure/new attempt behavior, ambiguous connection retry/idempotency, and scheduled reconciliation.
