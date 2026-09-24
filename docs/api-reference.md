# API reference

Base URL: **`/api/v1`**. All endpoints require a Passport access token unless noted, and
are rate-limited (`throttle:api`, 60 req/min per user).

## Authentication

The mobile app never sees a password. It delegates login to the web auth pages
(Fortify) through **OAuth2 Authorization Code + PKCE**, then receives tokens.

1. The app generates a PKCE `code_verifier`/`code_challenge` and opens a system browser
   to:
   ```
   GET /oauth/authorize?response_type=code&client_id=<public-client-id>
       &redirect_uri=foodapp://oauth/callback&code_challenge=<challenge>
       &code_challenge_method=S256&state=<random>
   ```
2. The user signs in / registers on the Fortify pages — this is where password, email
   verification, 2FA, and passkeys happen. Because the client is first-party, the
   consent screen is skipped.
3. The browser redirects to `foodapp://oauth/callback?code=...&state=...`.
4. The app exchanges the code for tokens:
   ```
   POST /oauth/token
   { grant_type: "authorization_code", client_id, code_verifier, code, redirect_uri }
   ```
   → `{ access_token, refresh_token, expires_in }`.
5. Send the token on every API call: `Authorization: Bearer <access_token>`.
6. Refresh with `grant_type=refresh_token` when the access token expires.

**Token lifetimes:** access 15 days, refresh 30 days (configured in
`AppServiceProvider`). `/oauth/*` routes are provided by Passport.

> Create the public client once per environment:
> `php artisan passport:client --public --name="Food App Mobile" --redirect_uri="foodapp://oauth/callback"`

## Conventions

- **Wrapping** — every response is wrapped: a resource is `{ "data": { ... } }`, a list
  is `{ "data": [ ... ] }`.
- **Casing** — JSON keys are `snake_case`.
- **Validation errors** — `422` with `{ "message": "...", "errors": { "field": ["..."] } }`.
- **Empty success** — deletes and similar return `204 No Content`.
- **Lists** are returned in full (no pagination yet).

### Status codes

| Code | Meaning |
| --- | --- |
| `200` | OK (including successful create/update — the resource is returned) |
| `204` | Deleted / no content |
| `401` | Missing or invalid token |
| `403` | Authenticated but not allowed (e.g. not an owner, wrong invitee) |
| `404` | Not found, or the record belongs to another household |
| `409` | Conflict — no active household, last owner, ingredient in use, stale invitation |
| `422` | Validation failed |
| `429` | Rate limited |

## Active household

Resource endpoints below operate on the caller's **active household**
(`users.current_household_id`). If none is set, they return `409`. Change it with
`POST /household/switch`.

---

## Auth & session

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/me` | The authenticated user + their current household |
| `POST` | `/auth/logout` | Revoke the current access token |
| `GET` | `/auth/devices` | List the user's active tokens (one per signed-in device) |
| `DELETE` | `/auth/devices/{token}` | Revoke a specific device's token |

## Households

| Method | Path | Purpose | Notes |
| --- | --- | --- | --- |
| `GET` | `/households` | Households the user belongs to, with their role | |
| `POST` | `/households` | Create a household | Creator becomes owner; it becomes active |
| `GET` | `/households/{household}` | Show a household | Member only |
| `PATCH` | `/households/{household}` | Rename | Owner only |
| `DELETE` | `/households/{household}` | Delete (cascades) | Owner only |
| `POST` | `/household/switch` | Set the active household (`{ household_id }`) | Must be a member |

## Members (active household)

| Method | Path | Purpose | Notes |
| --- | --- | --- | --- |
| `GET` | `/household/members` | List members + roles | |
| `PATCH` | `/household/members/{user}` | Change a member's role (`{ role }`) | Owner only; can't demote last owner |
| `DELETE` | `/household/members/{user}` | Remove a member, or leave (remove yourself) | Owner removes anyone; members remove only themselves; can't remove last owner |

## Invitations

Managed by an owner of the active household:

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/household/invitations` | List the household's invitations |
| `POST` | `/household/invitations` | Invite by email (`{ email, role? }`) — sends an email |
| `DELETE` | `/household/invitations/{invitation}` | Revoke a pending invitation |

