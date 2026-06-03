# Architecture

## Domain model

```
User ──< household_user >── Household
                              │
        ┌──────────┬─────────┼───────────┬──────────────┐
   Ingredient   Dinner   DinnerPlan   ShoppingList   HouseholdInvitation
                  │          │             │
             DinnerItem  DinnerPlanEntry  ShoppingListItem
```

- A **User** belongs to many **Households** (pivot `household_user` carries a `role`:
  `owner` or `member`). `users.current_household_id` marks the one a user is currently
  acting in.
- A **Household** owns everything else. Deleting it cascades.
- An **Ingredient** is a household's catalogue entry. A **Dinner** (recipe) has
  **DinnerItems** that each reference an ingredient with a quantity + unit, sized for
  the dinner's `default_servings`.
- A **DinnerPlan** has **DinnerPlanEntries**: a dinner scheduled on a date with the
  number of `servings` you'll actually cook.
- A **ShoppingList** has **ShoppingListItems** (either a catalogue ingredient or a
  free-text `name`), and can be linked back to the plan it was generated from.

The reasoning behind the servings/quantity split lives in the shopping-list generation
logic — see [api-reference.md](api-reference.md#generate-a-shopping-list).

## Request lifecycle

```
Route (routes/api.php, /api/v1)
  └─ auth:api (Passport)  +  throttle:api
       └─ household.active  (only for household-scoped resources)
            └─ Controller (App\Http\Controllers\Api\V1\*)   ← thin
                 ├─ *InputData  (spatie/laravel-data)        ← validates the request
                 ├─ Policy / household-ownership check       ← authorizes
                 ├─ Action (App\Actions\*)                   ← the business logic
                 └─ *Data  (spatie/laravel-data)             ← shapes the response
```

Controllers stay thin: resolve a DTO, authorize, call an action, return a DTO. Anything
with real logic (creating a household, inviting a member, generating a shopping list)
lives in a single-purpose **Action** class with a `handle()` method.

## Where things live

| Path | What |
| --- | --- |
| `routes/api.php` | The entire `/api/v1` surface |
| `app/Http/Controllers/Api/V1/` | Thin controllers; resource controllers extend `ApiController` |
| `app/Http/Controllers/Api/V1/ApiController.php` | Base for scoped controllers: `currentHousehold()` + `ensureBelongsToHousehold()` |
| `app/Http/Middleware/EnsureActiveHousehold.php` | Resolves the active household (alias `household.active`) |
| `app/Actions/{Domain}/` | One class per operation, grouped by domain |
| `app/Data/` | DTOs — `XData` (output) and `XInputData` (validated input) |
| `app/Policies/HouseholdPolicy.php` | Membership/owner authorization |
| `app/Models/` | Eloquent models (+ `Models/Passport/Client.php`) |
| `app/Enums/` | `HouseholdRole`, `MealType` |
| `app/Notifications/` | `HouseholdInvitationNotification` (email) |

## Household scoping (multi-tenancy)

Every household-owned resource is reached through the **active household**, never by
guessing ids:

1. `EnsureActiveHousehold` loads `users.current_household_id`, confirms the user is
   still a member, and rejects with **409** if there's no active household. It stashes
   the household on the request.
2. Controllers read it via `$this->currentHousehold($request)` and create records
   through its relations (e.g. `$household->dinners()->create(...)`).
3. For routes that take a record id (`/dinners/{dinner}`), `ensureBelongsToHousehold()`
   returns **404** if the record belongs to a different household — so one household can
   never see or touch another's data, even with a valid id.

Household management endpoints (`/households`, `/household/switch`) and accepting an
invitation are **not** under `household.active`, because a brand-new user has no active
household yet.

### Roles

`HouseholdPolicy` gates household-level actions: any **member** can view; only an
**owner** can rename/delete the household and manage members + invitations. The last
owner cannot be demoted or removed.

## Data conventions (spatie/laravel-data)

- PHP properties are **camelCase**; JSON is **snake_case** (global `SnakeCaseMapper` in
  `config/data.php`). So `defaultServings` ⇄ `default_servings`.
- Every response is **wrapped in `data`** (`config('data.wrap')`).
- **Gotcha:** under global wrapping, a nested `Data`/`DataCollection` would wrap *again*
  (`items: { data: [...] }`). To keep nested lists flat, build them as plain arrays of
  `ChildData::fromX($model)->toArray()` — see `DinnerData`, `DinnerPlanData`,
  `ShoppingListData`.

## Adding a new feature — checklist

1. Migration + model (+ factory) if there's new schema.
2. `XData` (output, with a `fromModel()` if it has relations) and `XInputData` (input,
   with `#[Validation]` attributes) in `app/Data/`.
3. An `Action` in `app/Actions/{Domain}/` for anything beyond a trivial create/update.
4. A thin controller extending `ApiController`; scope reads/writes to the active
   household and use `ensureBelongsToHousehold()` for id-bound records.
5. Routes inside the `household.active` group in `routes/api.php`.
6. A Pest feature test in `tests/Feature/Api/` — happy path, validation,
   **cross-household isolation**, and any role rules. Use the `ownerWithHousehold()`
   helper from `tests/Pest.php`.
7. `vendor/bin/pint --dirty` before finishing.
