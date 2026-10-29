# Live staging verification and follow-up

The owner ran the first repair successfully: DATABASE_COMMIT=PASS, APPLY=PASS, POST_REPAIR_STATE=PASS and VERIFY=PASS. Its original manifest and scripts remain unchanged as historical evidence.

Live browser checks confirmed the 12 root departments, the restored Eldan clothing breadcrumb, recovered Butterfly FORTE category, and the FootWave sports-insole category containing FOOTBALL, PLAY and SPORT.

The same check exposed an error in the first plan's broad product-name matching: `kolo` matched parts of `czterokołowy`/`trzykołowy` and `kolor`. Fourteen complete walkers had been moved into rollator accessories; three crutch/stick tips were in wheelchair accessories. One drinking cup was grouped with medicine-storage aids. The follow-up uses 18 explicit product IDs and exact source/name identities; it performs no new name matching. The exact review is `resources/catalogue/20261029/followup.json`.

The UI also labels product cards using the first attached category rather than the `is_primary` pivot. The Blade change selects the primary category with a fallback for older uncategorized records. It adds no database queries and changes no memberships.

## Execution after the first repair

Extract the reviewed follow-up commit's scripts, operations and product-card template using git archive. Copy the scripts and template into the same `/tmp/konji-catalogue-repair-20261029` directory inside the existing app container; copy the operations JSON to `/tmp/konji-catalogue-followup.json`. Keep `/tmp/konji-catalogue-plan-20261029.json` unchanged: its checksum must match the original receipt.

Run the standalone full-snapshot follow-up test with:

```bash
php -d memory_limit=512M /tmp/konji-catalogue-repair-20261029/test-followup.php \
  --source=/tmp/category-audit.json \
  --plan=/tmp/konji-catalogue-plan-20261029.json \
  --operations=/tmp/konji-catalogue-followup.json
```

Then run `followup.php --mode=dry-run`, `--mode=apply`, and `--mode=verify` in that order. Each apply checks the exact post-first-repair receipt, rejects later edits, and updates only the 18 products' category memberships. Category rows and all unrelated memberships are verified unchanged. The expected full membership total is 51,614; all 14,627 products retain exactly one primary category and the 12 departments remain unchanged.

The follow-up creates its own persistent backup and receipt at `storage/app/private/catalogue-repairs/catalogue-20261029-followup-v1.json` and its `.receipt.json` companion. Use `followup.php --mode=rollback` to restore the first repair's exact state; it refuses later edits. To roll all the way back to the original pre-repair catalogue, roll back the follow-up first, then use `run.php --mode=rollback`. After the follow-up, use its own verify command: the original run.php verify intentionally no longer matches the corrected state.

After data verification, run `apply-card.php` from the Laravel application working directory. It requires the reviewed original or already-correct template checksum, writes a persistent exclusive backup, then atomically replaces the template. It refuses to overwrite an unexpected deployed template. Run `php artisan view:clear` and gracefully reload the PHP-FPM master with `kill -USR2 1` inside the app container: production OPcache has timestamp validation disabled. This does not recreate the container or change the web/queue/scheduler services.

The template patch is a targeted fix to the current running container. It is also committed in the PR source and must be included in the next built application image; recreating the container from an older image would restore the old template. Category repairs persist independently in the database.

## Validation

PHP syntax checks passed for the runner, test and atomic template patcher. The complete first-repair simulation was used to validate all 18 corrections: fourteen complete walking aids move to walkers, actual spare wheels stay accessories, wheelchair footrest/brake stay accessories, three tips move to crutch/stick accessories, and the drinking aid moves to daily-living aids. The test checks all 14,627 primary assignments, 51,614 resulting memberships, valid category paths, identity-drift rejection and arbitrary production category-ID resolution from the original receipt. Live MySQL follow-up execution and verification of the updated card label remain pending until the owner runs this follow-up.
