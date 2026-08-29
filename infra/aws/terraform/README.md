# Konji Shop AWS Terraform

This Terraform stack creates the first AWS staging/production-style environment for Konji Shop:

- VPC with public EC2 subnets and private RDS subnets
- Ubuntu 24.04 EC2 Docker host with Elastic IP
- RDS MySQL
- private S3 uploads bucket with encryption, versioning and public access block
- CloudFront product-media distribution using Origin Access Control (OAC) against the private S3 bucket
- EC2 IAM role for S3 uploads and AWS Systems Manager
- optional Route 53 A record
- no-additional-charge S3 gateway endpoint for application-to-S3 traffic
- bounded RDS CloudWatch log retention and optional free AWS Budget alerts

It is designed to match the app deployment patch using `docker-compose.prod.yml`.

## 1. Prerequisites

Install and configure locally:

```bash
aws configure
terraform version
```

Create an EC2 key pair in AWS first if you want SSH access, or use AWS Systems Manager Session Manager.

## 2. Configure variables

```bash
cd infra/aws/terraform
cp terraform.tfvars.example terraform.tfvars
nano terraform.tfvars
```

At minimum, change:

```hcl
ssh_cidr_blocks = ["YOUR_PUBLIC_IP/32"]
ssh_key_name    = "your-existing-key-name"
```

The MVP cost baseline intentionally starts small and scales from measured production load. A ready-to-edit production example is included:

```bash
cp production.tfvars.example production.tfvars
```

Its baseline is:

```hcl
instance_type               = "t3a.small"
rds_instance_class          = "db.t4g.micro"
rds_deletion_protection     = true
rds_skip_final_snapshot     = false
rds_backup_retention_days   = 7
rds_cloudwatch_log_exports  = ["error"]
cloudwatch_log_retention_days = 14
cloudfront_price_class       = "PriceClass_100"
ssh_cidr_blocks              = ["YOUR_PUBLIC_IP/32"]
```

`t3a.small` keeps the existing x86_64 image/toolchain while reducing compute cost versus the equivalent T3 class. Keep `db.t4g.micro` until CPU, connections, memory pressure, or query latency show that the database needs a larger class.

## 3. Create infrastructure

```bash
terraform init
terraform fmt
terraform validate
terraform plan
terraform apply
```

Useful outputs:

```bash
terraform output app_public_ip
terraform output rds_endpoint
terraform output s3_bucket
terraform output product_media_url
terraform output -raw db_password
```

`db_password` is sensitive and is stored in Terraform state. Keep your state file private. For production, move Terraform state to a locked remote backend before going live.


## Cost controls built into this stack

- No NAT Gateway or Application Load Balancer is required for the single-host MVP.
- The VPC uses an S3 **Gateway** endpoint, which has no additional hourly/data-processing charge.
- The default EC2 class is `t3a.small`; the default RDS class remains `db.t4g.micro`.
- RDS exports only the error log by default. Add `slowquery` temporarily when profiling rather than ingesting it forever.
- Terraform owns RDS CloudWatch log groups with a 14-day default retention instead of CloudWatch's indefinite-retention default.
- S3 removes abandoned multipart-upload parts after 7 days and noncurrent object versions after 30 days. Current objects are not expired by this rule.
- CloudFront remains `PriceClass_100`.
- Set `cost_budget_notification_email` to create a monthly AWS Budget with 80%, 100%, and forecasted-100% alerts. Budget monitoring notifications do not add an AWS Budgets charge.
- One public IPv4 address remains necessary for the direct-to-EC2 MVP origin and is therefore an explicit baseline cost. Do not add more public IPv4 addresses unless the architecture changes.

Do not add NAT Gateway, ALB, ElastiCache, OpenSearch, extra EC2 hosts, or interface VPC endpoints just because they are common AWS patterns. Add them only when production measurements or availability requirements justify their recurring cost.

## 4. Prepare Laravel `.env` on EC2

SSH to the host:

```bash
ssh ubuntu@$(terraform output -raw app_public_ip)
```

Go to the app directory:

```bash
cd /var/www/konji-shop
```

If the repository was not cloned by user-data, clone it manually:

```bash
git clone <your-repo-url> /var/www/konji-shop
cd /var/www/konji-shop
```

Create `.env`:

```bash
cp .env.production.example .env
nano .env
```

Use Terraform outputs:

```env
DB_CONNECTION=mysql
DB_HOST=<terraform output rds_endpoint>
DB_PORT=3306
DB_DATABASE=konji_shop
DB_USERNAME=konji_shop
DB_PASSWORD=<terraform output -raw db_password>

AWS_DEFAULT_REGION=<terraform output s3_region>
AWS_BUCKET=<terraform output s3_bucket>
FILESYSTEM_DISK=s3
PUBLIC_FILESYSTEM_DRIVER=local
PUBLIC_FILESYSTEM_BUCKET=<terraform output s3_bucket>
PUBLIC_FILESYSTEM_URL=<terraform output product_media_url>
PUBLIC_FILESYSTEM_VISIBILITY=private
PUBLIC_FILESYSTEM_CACHE_CONTROL=public,max-age=86400,s-maxage=604800

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

For S3 on EC2, you can normally leave `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` empty because the EC2 instance role has S3 access.

Keep `PUBLIC_FILESYSTEM_DRIVER=local` until the catalogue copy and CDN probe have passed. Follow `docs/deployment/product-media-cloudfront-cutover.md`, then switch it to `s3`.

The product-media distribution defaults to `PriceClass_100` to keep the MVP edge footprint cost-conscious for a primarily European audience. Expand the price class only when real traffic requires it.

## 5. Deploy the app

```bash
bash scripts/deploy/aws-production.sh
```

Then check containers:

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f queue
docker compose -f docker-compose.prod.yml logs -f scheduler
```

## 6. Smoke test

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan test
docker compose -f docker-compose.prod.yml exec app php artisan route:list
```

Browser checks:

- homepage
- category page
- product page
- cart
- checkout
- login
- admin login
- admin products
- admin orders
- guest order tracking
- a small `mobilex:inspect-products --limit=10`
- a small `mobilex:import --limit=10`

## 7. HTTPS

This Terraform stack opens ports 80 and 443 and creates an Elastic IP. HTTPS can be added in either of these ways:

1. EC2-level Caddy/Certbot/Let’s Encrypt reverse proxy.
2. Later upgrade to an Application Load Balancer with ACM certificate.

For the first staging deployment, HTTP is acceptable if the environment is not used by real customers and is access-controlled. For production, HTTPS is mandatory.

## 8. Destroy staging

For staging only:

```bash
terraform destroy
```

Do not run destroy against production unless you intentionally want to remove the environment. Enable RDS deletion protection and final snapshots for production.
