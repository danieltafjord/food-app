@php
    // The user's saved language wins; otherwise fall back to the public site's language cookie.
    $locale = match ($user->locale?->value ?? request()->cookie('locale')) {
        'nb', 'no' => 'no',
        default => 'en',
    };
    app()->setLocale($locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Handlelista') }} — {{ __('oauth.page_title') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #111827;
            padding: 1.5rem;
        }
        .card {
            width: 100%;
            max-width: 26rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            box-shadow: 0 10px 25px -10px rgba(0, 0, 0, 0.2);
            padding: 2rem;
        }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { color: #4b5563; line-height: 1.5; margin: 0 0 1rem; }
        .scopes { list-style: none; padding: 0; margin: 0 0 1.5rem; }
        .scopes li { padding: 0.5rem 0; border-top: 1px solid #f3f4f6; font-size: 0.875rem; }
        .fields { display: grid; gap: 0.375rem; margin: 0 0 1.5rem; }
        .fields label { font-size: 0.8125rem; font-weight: 600; }
        .fields select {
            width: 100%;
            padding: 0.5rem 0.625rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            background: transparent;
            color: inherit;
            font-size: 0.9375rem;
        }
        .actions { display: flex; gap: 0.75rem; }
        form { flex: 1; margin: 0; }
        button {
            width: 100%;
            padding: 0.625rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid transparent;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
        }
        .approve { background: #111827; color: #fff; }
        .deny { background: #fff; color: #374151; border-color: #d1d5db; }
        @media (prefers-color-scheme: dark) {
            body { background: #0b0f19; color: #f9fafb; }
            .card { background: #111827; border-color: #1f2937; }
            p { color: #9ca3af; }
            .scopes li { border-color: #1f2937; }
            .approve { background: #f9fafb; color: #111827; }
            .fields select { border-color: #374151; }
            .deny { background: transparent; color: #e5e7eb; border-color: #374151; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('oauth.heading') }}</h1>
        <p>
            {!! __('oauth.request', [
                'client' => '<strong>'.e($client->name).'</strong>',
                'app' => e(config('app.name', 'Handlelista')),
            ]) !!}
            @if (! empty($user->name)) ({{ $user->name }}) @endif
        </p>

        @php($households = $user->households()->orderBy('name')->get(['households.id', 'households.name']))

        @if ($households->isNotEmpty())
            <div class="fields">
                <label for="household_id">{{ __('oauth.household') }}</label>
                <select id="household_id" name="household_id" form="approve-form" required>
                    @foreach ($households as $household)
                        <option value="{{ $household->id }}">{{ $household->name }}</option>
                    @endforeach
                </select>

                <label for="can_write">{{ __('oauth.permissions') }}</label>
                <select id="can_write" name="can_write" form="approve-form" required>
                    <option value="0">{{ __('oauth.read_only') }}</option>
                    <option value="1">{{ __('oauth.read_write') }}</option>
                </select>
            </div>
        @endif

        @if ($households->isEmpty())
            <p>{{ __('oauth.no_household') }}</p>
        @endif

        <div class="actions">
            @if ($households->isNotEmpty())
                <form method="post" action="{{ route('oauth.household-authorizations.approve') }}" id="approve-form">
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="approve">{{ __('oauth.authorize') }}</button>
                </form>
            @endif

            <form method="post" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny">{{ __('oauth.cancel') }}</button>
            </form>
        </div>
    </div>
</body>
</html>