Acted on by the invited user (the token comes from the invitation email; **not**
scoped to an active household):

| Method | Path | Purpose | Notes |
| --- | --- | --- | --- |
| `POST` | `/invitations/{token}/accept` | Join the household | The token's email must match the user's |
| `POST` | `/invitations/{token}/decline` | Decline | |

## Ingredients (active household)

Standard resource. Catalogue entries shared by the household.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/ingredients` | List |
| `POST` | `/ingredients` | Create (`{ name, default_unit?, category? }`) |
| `GET` | `/ingredients/{ingredient}` | Show |
| `PUT`/`PATCH` | `/ingredients/{ingredient}` | Update |
| `DELETE` | `/ingredients/{ingredient}` | Delete — `409` if used by a dinner or list |

## Dinners (active household)

Recipes. Items are sent **inline** in the dinner payload; on update the items are
replaced wholesale.

```jsonc
// POST /dinners
{
  "name": "Spaghetti Bolognese",
  "default_servings": 4,
  "notes": null,
  "items": [
    { "ingredient_id": 1, "quantity": 400, "unit": "g" },
    { "ingredient_id": 2, "quantity": 500, "unit": "g" }
  ]
}
```

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/dinners` | List (with items) |
| `POST` | `/dinners` | Create with items |
| `GET` | `/dinners/{dinner}` | Show (with items) |
| `PUT`/`PATCH` | `/dinners/{dinner}` | Update + replace items |
| `DELETE` | `/dinners/{dinner}` | Delete (items cascade) |

Every item's `ingredient_id` must belong to the household, else `422`.

## Dinner plans (active household)

A plan is a named period; entries schedule a dinner on a date with the servings you'll
cook.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/dinner-plans` | List (with entries) |
| `POST` | `/dinner-plans` | Create (`{ name, start_date?, end_date? }`) |
| `GET` | `/dinner-plans/{dinnerPlan}` | Show (with entries) |
| `PUT`/`PATCH` | `/dinner-plans/{dinnerPlan}` | Update |
| `DELETE` | `/dinner-plans/{dinnerPlan}` | Delete (entries cascade) |
| `POST` | `/dinner-plans/{dinnerPlan}/entries` | Schedule a dinner (`{ dinner_id, scheduled_date, servings, meal_type?, notes? }`) |
| `PUT`/`PATCH` | `/dinner-plans/{dinnerPlan}/entries/{entry}` | Move / re-size an entry |
| `DELETE` | `/dinner-plans/{dinnerPlan}/entries/{entry}` | Remove an entry |

## Shopping lists (active household)

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/shopping-lists` | List (with items) |
| `POST` | `/shopping-lists` | Create (`{ name, dinner_plan_id? }`) |
| `GET` | `/shopping-lists/{shoppingList}` | Show (with items) |
| `PUT`/`PATCH` | `/shopping-lists/{shoppingList}` | Update |
| `DELETE` | `/shopping-lists/{shoppingList}` | Delete (items cascade) |
| `POST` | `/shopping-lists/{shoppingList}/items` | Add an item — catalogue (`{ ingredient_id, quantity?, unit? }`) or ad-hoc (`{ name, ... }`) |
| `PUT`/`PATCH` | `/shopping-lists/{shoppingList}/items/{item}` | Update an item |
| `PATCH` | `/shopping-lists/{shoppingList}/items/{item}/check` | Tick/untick while shopping (`{ checked }`) |
| `DELETE` | `/shopping-lists/{shoppingList}/items/{item}` | Remove an item |

An item must carry either an `ingredient_id` (belonging to the household) or a `name`.

### Generate a shopping list

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/dinner-plans/{dinnerPlan}/shopping-list` | Build a list from a plan |

For each plan entry, every recipe item's quantity is scaled by
`entry.servings / dinner.default_servings`, then summed across the whole plan grouped by
**ingredient + unit**. The same ingredient in different units stays on separate lines.
The resulting list is linked back to the plan (`dinner_plan_id`).

> Example: Bolognese (serves 4: 2 onions, 500 g beef) cooked for 2 → 1 onion + 250 g
> beef. Plus a soup (serves 2: 1 onion) cooked for 2 → 1 onion. The list has **2 onions
> (pcs)** and **250 g beef** — two lines, onions combined.

---

## Sync (active household)

The mobile app is local-first; this endpoint is how it exchanges its offline
edits with the household. Everything else in this document is the REST surface;
the app only uses that for accounts, households, members and invitations.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/sync` | Push local changes and pull everything changed since the cursor |

