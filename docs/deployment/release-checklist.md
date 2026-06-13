# Konji Shop production release checklist

## Before deploy

```bash
docker compose exec app php artisan test
docker compose exec app php artisan shop:check
docker compose exec app php artisan polkurier:check
```

Confirm:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL is the final HTTPS URL
Paynow sandbox is disabled only after production credentials are ready
RDS backup/PITR is enabled
S3 bucket exists and is private
S3 write/read works
MAIL_FROM_ADDRESS is on the shop domain
SPF/DKIM/DMARC are configured
Polkurier credentials are production-ready
Legal pages are final, not draft text
```

## Deploy

```bash
APP_PATH=/var/www/konji-shop BRANCH=main ./scripts/deploy/aws-production.sh
```

## After deploy

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs --tail=100 app
docker compose -f docker-compose.prod.yml logs --tail=100 queue
docker compose -f docker-compose.prod.yml logs --tail=100 scheduler
curl -fsS http://127.0.0.1/up
```

Then manually test:

```text
homepage
category page
product page
cart
checkout
Paynow test payment flow
order confirmation email
admin order view
shipment creation test
shipment tracking test
withdrawal request flow
```
