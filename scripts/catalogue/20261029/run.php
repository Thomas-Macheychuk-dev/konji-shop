<?php

declare(strict_types=1);

// Standalone, data-only repair. Run inside the existing Laravel app container.
// No schema migrations, importer calls, product mutations or external API calls.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use App\Services\Storefront\StorefrontCache;

function demand(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function jsonFile(string $path): array
{
    demand(is_file($path), 'Missing file: '.$path);
    $result = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    demand(is_array($result), 'Expected JSON object/array: '.$path);
    return $result;
}

function digest(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function projection(array $rows, array $fields): array
{
    $result = [];
    foreach ($rows as $row) {
        $row = (array) $row;
        $item = [];
        foreach ($fields as $field) {
            $value = $row[$field];
            if ($value !== null && in_array($field, ['id', 'parent_id', 'category_id', 'product_id', 'is_primary'], true)) {
                $value = (int) $value;
            }
            $item[$field] = $value;
        }
        $result[] = $item;
    }
    return $result;
}

function currentSnapshot(bool $lock): array
{
    $result = [];
    foreach (['categories', 'products', 'category_product'] as $table) {
        $query = DB::table($table)->orderBy('id');
        if ($table === 'products') {
            $query->select(['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at']);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $result[$table] = array_map(fn ($row): array => (array) $row, $query->get()->all());
    }
    return $result;
}

function assertBaseline(array $state, array $source): void
{
    $fields = [
        'categories' => ['id', 'parent_id', 'name', 'slug', 'status', 'deleted_at'],
        'products' => ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at'],
        'assignments' => ['product_id', 'category_id', 'is_primary'],
    ];
    foreach ($fields as $key => $columns) {
        $live = $state[$key === 'assignments' ? 'category_product' : $key];
        $expected = $source[$key];
        if ($key === 'assignments') {
            $sort = fn ($a, $b): int => [(int) $a['product_id'], (int) $a['category_id']] <=> [(int) $b['product_id'], (int) $b['category_id']];
            usort($live, $sort);
            usort($expected, $sort);
        }
        demand(digest(projection($live, $columns)) === digest(projection($expected, $columns)),
            'Snapshot drift in '.$key.'. No repair applied. Export a fresh catalogue and rebuild/review the plan.');
    }
}

function oldChain(int $id, array $categories): array
{
    $seen = [];
    while (isset($categories[$id]) && ! in_array($id, $seen, true)) {
        $seen[] = $id;
        $parent = $categories[$id]['parent_id'];
        if ($parent === null) {
            break;
        }
        $id = (int) $parent;
    }
    return $seen;
}

function activeChain(int $id, array $categories): array
{
    $seen = [];
    while (true) {
        demand(isset($categories[$id]), 'Missing category in repaired graph: '.$id);
        demand(! in_array($id, $seen, true), 'Category cycle: '.$id);
        $category = $categories[$id];
        demand($category['deleted_at'] === null && $category['status'] === 'active', 'Inactive category in product path: '.$id);
        $seen[] = $id;
        if ($category['parent_id'] === null) {
            return $seen;
        }
        $id = (int) $category['parent_id'];
    }
}

function plannedState(array $source, array $plan): array
{
    $oldCategories = array_column($source['categories'], null, 'id');
    $categories = $oldCategories;
    foreach ($plan['create_categories'] as $row) {
        $categories[$row['ref']] = ['id' => $row['ref'], 'name' => $row['name'], 'slug' => $row['slug'], 'parent_id' => $row['parent_ref'], 'status' => 'active', 'deleted_at' => null];
    }
    foreach ($plan['update_categories'] as $row) {
        demand(isset($categories[$row['id']]) && $categories[$row['id']]['slug'] === $row['slug'], 'Category identity mismatch');
        $categories[$row['id']] = array_replace($categories[$row['id']], ['parent_id' => $row['parent_ref'], 'name' => $row['name'], 'status' => $row['status']]);
    }
    $roots = array_column($plan['roots'], 'ref');
    $actualRoots = [];
    $slugs = [];
    $siblingNames = [];
    foreach ($categories as $id => $category) {
        demand(! isset($slugs[$category['slug']]), 'Duplicate category slug in plan: '.$category['slug']);
        $slugs[$category['slug']] = true;
        if ($category['deleted_at'] !== null || $category['status'] !== 'active') {
            continue;
        }
        $siblingKey = ($category['parent_id'] ?? 'root').'|'.mb_strtolower(trim($category['name']));
        demand(! isset($siblingNames[$siblingKey]), 'Duplicate active sibling category name: '.$category['name']);
        $siblingNames[$siblingKey] = true;
        $chain = activeChain((int) $id, $categories);
        demand(in_array(end($chain), $roots, true), 'Unexpected active root');
        if ($category['parent_id'] === null) {
            $actualRoots[] = (int) $id;
        }
    }
    sort($roots);
    sort($actualRoots);
    demand($actualRoots === $roots, 'Wrong active departments');
    $products = array_column($source['products'], null, 'id');
    $byProduct = [];
    foreach ($source['assignments'] as $row) {
        $byProduct[$row['product_id']][] = $row;
    }
    $overrides = array_column($plan['product_primary_overrides'], null, 'product_id');
    $retired = $plan['retire_category_ids'];
    $assignments = [];
    foreach ($products as $id => $product) {
        $old = $byProduct[$id] ?? [];
        $oldPrimary = null;
        $primaryCount = 0;
        $ancestors = [];
        foreach ($old as $row) {
            if ((bool) $row['is_primary']) {
                $oldPrimary = (int) $row['category_id'];
                $primaryCount++;
            }
            if (! in_array((int) $row['category_id'], $retired, true)) {
                foreach (array_slice(oldChain((int) $row['category_id'], $oldCategories), 1) as $ancestor) {
                    $ancestors[$ancestor] = true;
                }
            }
        }
        demand($primaryCount <= 1, 'Multiple old primary categories: '.$id);
        $primary = $oldPrimary;
        if (isset($overrides[$id])) {
            $override = $overrides[$id];
            foreach (['name', 'external_source', 'external_id'] as $field) {
                demand($override[$field] === $product[$field], 'Product identity mismatch: '.$id);
            }
            $primary = (int) $override['category_ref'];
        }
        demand($primary !== null, 'No primary category for product '.$id);
        $leaves = [];
        foreach ($old as $row) {
            $categoryId = (int) $row['category_id'];
            if (! isset($ancestors[$categoryId]) && ! in_array($categoryId, $retired, true)) {
                $leaves[$categoryId] = true;
            }
        }
        if (isset($overrides[$id]) && $oldPrimary !== null) {
            unset($leaves[$oldPrimary]);
        }
        $leaves[$primary] = true;
        if ($product['external_source'] !== 'apolonia') {
            unset($leaves[23]);
        }
        $final = [];
        foreach ($leaves as $leaf => $_) {
            if ($categories[$leaf]['status'] !== 'active' || $categories[$leaf]['deleted_at'] !== null) {
                continue;
            }
            foreach (activeChain((int) $leaf, $categories) as $categoryId) {
                $final[$categoryId] = true;
            }
        }
        demand(isset($final[$primary]), 'Missing planned primary: '.$id);
        if (in_array($product['external_source'], ['peruka', 'reh4mat', 'mobilex'], true)) {
            demand(! isset($final[560]), 'Clothing contamination remains: '.$id);
        }
        ksort($final);
        foreach ($final as $categoryId => $_) {
            $assignments[] = ['product_id' => (int) $id, 'category_id' => (int) $categoryId, 'is_primary' => (int) ($categoryId === $primary)];
        }
    }
    return [$categories, $assignments];
}

function writeExclusive(string $path, array $data): void
{
    $stream = fopen($path, 'x');
    demand($stream !== false, 'Cannot create backup/receipt (existing files are never overwritten): '.$path);
    try {
        $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        demand(fwrite($stream, $text) === strlen($text), 'Incomplete backup/receipt write');
        demand(fflush($stream), 'Cannot flush backup/receipt');
        if (function_exists('fsync')) {
            demand(fsync($stream), 'Cannot sync backup/receipt');
        }
    } finally {
        fclose($stream);
    }
}

function cacheBump(): void
{
    app(StorefrontCache::class)->bump(StorefrontCache::NAMESPACE_NAVIGATION);
    app(StorefrontCache::class)->bump(StorefrontCache::NAMESPACE_CATALOGUE);
}

function receiptMatches(array $state, array $receipt): void
{
    foreach (['categories', 'category_product'] as $key) {
        demand(digest($state[$key]) === $receipt['after_hashes'][$key], 'Post-repair drift in '.$key.'; refusing to overwrite newer edits.');
    }
    demand(digest(projection($state['products'], ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at'])) === $receipt['product_identity_hash'], 'Product catalogue identity changed');
}

if (defined('CATALOGUE_REPAIR_FUNCTIONS_ONLY') && CATALOGUE_REPAIR_FUNCTIONS_ONLY) {
    return;
}

try {
    $options = getopt('', ['mode:', 'source:', 'plan:', 'backup:', 'app:']);
    $mode = $options['mode'] ?? 'dry-run';
    demand(in_array($mode, ['dry-run', 'apply', 'verify', 'rollback'], true), 'Unsupported mode');
    $appPath = $options['app'] ?? getcwd();
    demand(is_file($appPath.'/vendor/autoload.php'), 'Run inside the Laravel app directory or pass --app');
    require $appPath.'/vendor/autoload.php';
    $app = require $appPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    demand(DB::connection()->getDriverName() === 'mysql', 'This reviewed repair requires MySQL');
    $backupPath = $options['backup'] ?? storage_path('app/private/catalogue-repairs/catalogue-20261029-v1.json');
    $receiptPath = $backupPath.'.receipt.json';
    $databaseFingerprint = hash('sha256', (string) config('database.connections.'.config('database.default').'.host').'|'.DB::connection()->getDatabaseName());
    if ($mode === 'verify' || $mode === 'rollback') {
        $backup = jsonFile($backupPath);
        $receipt = jsonFile($receiptPath);
        demand(hash_file('sha256', $backupPath) === $receipt['backup_sha256'], 'Backup checksum mismatch');
        demand($receipt['database_fingerprint'] === $databaseFingerprint, 'Wrong database');
        DB::transaction(function () use ($mode, $backup, $receipt): void {
            $state = currentSnapshot($mode === 'rollback');
            receiptMatches($state, $receipt);
            if ($mode === 'verify') {
                echo "POST_REPAIR_STATE=PASS\n";
                return;
            }
            DB::table('category_product')->delete();
            // Break references before restoring original parents; no category is hard-deleted.
            $originalIds = array_column($backup['categories'], 'id');
            DB::table('categories')->update(['parent_id' => null]);
            foreach ($backup['categories'] as $row) {
                $id = $row['id'];
                unset($row['id']);
                DB::table('categories')->where('id', $id)->update($row);
            }
            // Keep created rows as archived tombstones to avoid hard deletion and ID reuse.
            DB::table('categories')->whereNotIn('id', $originalIds)->update(['parent_id' => null, 'status' => 'archived', 'deleted_at' => now()->toDateTimeString()]);
            foreach (array_chunk($backup['category_product'], 400) as $chunk) {
                DB::table('category_product')->insert($chunk);
            }
            $restored = currentSnapshot(false);
            demand(digest(array_values(array_filter($restored['categories'], fn ($row): bool => in_array($row['id'], $originalIds, true)))) === digest($backup['categories']), 'Category rollback validation failed');
            demand(digest($restored['category_product']) === digest($backup['category_product']), 'Assignment rollback validation failed');
        });
        cacheBump();
        echo strtoupper($mode)."=PASS\n";
        exit(0);
    }
    $sourcePath = $options['source'] ?? '/tmp/category-audit.json';
    $planPath = $options['plan'] ?? dirname(__DIR__).'/resources/plan.json';
    $source = jsonFile($sourcePath);
    $plan = jsonFile($planPath);
    demand(($plan['schema_version'] ?? null) === 1 && ($plan['repair_id'] ?? null) === 'catalogue-20261029-v1', 'Unrecognized repair manifest');
    demand(hash_file('sha256', $sourcePath) === $plan['source_sha256'], 'Source file checksum mismatch');
    [$plannedCategories, $plannedAssignments] = plannedState($source, $plan);
    echo 'MODE='.$mode."\n";
    echo 'APP_ENV='.app()->environment()."\n";
    echo 'SOURCE_SHA256='.$plan['source_sha256']."\n";
    echo 'ROOT_DEPARTMENTS='.count($plan['roots'])."\n";
    echo 'NEW_CATEGORIES='.count($plan['create_categories'])."\n";
    echo 'CATEGORY_UPDATES='.count($plan['update_categories'])."\n";
    echo 'PRIMARY_REASSIGNMENTS='.count($plan['product_primary_overrides'])."\n";
    echo 'PLANNED_ASSIGNMENTS='.count($plannedAssignments)."\n";
    $committed = false;
    DB::transaction(function () use ($mode, $source, $plan, $planPath, $backupPath, $receiptPath, $databaseFingerprint, $plannedAssignments, $plannedCategories): void {
        $before = currentSnapshot($mode === 'apply');
        assertBaseline($before, $source);
        echo "LIVE_SNAPSHOT_MATCH=PASS\nCATEGORY_GRAPH=PASS\nPRIMARY_COVERAGE=PASS\nCLOTHING_CONTAMINATION=0\n";
        if ($mode === 'dry-run') {
            return;
        }
        umask(0077);
        if (! is_dir(dirname($backupPath))) {
            demand(mkdir(dirname($backupPath), 0700, true), 'Cannot create backup directory');
        }
        demand(! file_exists($backupPath) && ! file_exists($receiptPath), 'Backup/receipt already exists; use verify instead of reapplying');
        writeExclusive($backupPath, ['repair_id' => $plan['repair_id'], 'created_at' => now()->toIso8601String(), 'database_fingerprint' => $databaseFingerprint, 'plan_sha256' => hash_file('sha256', $planPath), 'categories' => $before['categories'], 'category_product' => $before['category_product']]);
        $refs = [];
        $timestamp = now()->toDateTimeString();
        foreach ($plan['create_categories'] as $row) {
            $refs[$row['ref']] = DB::table('categories')->insertGetId(['parent_id' => null, 'name' => $row['name'], 'slug' => $row['slug'], 'status' => 'active', 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        }
        $resolve = static fn (?int $id): ?int => $id === null ? null : ($refs[$id] ?? $id);
        foreach ($plan['create_categories'] as $row) {
            DB::table('categories')->where('id', $refs[$row['ref']])->update(['parent_id' => $resolve($row['parent_ref'])]);
        }
        foreach ($plan['update_categories'] as $row) {
            demand(DB::table('categories')->where('id', $row['id'])->where('slug', $row['slug'])->update(['parent_id' => $resolve($row['parent_ref']), 'name' => $row['name'], 'status' => $row['status'], 'updated_at' => $timestamp]) === 1, 'Category update conflict');
        }
        $oldPairs = [];
        foreach ($before['category_product'] as $row) {
            $oldPairs[$row['product_id'].':'.$row['category_id']] = $row;
        }
        $insert = [];
        foreach ($plannedAssignments as $row) {
            $row['category_id'] = $resolve($row['category_id']);
            $old = $oldPairs[$row['product_id'].':'.$row['category_id']] ?? null;
            $insert[] = ['id' => $old['id'] ?? null, 'category_id' => $row['category_id'], 'product_id' => $row['product_id'], 'is_primary' => $row['is_primary'], 'created_at' => $old['created_at'] ?? $timestamp, 'updated_at' => $timestamp];
        }
        DB::table('category_product')->delete();
        foreach (array_chunk($insert, 400) as $chunk) {
            DB::table('category_product')->insert($chunk);
        }
        $after = currentSnapshot(false);
        $expectedCategories = [];
        foreach ($plannedCategories as $row) {
            $row['id'] = $resolve((int) $row['id']);
            $row['parent_id'] = $resolve($row['parent_id'] === null ? null : (int) $row['parent_id']);
            $expectedCategories[$row['id']] = $row;
        }
        ksort($expectedCategories);
        $categoryFields = ['id', 'parent_id', 'name', 'slug', 'status', 'deleted_at'];
        demand(digest(projection(array_values($expectedCategories), $categoryFields)) === digest(projection($after['categories'], $categoryFields)), 'Category write validation failed');
        $sort = fn ($a, $b): int => [(int) $a['product_id'], (int) $a['category_id']] <=> [(int) $b['product_id'], (int) $b['category_id']];
        $expected = projection($insert, ['product_id', 'category_id', 'is_primary']);
        $actual = projection($after['category_product'], ['product_id', 'category_id', 'is_primary']);
        usort($expected, $sort);
        usort($actual, $sort);
        demand(digest($expected) === digest($actual), 'Assignment write validation failed');
        demand(digest($before['products']) === digest($after['products']), 'Product rows changed during repair');
        $categoriesAfter = array_column($after['categories'], null, 'id');
        $primaryCounts = [];
        foreach ($after['category_product'] as $row) {
            activeChain((int) $row['category_id'], $categoriesAfter);
            if ($row['is_primary']) {
                $primaryCounts[$row['product_id']] = ($primaryCounts[$row['product_id']] ?? 0) + 1;
            }
        }
        foreach ($after['products'] as $row) {
            demand(($primaryCounts[$row['id']] ?? 0) === 1, 'Written primary category invariant failed');
        }
        writeExclusive($receiptPath, ['repair_id' => $plan['repair_id'], 'database_fingerprint' => $databaseFingerprint, 'backup_sha256' => hash_file('sha256', $backupPath), 'plan_sha256' => hash_file('sha256', $planPath), 'created_category_ids' => array_values($refs), 'after_hashes' => ['categories' => digest($after['categories']), 'category_product' => digest($after['category_product'])], 'product_identity_hash' => digest(projection($after['products'], ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at']))]);
    });
    if ($mode === 'apply') {
        $committed = true;
        echo "DATABASE_COMMIT=PASS\nBACKUP=".$backupPath."\nRECEIPT=".$receiptPath."\n";
        cacheBump();
        echo "CACHE_INVALIDATION=PASS\n";
    }
    echo strtoupper(str_replace('-', '_', $mode))."=PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'REPAIR=FAIL '.$exception->getMessage()."\n");
    if (! empty($committed)) {
        fwrite(STDERR, "DATABASE_ALREADY_COMMITTED=1; use --mode=verify to retry cache invalidation.\n");
    }
    exit(1);
}
