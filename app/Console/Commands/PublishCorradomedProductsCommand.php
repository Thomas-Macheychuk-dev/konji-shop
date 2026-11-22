<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Corradomed\CorradomedProductImporter;

/**
 * Publishes the draft products imported by corradomed:import.
 */
final class PublishCorradomedProductsCommand extends PublishImportedDraftsCommand
{
    protected $signature = 'corradomed:publish
        {--write : Publish after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the products and variants recorded in a backup written by --write.}';

    protected $description = 'Preview, publish or roll back publishing the imported Corradomed draft products.';

    protected function source(): string
    {
        return CorradomedProductImporter::SOURCE;
    }
}
