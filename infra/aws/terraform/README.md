# Konji Shop AWS Terraform

This Terraform stack creates the first AWS staging/production-style environment for Konji Shop:

- VPC with public EC2 subnets and private RDS subnets
- Ubuntu 24.04 EC2 Docker host with Elastic IP
- RDS MySQL
- private S3 uploads bucket with encryption, versioning and public access block
- EC2 IAM role for S3 uploads and AWS Systems Manager
- optional Route 53 A record

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

For staging, the defaults are intentionally small. For production, use at least:

```hcl
instance_type              = "t3.medium"
rds_instance_class         = "db.t4g.small"
rds_deletion_protection    = true
rds_skip_final_snapshot    = false
rds_backup_retention_days  = 14
ssh_cidr_blocks            = ["YOUR_PUBLIC_IP/32"]
```

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
terraform output -raw db_password
```

`db_password` is sensitive and is stored in Terraform state. Keep your state file private. For production, move Terraform state to a locked remote backend before going live.

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

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

For S3 on EC2, you can normally leave `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` empty because the EC2 instance role has S3 access.

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
