<?php

namespace App\Services;

use App\Models\Collaboration;
use Illuminate\Support\Facades\DB;

class CollaborationService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCollaboration(array $attributes): Collaboration
    {
        return DB::transaction(function () use ($attributes): Collaboration {
            return Collaboration::query()->create($attributes);
        });
    }

    public function deleteCollaboration(Collaboration $collaboration): bool
    {
        return DB::transaction(function () use ($collaboration): bool {
            return (bool) $collaboration->delete();
        });
    }
}
