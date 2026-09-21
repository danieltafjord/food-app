<?php

namespace App\Actions\Households;

use App\Data\HouseholdInputData;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnsureHousehold
{
    public function __construct(private CreateHousehold $createHousehold) {}

    /** Repeated setup requests, including from other devices, reuse the same household. */
    public function handle(User $user, HouseholdInputData $data): Household
    {
        return DB::transaction(function () use ($user, $data): Household {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $household = $user->households()->whereKey($user->current_household_id)->first()
                ?? $user->households()->orderBy('households.id')->first();

            if ($household) {
                if ($user->current_household_id !== $household->id) {
                    $user->update(['current_household_id' => $household->id]);
                }

                return $household;
            }

            return $this->createHousehold->handle($user, $data);
        });
    }
}
