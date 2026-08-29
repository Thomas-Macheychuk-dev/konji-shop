<?php

it('keeps the single-host MVP free of NAT gateway and paid interface endpoints', function (): void {
    $network = file_get_contents(base_path('infra/aws/terraform/network.tf'));

    expect($network)
        ->not->toContain('resource "aws_nat_gateway"')
        ->not->toContain('vpc_endpoint_type = "Interface"')
        ->toContain('resource "aws_vpc_endpoint" "s3"')
        ->toContain('vpc_endpoint_type = "Gateway"')
        ->toContain('com.amazonaws.${var.aws_region}.s3');
});

it('uses cost-conscious EC2 RDS and CloudFront defaults', function (): void {
    $variables = file_get_contents(base_path('infra/aws/terraform/variables.tf'));
    $production = preg_replace(
        '/\s+/',
        ' ',
        file_get_contents(base_path('infra/aws/terraform/production.tfvars.example')),
    );

    expect($variables)
        ->toContain('default     = "t3a.small"')
        ->toContain('default     = "db.t4g.micro"')
        ->toContain('default     = "PriceClass_100"')
        ->and($production)
        ->toContain('instance_type = "t3a.small"')
        ->toContain('rds_instance_class = "db.t4g.micro"')
        ->toContain('rds_max_allocated_storage_gb = 50')
        ->toContain('cloudfront_price_class = "PriceClass_100"');
});

it('bounds CloudWatch and S3 storage growth', function (): void {
    $rds = file_get_contents(base_path('infra/aws/terraform/rds.tf'));
    $cloudwatch = file_get_contents(base_path('infra/aws/terraform/cloudwatch.tf'));
    $s3 = file_get_contents(base_path('infra/aws/terraform/s3.tf'));
    $variables = file_get_contents(base_path('infra/aws/terraform/variables.tf'));

    expect($rds)
        ->toContain('enabled_cloudwatch_logs_exports = var.rds_cloudwatch_log_exports')
        ->and($cloudwatch)
        ->toContain('retention_in_days = var.cloudwatch_log_retention_days')
        ->and($variables)
        ->toContain('default     = ["error"]')
        ->toContain('default     = 14')
        ->toContain('default     = 30')
        ->toContain('default     = 7')
        ->and($s3)
        ->toContain('noncurrent_days = var.s3_noncurrent_version_retention_days')
        ->toContain('days_after_initiation = var.s3_abort_incomplete_multipart_days')
        ->toContain('expired_object_delete_marker = true');
});

it('provides optional free monthly AWS budget notifications', function (): void {
    $cost = file_get_contents(base_path('infra/aws/terraform/cost.tf'));

    expect($cost)
        ->toContain('resource "aws_budgets_budget" "monthly_cost"')
        ->toContain('trimspace(var.cost_budget_notification_email) != ""')
        ->toContain('threshold                  = 80')
        ->toContain('threshold                  = 100')
        ->toContain('notification_type          = "FORECASTED"');
});

it('rotates container logs instead of allowing the EC2 volume to grow without bound', function (): void {
    $compose = file_get_contents(base_path('docker-compose.prod.yml'));
    $env = file_get_contents(base_path('.env.production.example'));

    expect($compose)
        ->toContain('x-logging: &default-logging')
        ->toContain('max-size: "${DOCKER_LOG_MAX_SIZE:-10m}"')
        ->toContain('max-file: "${DOCKER_LOG_MAX_FILES:-3}"')
        ->toContain('logging: *default-logging')
        ->and($env)
        ->toContain('DOCKER_LOG_MAX_SIZE=10m')
        ->toContain('DOCKER_LOG_MAX_FILES=3');
});
