data "aws_cloudfront_cache_policy" "caching_optimized" {
  name = "Managed-CachingOptimized"
}

resource "aws_cloudfront_origin_access_control" "product_media" {
  name                              = "${local.name_prefix}-product-media-oac"
  description                       = "Private S3 access for Konji Shop catalogue media"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_cloudfront_distribution" "product_media" {
  enabled         = true
  is_ipv6_enabled = true
  comment         = "${local.name_prefix} product media"
  price_class     = var.cloudfront_price_class
  http_version    = "http2and3"

  origin {
    domain_name              = aws_s3_bucket.uploads.bucket_regional_domain_name
    origin_id                = "${local.name_prefix}-product-media-s3"
    origin_access_control_id = aws_cloudfront_origin_access_control.product_media.id
  }

  default_cache_behavior {
    target_origin_id       = "${local.name_prefix}-product-media-s3"
    viewer_protocol_policy = "redirect-to-https"
    allowed_methods        = ["GET", "HEAD"]
    cached_methods         = ["GET", "HEAD"]
    compress               = true
    cache_policy_id        = data.aws_cloudfront_cache_policy.caching_optimized.id
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = true
  }

  tags = {
    Name = "${local.name_prefix}-product-media"
  }
}

data "aws_iam_policy_document" "product_media_cloudfront_read" {
  statement {
    sid     = "AllowCloudFrontReadOnly"
    effect  = "Allow"
    actions = ["s3:GetObject"]

    resources = ["${aws_s3_bucket.uploads.arn}/products/*"]

    principals {
      type        = "Service"
      identifiers = ["cloudfront.amazonaws.com"]
    }

    condition {
      test     = "StringEquals"
      variable = "AWS:SourceArn"
      values   = [aws_cloudfront_distribution.product_media.arn]
    }
  }
}

resource "aws_s3_bucket_policy" "product_media_cloudfront_read" {
  bucket = aws_s3_bucket.uploads.id
  policy = data.aws_iam_policy_document.product_media_cloudfront_read.json
}
