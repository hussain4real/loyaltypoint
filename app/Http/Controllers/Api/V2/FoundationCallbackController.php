<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\FoundationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoundationCallbackController extends Controller
{
    public function __invoke(Request $request, string $source, FoundationEvents $events): JsonResponse
    {
        $event = $events->accept($source, $request->getContent(), $request->header('X-Callback-Timestamp', ''), $request->header('X-Callback-Signature', ''));

        return response()->json(['reference' => $event->id, 'status' => 'accepted'], 202);
    }
}
