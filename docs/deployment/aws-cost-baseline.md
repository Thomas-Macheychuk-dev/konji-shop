# AWS production MVP cost baseline

This baseline keeps Konji Shop fast and production-capable while minimizing recurring AWS services.

## Keep for MVP

- one `t3a.small` EC2 Docker host;
- one `db.t4g.micro` RDS MySQL instance;
- one Elastic IP/public IPv4 address;
- private S3 + CloudFront `PriceClass_100`;
- local Redis container;
- private RDS subnets;
- S3 Gateway VPC endpoint;
- Route 53 only when DNS is hosted in AWS.

## Do not add before metrics justify it

- NAT Gateway;
- Application Load Balancer;
- ElastiCache;
- OpenSearch;
- extra application hosts;
- paid interface VPC endpoints;
- global CloudFront price class;
- always-on crawler/import infrastructure.

## Cost guards

- Docker logs rotate at 10 MiB x 3 files per container by default.
- Laravel production logs go to stderr at `warning` level.
- RDS exports only `error` by default and Terraform sets 14-day CloudWatch retention.
- S3 aborts incomplete multipart uploads after 7 days.
- S3 keeps noncurrent object versions for 30 days, then expires them.
- RDS storage starts at 20 GiB and the production example caps autoscaling at 50 GiB.
- AWS Budget alerts are configured when `cost_budget_notification_email` is supplied.

## Scale triggers

Scale EC2 only when sustained CPU/memory pressure, FPM saturation, queue latency, or Core Web Vitals/TTFB show the host is the bottleneck.

Scale RDS only when query latency, CPU, memory pressure, connection pressure, storage throughput, or lock contention show the database is the bottleneck.

Keep search on MySQL until measured search latency/relevance requirements justify OpenSearch.

## Production apply review

Before `terraform apply` for production:

1. copy `infra/aws/terraform/production.tfvars.example` to an ignored production tfvars file;
2. replace the SSH CIDR and budget notification email;
3. verify `terraform plan` does not add NAT Gateway, ALB, ElastiCache, or OpenSearch;
4. verify RDS deletion protection and final snapshot settings;
5. verify the budget threshold is appropriate for the expected baseline;
6. apply and confirm the AWS Budget subscription email.
