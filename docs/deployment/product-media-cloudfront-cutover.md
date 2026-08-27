# Product media: private S3 + CloudFront cutover

This runbook moves catalogue media away from the EC2/Docker public volume without making the S3 bucket public.

## Architecture

```text
browser
  -> HTTPS CloudFront distribution
      -> Origin Access Control (SigV4)
          -> private S3 bucket

Laravel/importers
  -> EC2 instance role
      -> private S3 bucket
```

The application keeps using the logical Laravel disk name `public`. Only the physical backing driver changes.

CloudFront is granted S3 read access only to `products/*`. Shipment labels, protocols, and other private application objects in the shared bucket are not exposed through the CDN.

## Cost-conscious MVP defaults

- CloudFront `PriceClass_100` (North America + Europe/Israel) unless production traffic demonstrates a need for a broader edge footprint.
- AWS managed `CachingOptimized` cache policy.
- Compression enabled.
- No CloudFront access logging in the MVP baseline; enable it only when an operational need justifies the extra S3/logging cost.
- Product objects default to:

```text
Cache-Control: public,max-age=86400,s-maxage=604800
```

This gives browsers a one-day TTL and shared caches a seven-day TTL. Do not use `immutable` or a one-year browser TTL until catalogue object names are content-addressed/versioned, because current importer paths can be overwritten.

## 1. Deploy Terraform first

```bash
cd infra/aws/terraform
terraform init
terraform fmt -check
terraform validate
terraform plan
terraform apply
```

Capture the outputs:

```bash
terraform output -raw s3_bucket
terraform output -raw product_media_url
```

Use them in the production environment:

```env
PUBLIC_FILESYSTEM_BUCKET=<terraform output -raw s3_bucket>
PUBLIC_FILESYSTEM_URL=<terraform output -raw product_media_url>
PUBLIC_FILESYSTEM_CACHE_CONTROL=public,max-age=86400,s-maxage=604800
PUBLIC_FILESYSTEM_VISIBILITY=private
```

Keep this during the copy/verification phase:

```env
PUBLIC_FILESYSTEM_DRIVER=local
```

Then rebuild Laravel configuration cache.

## 2. Verify the S3/CloudFront boundary

Configuration-only check:

```bash
php artisan shop:check-public-media
```

Live probe (one temporary S3 object, one CDN GET, then best-effort cleanup):

```bash
php artisan shop:check-public-media --probe
```

Do not continue unless this passes.

## 3. Dry-run the catalogue copy

```bash
php artisan shop:migrate-public-media
```

Review source/planned/already-present counts.

## 4. Copy to S3

```bash
php artisan shop:migrate-public-media --write
```

The command intentionally keeps the local source files for rollback.

Re-run it until all source objects are either copied or already present and failures are zero.

## 5. Verify CloudFront against real catalogue files

Open a sample from each class through `PUBLIC_FILESYSTEM_URL`:

- product gallery image
- attribute/swatch image
- localized supplier document/PDF
- inline product-description image

Verify HTTPS 200 responses and correct file contents.

## 6. Rewrite legacy description URLs

Only after the real objects exist on S3 and the CDN URL is verified:

```bash
php artisan shop:migrate-public-media --write --rewrite-descriptions
```

## 7. Switch the logical public disk

Change:

```env
PUBLIC_FILESYSTEM_DRIVER=s3
```

Then rebuild configuration cache and smoke-test:

```bash
php artisan config:cache
php artisan shop:check-public-media --probe
```

Check storefront product/category pages and supplier documents.

## Rollback

Because the source copy was retained, rollback is configuration-only while the old EC2 volume still exists:

```env
PUBLIC_FILESYSTEM_DRIVER=local
```

Rebuild configuration cache. Do not delete the local source until the S3/CloudFront path has been stable in production and a later cleanup patch explicitly removes it.
