<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }} — Authorization</title>
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
            .deny { background: transparent; color: #e5e7eb; border-color: #374151; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Authorization request</h1>
        <p>
            <strong>{{ $client->name }}</strong> is requesting permission to access
            your {{ config('app.name', 'Laravel') }} account
            @if (! empty($user->name)) ({{ $user->name }}) @endif.
        </p>

        @if (count($scopes) > 0)
            <ul class="scopes">
                @foreach ($scopes as $scope)
                    <li>{{ $scope->description }}</li>
                @endforeach
            </ul>
        @endif

        <div class="actions">
            <form method="post" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="approve">Authorize</button>
            </form>

            <form method="post" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny">Cancel</button>
            </form>
        </div>
    </div>
</body>
</html>