Request:

```json
{
  "cursor": 41,
  "household_id": 7,
  "changes": {
    "ingredients": [{ "id": "…uuid…", "name": "Melk", "default_unit": "l", "category": "dairy",
                      "created_at": "…", "updated_at": "…", "deleted_at": null }],
    "dinner_items": [{ "id": "…uuid…", "updated_at": "…", "deleted_at": "…" }]
  }
}
```

- `cursor` — the integer returned by the previous sync, or `null` on a device's first
  sync (then only live rows are returned, never tombstones).
- `household_id` — the household the device is bound to. If the account's active
  household differs the request is refused with `409` and
  `{ "code": "household_mismatch", "household_id": <active> }`; the device re-binds
  (wipes its local copy and pulls the new household) instead of uploading one
  household's rows into another.
- `changes` — resource key → rows. Keys, in dependency order: `ingredients`, `dinners`,
  `dinner_items`, `dinner_plans`, `plan_entries`, `shopping_lists`, `shopping_list_items`.
  Foreign keys are the parent's uuid. A delete is a **bare tombstone**
  `{ id, updated_at, deleted_at }`; a tombstone for a uuid the server never saw is ignored.
  At most **1000 rows** per request — larger outboxes are chunked in dependency order.

Response (`200`, not wrapped in `data`):

```json
{
  "cursor": 42,
  "household_id": 7,
  "changes": { "ingredients": [ … ], "dinners": [ … ], … },
  "rejected": { "dinner_items": [{ "id": "…", "code": "unknown_parent", "message": "…" }] },
  "remaps": { "ingredients": { "…pushed uuid…": "…existing uuid…" } }
}
```

- Every write in a batch is stamped with one new household `sync_version`; that number is
  the returned `cursor`. A batch that writes nothing (a poll) returns the household's
  current version unchanged, so an idle device's cursor stays put. `changes` holds every
  row whose version is above the request cursor, tombstones included, plus any row the
  device pushed but lost on so it converges.
- Conflicts are **last-write-wins by the client `updated_at`** (clamped to the server
  clock, stored as UTC). A newer live row restores a tombstone.
- Deleting a parent tombstones its children (dinner → items and plan entries, plan →
  entries, list → items). Deleting an ingredient tombstones its recipe lines and turns
  shopping-list lines into free text.
- Rows are validated individually. A bad row is listed in `rejected` with a code
  (`invalid`, `unknown_id`, `unknown_parent`) and skipped; it never fails the batch. Only a
  malformed envelope, an unknown resource key or an oversized batch returns `422`.
- `remaps` reports ingredients that were merged onto an existing same-named ingredient
  (two devices created "Melk" offline); the client rewrites its references and drops the
  duplicate.
- REST writes and deletes are versioned and tombstoned too, so a device with a cursor sees
  them on its next pull.

## Live sync

With `BROADCAST_CONNECTION=reverb`, every committed write (sync batch, REST, public API)
rings a doorbell over Laravel Reverb, so other devices pull at once instead of on their
next poll. The doorbell carries no rows. Devices still pull through `POST /sync`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/realtime` | Where to open the socket: `{ key, host, port, scheme }`, or `null` when live sync is off |
| `POST` | `/broadcasting/auth` | Authorize a channel for this socket (`socket_id`, `channel_name`); app tokens only |

Channels, all limited to members of the household:

- `private-household.{id}`: event `synced` with `{ "version": 42 }`, sent after the write
  commits. A device ignores a version at or below its cursor. The device that made the
  write sends `X-Socket-ID` with its sync request and is skipped.
- `presence-household.{id}.list.{uuid}`: who has a shopping list open.
- `presence-household.{id}.week.{YYYY-MM-DD}`: who is looking at a week of the plan (its Monday).

Member info is `{ id, name }`. A failed broadcast is only reported and never fails the
write. Devices without a socket keep polling.
