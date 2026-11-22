<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OldShop\OldShopProductImporter;

/**
 * Publishes the draft products imported by oldshop:import.
 */
final class PublishOldShopProductsCommand extends PublishImportedDraftsCommand
{
    protected $signature = 'oldshop:publish
        {--write : Publish after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the products and variants recorded in a backup written by --write.}';

    protected $description = 'Preview, publish or roll back publishing the draft products imported from the old ortezka.pl shop.';

    protected function source(): string
    {
        return OldShopProductImporter::SOURCE;
    }
}
