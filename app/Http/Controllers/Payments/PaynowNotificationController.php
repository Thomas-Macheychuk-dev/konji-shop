<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Services\Payments\HandlePaymentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaynowNotificationController
{
    /**
     * @throws Throwable
     */
    public function __invoke(Request $request, HandlePaymentNotificationService $service): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = $request->json()->all();

        $service->handle('paynow', $payload, $rawBody);

        return response()->json(['status' => 'OK']);
    }
}
