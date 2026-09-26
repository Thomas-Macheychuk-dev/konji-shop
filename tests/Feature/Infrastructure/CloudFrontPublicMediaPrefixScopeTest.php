<?php

it('allows CloudFront to read only the public catalogue media prefixes', function (): void {
    $cloudfront = file_get_contents(
        base_path('infra/aws/terraform/cloudfront.tf')
    );

    expect($cloudfront)
        ->toContain('"${aws_s3_bucket.uploads.arn}/products/*"')
        ->toContain('"${aws_s3_bucket.uploads.arn}/swatches/*"')
        ->not->toContain('"${aws_s3_bucket.uploads.arn}/*"');
});
