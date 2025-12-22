<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProviderResource;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProviderController extends Controller
{
    /**
     * List all active providers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $providers = Provider::active()
            ->orderBy('name')
            ->get();

        return ProviderResource::collection($providers);
    }

    /**
     * Get a specific provider by slug.
     */
    public function show(Provider $provider): ProviderResource
    {
        return new ProviderResource($provider);
    }
}
