# SEO-03B-P5 — Isolated redirect runtime evidence

Date: 2026-10-04. Frozen manifest: 41 approved products, 58 source paths
(36 original mappings preserved, 22 additional mappings approved); four HOLD
products (6632, 6646, 6664, 6687) excluded.

The disposable localhost-only `nginx:1.27-alpine` runtime used the actual
`docker/nginx/production.conf`, enabled redirect snippet and the unchanged
P4 `candidate-58-map.conf`. Ports were bound to `127.0.0.1:18080` (HTTP)
and `127.0.0.1:18444` (HTTPS). All 348 redirects across six hostname/scheme
combinations passed, and all six unrelated control requests passed. The report
records each status, location and remote IP. No query-string propagation to
product targets. The independent 41/41 EC2 destination validation is in P4.

This evidence is not deployment authorization and does not establish that
these 58 redirects have been enabled publicly. Production map and DNS untouched.
