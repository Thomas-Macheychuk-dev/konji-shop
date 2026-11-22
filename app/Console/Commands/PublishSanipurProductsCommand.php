<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sanipur\SanipurProductImporter;

/**
 * Publishes the draft products imported by sanipur:import.
 */
final class PublishSanipurProductsCommand extends PublishImportedDraftsCommand
{
    protected $signature = 'sanipur:publish
        {--write : Publish after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the products and variants recorded in a backup written by --write.}';

    protected $description = 'Preview, publish or roll back publishing the imported Sanipur draft products.';

    protected function source(): string
    {
        return SanipurProductImporter::SOURCE;
    }
}
