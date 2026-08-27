<?php

it('defines private S3 product-media delivery through CloudFront origin access control', function (): void {
    $cloudfront = file_get_contents(base_path('infra/aws/terraform/cloudfront.tf'));
    $s3 = file_get_contents(base_path('infra/aws/terraform/s3.tf'));

    expect($cloudfront)
        ->toContain('resource "aws_cloudfront_origin_access_control" "product_media"')
        ->toContain('signing_behavior                  = "always"')
        ->toContain('signing_protocol                  = "sigv4"')
        ->toContain('aws_s3_bucket.uploads.bucket_regional_domain_name')
        ->toContain('origin_access_control_id = aws_cloudfront_origin_access_control.product_media.id')
        ->toContain('viewer_protocol_policy = "redirect-to-https"')
        ->toContain('data.aws_cloudfront_cache_policy.caching_optimized.id')
        ->toContain('price_class     = var.cloudfront_price_class')
        ->toContain('cloudfront.amazonaws.com')
        ->toContain('${aws_s3_bucket.uploads.arn}/products/*')
        ->toContain('AWS:SourceArn')
        ->toContain('aws_cloudfront_distribution.product_media.arn')
        ->and($s3)
        ->toContain('block_public_acls       = true')
        ->toContain('block_public_policy     = true')
        ->toContain('ignore_public_acls      = true')
        ->toContain('restrict_public_buckets = true')
        ->toContain('object_ownership = "BucketOwnerEnforced"');
});

it('keeps the default CloudFront price class cost-conscious for a European MVP', function (): void {
    $variables = file_get_contents(base_path('infra/aws/terraform/variables.tf'));
    $outputs = file_get_contents(base_path('infra/aws/terraform/outputs.tf'));

    expect($variables)
        ->toContain('variable "cloudfront_price_class"')
        ->toContain('default     = "PriceClass_100"')
        ->and($outputs)
        ->toContain('output "product_media_url"')
        ->toContain('https://${aws_cloudfront_distribution.product_media.domain_name}');
});
