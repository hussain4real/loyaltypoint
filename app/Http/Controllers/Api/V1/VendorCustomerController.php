<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VendorUserLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorCustomerController extends Controller
{
    /**
     * Look up linked accounts by vendor email.
     */
    public function lookupByVendorEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_email' => ['required', 'string', 'email'],
            'provider' => ['nullable', 'string'],
        ]);

        $query = VendorUserLink::query()
            ->with(['user', 'provider'])
            ->where('vendor_email', $validated['vendor_email'])
            ->whereHas('provider', function ($q) {
                $q->where('is_active', true);
            });

        // Filter by provider slug if provided
        if (! empty($validated['provider'])) {
            $query->whereHas('provider', function ($q) use ($validated) {
                $q->where('slug', $validated['provider']);
            });
        }

        $links = $query->get();

        $data = $links->map(function (VendorUserLink $link) {
            return [
                'provider' => [
                    'slug' => $link->provider->slug,
                    'name' => $link->provider->name,
                ],
                'user' => [
                    'id' => $link->user->id,
                    'name' => $link->user->name,
                    'email' => $link->user->email,
                ],
                'points_balance' => $link->user->getBalanceForProvider($link->provider),
                'linked_at' => $link->linked_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total_linked_accounts' => $links->count(),
            ],
        ]);
    }
}
