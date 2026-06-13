# AWS deployment: EC2 + RDS + S3

This is the first production deployment shape for Konji Shop. It keeps the app on one Docker Compose host and moves the database and uploaded/generated files to managed AWS services.

## Target architecture

```text
Internet / DNS
  -> EC2 public IP or load balancer
      -> Docker Compose web container, port 80
          -> app php-fpm container
          -> queue worker container
          -> scheduler container
          -> Redis container, internal only
  -> RDS MySQL
  -> S3 bucket for files, product images, shipment labels and protocols
```

Use this first because it matches the local Docker Compose setup and keeps the operational surface small. Move Redis to ElastiCache and the web tier to ECS/Fargate later if traffic requires it.

## AWS resources

Create these resources before deploying:

1. EC2 Ubuntu 24.04 LTS instance, normally `t3.small` or `t3.medium` for the first launch.
2. Elastic IP attached to EC2, unless an Application Load Balancer is used.
3. RDS MySQL 8.x instance in private subnets.
4. S3 bucket for production application files.
5. IAM user or instance role with the minimum S3 permissions the app needs.
6. Security groups:
   - EC2: allow 22 from your IP, 80/443 from the internet or from the load balancer only.
   - RDS: allow 3306 from the EC2 security group only.
   - Redis container: no public port, internal Docker network only.
7. DNS record pointing the shop domain to the Elastic IP or load balancer.
8. TLS certificate: terminate HTTPS at the load balancer, CloudFront, or a host-level reverse proxy.

## First EC2 bootstrap

SSH to the instance and run:

```bash
sudo APP_PATH=/var/www/konji-shop ./scripts/aws/bootstrap-ec2.sh
```

Then log out and back in so the deployment user has Docker group permissions.

Clone the repository:

```bash
sudo mkdir -p /var/www
sudo chown -R ubuntu:ubuntu /var/www
cd /var/www
git clone git@github.com:Thomas-Macheychuk-dev/konji-shop.git konji-shop
cd konji-shop
```

Create the production env file:

```bash
cp .env.production.example .env
php -r "echo 'Generate APP_KEY locally with: php artisan key:generate --show'.PHP_EOL;"
```

Fill in real production values in `.env`. Do not commit `.env`.

## Required production env decisions

Use these values unless there is a specific reason not to:

```env
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stderr
LOG_LEVEL=warning
DB_HOST=<rds-endpoint>
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
FILESYSTEM_DISK=s3
POLKURIER_LABEL_DISK=s3
POLKURIER_PROTOCOL_DISK=s3
PAYNOW_SANDBOX=false
SESSION_SECURE_COOKIE=true
```

Set `APP_URL` to the final HTTPS domain, not the EC2 IP.

## Deploy

From the server:

```bash
APP_PATH=/var/www/konji-shop BRANCH=main ./scripts/deploy/aws-production.sh
```

The script will:

1. Pull the selected branch.
2. Build the production app and web images.
3. Start Redis and app containers.
4. Run migrations.
5. Rebuild Laravel caches.
6. Start web, queue and scheduler containers.
7. Restart queue workers.
8. Check `/up`.

## GitHub Actions deployment

The repository includes `.github/workflows/deploy-prod.yml`. Configure these repository secrets before using it:

```text
AWS_PROD_HOST       EC2 hostname or public IP
AWS_PROD_USER       normally ubuntu
AWS_PROD_SSH_KEY    private SSH key allowed to deploy on the EC2 instance
AWS_PROD_PATH       /var/www/konji-shop
```

The workflow runs tests first, then SSHes to the EC2 instance and runs `scripts/deploy/aws-production.sh`.

## Smoke test after each deployment

Run this checklist manually after deployment:

```text
/ loads
/up returns success
/product page loads
category page loads
cart add/update/remove works
checkout starts
Paynow return works
Paynow webhook works
admin login works
admin order view works
Polkurier diagnostics works
shipment label/protocol storage works on S3
queue worker processes emails/jobs
scheduler runs polkurier:sync-shipments
order confirmation email arrives
withdrawal email arrives
```

## Rollback

For a simple rollback:

```bash
cd /var/www/konji-shop
git log --oneline -n 10
git checkout <previous-good-commit>
docker compose -f docker-compose.prod.yml build app web
docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

Database migrations are not automatically rolled back. For migrations that change production data, prepare a manual rollback plan before deployment.

## Backups

Minimum production backup policy:

```text
RDS automated backups with point-in-time restore enabled.
Manual RDS snapshot before risky releases.
S3 versioning enabled for the production bucket.
Documented restore test before launch.
```

## Notes

- Only one scheduler container should run in production.
- Do not expose Redis, MySQL, Mailpit, or PHP-FPM publicly.
- Keep `APP_DEBUG=false` in production.
- Keep product import jobs off peak hours until catalogue data is stable.
