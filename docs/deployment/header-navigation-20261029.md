# Storefront header navigation — 29 October 2026

Replace the desktop category strip with Refundacje NFZ, Odstąp od umowy, Dostawa i płatności and Kontakt, retaining the blue Kategorie button. Use a wrapping flex row instead of overflow-x-auto. Keep the category sidebar and mobile category menu; expose the same four customer links in the same order on mobile.

There was no NFZ page. The new /refundacje-nfz route renders a short enquiry/contact page without inventing reimbursement rates, eligibility rules or online fulfilment capabilities.

Validation before deployment: PHP route and header PHP-block syntax checks passed; Bash syntax passed; source checks confirm four links in order, no desktop category loop, no horizontal-scroll class, and the mobile category loop remains. Live width and click-through checks are pending deployment.

For the current shared new-shop runtime, extract this commit's resources/views/partials/storefront/header.blade.php, resources/views/pages/legal/nfz.blade.php, routes/web.php and scripts/deploy/apply-header-20261029.sh into one temporary directory with git archive. Run the extracted Bash script from the EC2 host. It verifies candidate and deployed file checksums, refuses an existing unrelated NFZ view, creates an exclusive persistent backup under storage/app/private/ui-backups/header-20261029, updates only the three source files, rebuilds route and view caches and gracefully reloads PHP-FPM for production OPcache. It attempts source/cache restoration if the apply sequence fails. No database/schema, category, importer, asset-build, payment, shipping or redirect-map changes occur.

The patch is applied to the current running app container. Include the PR source in the next application image build so a container recreation retains it. Do not rerun the script after a successful apply: the original-source checksum guard intentionally rejects an already changed header.

After deployment, check the row at the reported desktop width and a narrow desktop viewport, all four destinations (including the NFZ page and its contact link), and the mobile menu. No horizontal scroll should be needed in this row.
