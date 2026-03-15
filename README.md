# Konji Shop -- Engineering & Architecture Handbook

**Project:** Konji Shop\
**Maintainer:** Tomasz Maciejczuk\
**Version:** 1.0

------------------------------------------------------------------------

## 1. Project Overview

Konji Shop is an **e-commerce platform for medical supplies** targeted
at:

-   Hospitals
-   Clinics
-   Medical professionals
-   Individual patients

Core capabilities:

-   Product catalog
-   Inventory management
-   Orders
-   Payments
-   Shipping
-   B2B accounts
-   Audit logging
-   Secure checkout

------------------------------------------------------------------------

## 2. Technology Stack

### Backend

-   Laravel 12
-   PHP 8.5
-   Livewire + Volt
-   Redis
-   MySQL

### Frontend

-   Blade
-   Livewire components
-   TailwindCSS
-   Vite

### Infrastructure

-   Docker
-   Docker Compose
-   Nginx
-   Mailpit
-   GitHub
-   GitHub Actions

------------------------------------------------------------------------

## 3. High-Level System Architecture

Internet\
↓\
Nginx (reverse proxy)\
↓\
Laravel App (PHP-FPM container)

Connected services:

-   MySQL database
-   Redis (cache + queues)
-   Queue workers
-   Scheduler

------------------------------------------------------------------------

## 4. Docker Architecture

### Containers

  Container   Purpose
  ----------- -------------------------
  app         Laravel PHP runtime
  web         Nginx server
  db          MySQL database
  redis       cache + queue broker
  queue       Laravel queue worker
  scheduler   Laravel scheduled tasks
  mailpit     local email testing

------------------------------------------------------------------------

## 5. Project Directory Structure

    konji-shop/
    │
    ├─ app/
    ├─ bootstrap/
    ├─ config/
    ├─ database/
    ├─ docker/
    │   ├─ nginx/
    │   ├─ php/
    │   └─ entrypoint.sh
    ├─ public/
    ├─ resources/
    ├─ routes/
    ├─ storage/
    ├─ tests/
    ├─ compose.yaml
    └─ Dockerfile

------------------------------------------------------------------------

## 6. Domain Model

### Product

Fields:

-   id
-   name
-   description
-   sku
-   price
-   stock_quantity
-   category_id
-   status
-   created_at

### Category

-   id
-   name
-   slug
-   parent_category

Supports hierarchical categories.

### Customer

-   id
-   email
-   password
-   name
-   company_name
-   vat_number
-   account_type

Account types:

-   retail
-   hospital
-   distributor

### Order

-   id
-   customer_id
-   status
-   total_amount
-   currency
-   payment_method
-   shipping_method
-   created_at

### Order Item

-   id
-   order_id
-   product_id
-   quantity
-   unit_price

------------------------------------------------------------------------

## 7. Application Architecture

Layered architecture:

Controller\
↓\
Services / Actions\
↓\
Models\
↓\
Database

### Services

Examples:

-   OrderService
-   PaymentService
-   InventoryService
-   CartService

### Actions

Examples:

-   CreateOrderAction
-   ProcessPaymentAction
-   UpdateInventoryAction

------------------------------------------------------------------------

## 8. Queue Architecture

Queues handle:

-   email sending
-   order processing
-   payment callbacks
-   stock updates
-   notifications

Queue backend:

Redis

Worker command:

    php artisan queue:work

------------------------------------------------------------------------

## 9. Scheduler

Scheduler runs tasks like:

-   inventory reconciliation
-   abandoned cart reminders
-   payment retries
-   order cleanup

Executed every minute:

    php artisan schedule:run

------------------------------------------------------------------------

## 10. Database

Primary DB:

MySQL 8.4

Important indexes:

-   products.sku
-   products.category_id
-   orders.customer_id
-   order_items.order_id

Future improvements:

-   read replicas
-   query caching
-   partitioning

------------------------------------------------------------------------

## 11. Caching Strategy

Redis used for:

-   session storage
-   cache store
-   queue broker

Example cache keys:

-   product:{id}
-   category_tree
-   homepage_products

------------------------------------------------------------------------

## 12. File Storage

Initial storage:

local filesystem

Future storage:

-   AWS S3
-   CloudFront CDN

Used for:

-   product images
-   invoices
-   documents

------------------------------------------------------------------------

## 13. Email System

Development:

Mailpit

Production options:

-   AWS SES
-   SendGrid

------------------------------------------------------------------------

## 14. Security

### Authentication

Laravel authentication with:

-   bcrypt password hashing
-   email verification
-   password reset

Future:

2FA

### Authorization

Policies and Gates:

-   ProductPolicy
-   OrderPolicy
-   AdminPolicy

------------------------------------------------------------------------

## 15. CI/CD Pipeline

Proposed pipeline:

1.  Push to main
2.  Run tests
3.  Build Docker image
4.  Push image to registry
5.  Deploy to server

------------------------------------------------------------------------

## 16. Production Infrastructure (Future AWS)

Cloudflare\
↓\
AWS Load Balancer\
↓\
Application containers (Laravel)

Services:

-   Redis
-   Queue workers
-   Scheduler

Database:

AWS RDS MySQL

------------------------------------------------------------------------

## 17. Scaling Strategy

Stage 1: Single VPS with Docker

Stage 2: Load balancer + multiple app containers

Stage 3: AWS ECS or Kubernetes with RDS and Elasticache

------------------------------------------------------------------------

## 18. Monitoring

Tools planned:

-   Sentry
-   Prometheus
-   Grafana

Logs:

ELK stack

------------------------------------------------------------------------

## 19. Performance Strategy

Focus areas:

-   product catalog queries
-   search
-   checkout flow

Optimizations:

-   database indexes
-   caching
-   background processing

------------------------------------------------------------------------

## 20. Development Workflow

Start environment:

    docker compose up -d

Useful commands:

    docker compose exec app php artisan migrate
    docker compose exec app php artisan tinker
    docker compose exec app php artisan optimize:clear

------------------------------------------------------------------------

## 21. Testing

Testing framework:

Pest

Test types:

-   unit tests
-   feature tests
-   integration tests

------------------------------------------------------------------------

## 22. Backup Strategy

Database backups:

daily

File storage backups:

weekly

------------------------------------------------------------------------

## 23. Disaster Recovery

Recovery procedure:

1.  restore database backup
2.  rebuild containers
3.  redeploy application

------------------------------------------------------------------------

## 24. Roadmap

Planned features:

-   advanced product search
-   hospital accounts
-   bulk orders
-   subscription orders
-   discount engine
-   multi-warehouse inventory

------------------------------------------------------------------------

## Maintainer

Tomasz Maciejczuk\
Konji Shop

------------------------------------------------------------------------

## Start

cp .env.example .env

docker compose build
docker compose up -d

docker exec -it konji_shop_app php artisan key:generate
docker exec -it konji_shop_app php artisan migrate
