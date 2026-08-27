<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\PublicMediaDeliveryReadiness;
use Illuminate\Console\Command;
use Throwable;

final class CheckPublicMediaDeliveryCommand extends Command
{
    protected $signature = 'shop:check-public-media
        {--probe : Write one temporary private S3 object and fetch it through the configured CloudFront/CDN URL}';

    protected $description = 'Validate the private-S3 and CloudFront delivery boundary before switching catalogue media to S3.';

    public function handle(PublicMediaDeliveryReadiness $readiness): int
    {
        $checks = $readiness->checks();

        $this->info('Public product media delivery readiness');
        $this->table(
            ['Check', 'Status', 'Message'],
            array_map(
                static fn (array $check): array => [
                    $check['name'],
                    strtoupper($check['status']),
                    $check['message'],
                ],
                $checks,
            ),
        );

        if (! $readiness->isConfigured()) {
            $this->error('Public product media delivery is NOT configured for the S3/CloudFront cutover.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('probe')) {
            $this->info('Configuration is ready. Re-run with --probe after Terraform has deployed CloudFront and before the live disk switch.');

            return self::SUCCESS;
        }

        try {
            $result = $readiness->probe();
        } catch (Throwable $exception) {
            $this->error('Public media probe failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
