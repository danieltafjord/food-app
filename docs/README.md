# Food App

A shared meal-planning backend. A **household** (you + whoever you invite) keeps a
catalogue of **ingredients**, builds **dinners** (recipes) from them, schedules those
dinners onto days in a **dinner plan**, and turns a plan into a **shopping list** —
with quantities automatically scaled to the servings you're cooking and combined per
ingredient.

It's a **backend-only API** meant to be consumed by a mobile app. Authentication is
delegated to the existing web auth pages, so the API never handles passwords directly.
An optional Svelte/Inertia backoffice can sit on the normal session guard for admin.

## Documentation

- **[architecture.md](architecture.md)** — how the backend is structured: the request
  lifecycle, where code lives, household scoping, and the conventions to follow when
  adding features.
- **[api-reference.md](api-reference.md)** — the HTTP API: the auth flow, response and
  error conventions, and every endpoint grouped by resource.

## Tech stack

- PHP 8.4, Laravel 13
- **SQLite** database
- **Laravel Passport** — OAuth2 (Authorization Code + PKCE) for mobile tokens
- **Laravel Fortify** — the underlying auth (login, registration, password reset, email
  verification, 2FA, passkeys); Passport delegates to it
- **spatie/laravel-data** — typed request/response DTOs
- **Pest** — tests

## Local setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Create the SQLite database file, then run migrations + demo seed data
touch database/database.sqlite
php artisan migrate --seed

# Passport signing keys (only if storage/oauth-*.key are missing)
php artisan passport:keys

# A first-party public client for the mobile app (Authorization Code + PKCE)
php artisan passport:client --public --name="Food App Mobile" \
    --redirect_uri="foodapp://oauth/callback"
```

Run everything (server, queue, logs, Vite) with:

```bash
composer run dev
```

## Tests

```bash
php artisan test --compact            # whole suite
php artisan test --compact --filter=ShoppingListGenerationTest
```

The suite covers the schema, the auth surface, household/member/invitation flows,
every resource (including cross-household isolation and role enforcement), and the
shopping-list generation math.

## Demo data

`php artisan migrate --seed` runs `FoodSeeder`, which creates a household with two
members, an ingredient catalogue, two dinners, a weekly plan, and a shopping list — a
realistic dataset to explore the API against.
