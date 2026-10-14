<?php

declare(strict_types=1);

namespace Tests\Feature\Herdegen;

use App\Console\Commands\FetchHerdegenAuthorizedPagesCommand;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FetchHerdegenAuthorizedPagesCommandTest extends TestCase
{
    public function test_it_rejects_unsafe_product_and_image_urls(): void
    {
        self::assertTrue(FetchHerdegenAuthorizedPagesCommand::allowedUrl('https://www.orteo.pl/herdegen-test', false));
        self::assertTrue(FetchHerdegenAuthorizedPagesCommand::allowedUrl('https://www.orteo.pl/img/product_media/1001-2000/progress23.jpg', true));
        self::assertFalse(FetchHerdegenAuthorizedPagesCommand::allowedUrl('http://www.orteo.pl/herdegen-test', false));
        self::assertFalse(FetchHerdegenAuthorizedPagesCommand::allowedUrl('https://www.orteo.pl.evil.test/herdegen-test', false));
        self::assertFalse(FetchHerdegenAuthorizedPagesCommand::allowedUrl('https://www.orteo.pl/img/product_media/../../secret.jpg', true));
    }

    public function test_it_rejects_missing_snapshot_without_network_access(): void
    {
        Storage::fake('local');
        $this->artisan('herdegen:fetch-authorized-pages', ['--limit' => '5'])
            ->expectsOutputToContain('HERDEGEN_FETCH=FAIL')
            ->assertExitCode(1);
    }

    public function test_it_rejects_invalid_options(): void
    {
        $this->artisan('herdegen:fetch-authorized-pages', ['--delay-ms' => '-1'])
            ->expectsOutputToContain('HERDEGEN_FETCH=FAIL')
            ->assertExitCode(1);
    }
}
