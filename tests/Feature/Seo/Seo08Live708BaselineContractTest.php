<?php

declare(strict_types=1);

it('pins the verified SEO-08 live708 production baseline', function (): void {
    $path =
        'resources/seo/ortezka/review/seo-08g-20261008/'
        .'live-708-baseline.json';

    $absolute = base_path($path);

    expect(hash_file('sha256', $absolute))
        ->toBe('20378230f1aa7c65b3a29da6861bf6a457ca7eed573ba0005543453501e6e263');

    $evidence = json_decode(
        (string) file_get_contents($absolute),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($evidence['schema_version'])->toBe(1)
        ->and($evidence['phase'])->toBe('SEO-08G')
        ->and($evidence['decision'])
        ->toBe('VERIFY_LIVE_PRODUCTION_708')
        ->and($evidence['result'])->toBe('PASS');

    expect($evidence['source']['main_commit'])
        ->toBe('73a324574ed8e7f21db5460f683c6a607784290f')
        ->and($evidence['source']['promotion_source_commit'])
        ->toBe('90c6a1b6088fb1acaa12e5097608fdffb82221a5')
        ->and($evidence['source']['production_map_sha256'])
        ->toBe('059d34d5e2b49301a4da744d904fd4e43a67c9005a10cb54e6ee90b6e2d4edff')
        ->and($evidence['source']['manifest_sha256'])
        ->toBe('ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db')
        ->and($evidence['source']['authorization_sha256'])
        ->toBe('7116b063d95aec383cabaf0a9a50ef2e947ea4623afa389c79c80c6197bd7530')
        ->and($evidence['source']['ordinary_redirect_rules'])
        ->toBe(708)
        ->and($evidence['source']['approved_target_products'])
        ->toBe(445)
        ->and($evidence['source']['held_legacy_ids'])
        ->toBe(['3321', '7560']);

    expect($evidence['runtime']['web_image'])
        ->toBe('sha256:924a3ac0774b90e2c62c33d2f4d0362eaba3d55c0450be14ba327c888b235ce2')
        ->and($evidence['runtime']['immutable_live_tag'])
        ->toBe('konji-shop-web:seo08f-live708-73a3245')
        ->and($evidence['runtime']['pre708_rollback_image'])
        ->toBe('sha256:a16c4f9c7459f7715925becc5fb414ec11a5cffa56e251b6ef3cd6ca8b8a70e6')
        ->and($evidence['runtime']['production_origin_http'])
        ->toBe(200)
        ->and($evidence['runtime']['staging_origin_http'])
        ->toBe(200)
        ->and($evidence['runtime']['q5_status'])
        ->toBe(301)
        ->and($evidence['runtime']['q5_result'])
        ->toBe('PASS')
        ->and($evidence['runtime']['staging_candidate_enabled'])
        ->toBeFalse();

    expect($evidence['validation']['target_report_sha256'])
        ->toBe('897a7f3d44b2a917cccb1f26aad5edfa1d76058741d1ef633df9d2c815f0192b')
        ->and($evidence['validation']['runtime_report_sha256'])
        ->toBe('f7142f4d519388d7aef350f94d97a993ce5787f8fa08828c8db0d8240f9cb465')
        ->and($evidence['validation']['target_products'])
        ->toBe(445)
        ->and($evidence['validation']['source_paths'])
        ->toBe(708)
        ->and($evidence['validation']['target_result'])
        ->toBe('PASS')
        ->and($evidence['validation']['runtime_result'])
        ->toBe('PASS')
        ->and($evidence['validation']['redirect_chains'])
        ->toBe(0)
        ->and($evidence['validation']['redirect_loops'])
        ->toBe(0)
        ->and($evidence['validation']['wrong_destinations'])
        ->toBe(0)
        ->and($evidence['validation']['canonical_mismatches'])
        ->toBe(0)
        ->and($evidence['validation']['identity_mismatches'])
        ->toBe(0);

    expect($evidence['service_isolation']['app_recreated'])
        ->toBeFalse()
        ->and($evidence['service_isolation']['queue_recreated'])
        ->toBeFalse()
        ->and($evidence['service_isolation']['scheduler_recreated'])
        ->toBeFalse()
        ->and($evidence['service_isolation']['redis_recreated'])
        ->toBeFalse()
        ->and($evidence['service_isolation']['database_writes_by_deployment'])
        ->toBe(0)
        ->and($evidence['service_isolation']['migrations'])
        ->toBe(0);

    expect($evidence['governance']['owner_authorization_text'])
        ->toBe('AUTHORIZE SEO-08 PRODUCTION 708')
        ->and($evidence['governance']['automatic_production_deployment_disabled'])
        ->toBeTrue()
        ->and($evidence['governance']['runtime_mutated_by_closure_capture'])
        ->toBeFalse();

    expect(hash_file(
        'sha256',
        base_path('docker/nginx/generated/legacy-seo-product-map.conf'),
    ))->toBe(
        $evidence['source']['production_map_sha256'],
    );
});
