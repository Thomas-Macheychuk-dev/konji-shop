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
