# Implementation Log

## 2026-08-27 — Paynow v3 payment initiation hardening

- Migrated Paynow payment creation from deprecated `/v1/payments` to stable `/v3/payments`.
- Implemented Paynow v3 canonical request signatures using `Api-Key`, `Idempotency-Key`, query parameters, and the exact request body.
- Added stable per-payment idempotency keys, request timeouts, strict response validation, safe provider failures, and removal of payment redirect tokens/provider payloads from checkout success logs.
- Added Paynow signature contract coverage against Paynow's published example plus gateway tests for `NEW`, `PENDING`, optional missing initial status, `ERROR`, malformed payloads, HTTP failures, production/sandbox endpoints, stable idempotency, connection failures, and missing credentials.
- Persist initial provider status through `StartPaymentService` when the gateway returns it.
