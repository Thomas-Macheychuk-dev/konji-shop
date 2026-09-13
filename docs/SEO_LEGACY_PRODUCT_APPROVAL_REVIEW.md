# SEO-02C legacy product approval review

`php artisan seo:prepare-legacy-product-approval-review` turns SEO-02B evidence into explicit review cohorts. It is still evidence-only:

- database writes: **none**;
- redirects/runtime changes: **none**;
- redirects approved: **always zero**.

The command deliberately does **not** treat `identifier_agreement` as sufficient for approval. Legacy numeric identifiers can be reused by an unrelated current product even when both the target variant SKU and external parent SKU agree. A real production example is legacy orthopaedic product `4009` colliding with current `Biustonosz LANA 4009`.

Top-priority approval candidates therefore require all of the following:

1. SEO-02B classification `exact_identifier_and_name`;
2. target product is storefront-reachable;
3. when the evidence contains an exact variant candidate, that variant is active.

Even these records remain `redirect_approved=false` until a later explicit approval step.

For active `identifier_agreement` records the command records exact normalized semantic-token overlap after removing the legacy identifier and low-value supplier/generic tokens. This is a review-priority signal only, not an approval rule.

Review classes include:

- `approval_candidate_exact_identifier_and_name`;
- `review_identifier_agreement_semantic_support`;
- `review_identifier_agreement_name_divergence`;
- `blocked_target_draft_strong`;
- `blocked_matched_variant_not_active`;
- `review_active_parent_candidate`;
- `review_active_variant_candidate`;
- `review_active_exact_name_only`;
- `blocked_target_draft_weak`;
- `identifier_conflict`;
- `duplicate_legacy_index`;
- `missing_legacy_index`;
- `unmatched`.

The final SEO-01 CSV is also supplied so each canonical legacy product can carry all known legacy source paths/aliases into the review manifest. This is important because one approved product may require more than one old source URL to redirect directly to the final Konji target.
