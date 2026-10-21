{{-- ================================================================
     Template halaman error STANDALONE (tanpa query DB / session / navbar).
     Aman ditampilkan bahkan saat database atau layanan utama down.
     Dipakai oleh views/errors/404, 403, 419, 429, 500.
     ================================================================ --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $errorCode }} — {{ $errorTitle }} - Agung ATK</title>

    {{-- Design system murni CSS (tanpa query DB/session): aman untuk halaman error --}}
    @include('partials.theme')

    @include('partials.theme-init')

    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--atk-bg, #f8fafc);
            color: var(--atk-text, #334155);
            font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            text-align: center;
        }
        .atk-error-card {
            max-width: 32rem;
            width: 100%;
            background: var(--atk-card-bg, #ffffff);
            border: 1px solid var(--atk-border, #e2e8f0);
            border-radius: var(--atk-radius, 0.5rem);
            box-shadow: var(--atk-shadow-md, 0 8px 20px -8px rgba(15, 23, 42, 0.15));
            padding: 2.5rem 2rem;
        }
        .atk-error-code {
            font-size: 4rem;
            font-weight: 800;
            line-height: 1;
            color: var(--atk-primary, #1e3a8a);
            letter-spacing: -0.03em;
        }
        .atk-error-icon { font-size: 2rem; color: var(--atk-accent, #f59e0b); margin-bottom: 0.75rem; }
        .atk-error-title { font-size: 1.25rem; font-weight: 700; margin: 0.75rem 0 0.5rem; }
        .atk-error-desc { color: var(--atk-muted, #64748b); font-size: 0.95rem; margin-bottom: 1.75rem; }
        .atk-error-actions { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        .atk-btn {
            display: inline-block;
            padding: 0.6rem 1.25rem;
            border-radius: var(--atk-radius, 0.5rem);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .atk-btn-primary { background: var(--atk-primary, #1e3a8a); color: #fff; }
        .atk-btn-primary:hover { background: var(--atk-primary-hover, #1e40af); }
        .atk-btn-outline { background: transparent; color: var(--atk-primary, #1e3a8a); border-color: var(--atk-border, #e2e8f0); }
        .atk-btn-outline:hover { border-color: var(--atk-primary, #1e3a8a); }
        .atk-error-footer { margin-top: 1.5rem; font-size: 0.8rem; color: var(--atk-muted, #64748b); }
        [data-theme="dark"] body { background: var(--atk-bg, #0f172a); color: var(--atk-text, #e2e8f0); }
    </style>
</head>
<body>
    <main class="atk-error-card">
        <div class="atk-error-icon" aria-hidden="true">
            @if ($errorCode === 404)
                <i class="bi bi-compass"></i>
            @elseif ($errorCode === 403)
                <i class="bi bi-shield-lock"></i>
            @elseif ($errorCode === 419)
                <i class="bi bi-clock-history"></i>
            @elseif ($errorCode === 429)
                <i class="bi bi-speedometer2"></i>
            @else
                <i class="bi bi-exclamation-triangle"></i>
            @endif
        </div>

        <div class="atk-error-code">{{ $errorCode }}</div>
        <h1 class="atk-error-title">{{ $errorTitle }}</h1>
        <p class="atk-error-desc">{!! $errorDescription !!}</p>

        <div class="atk-error-actions">
            <a href="{{ url('/') }}" class="atk-btn atk-btn-primary">Kembali ke Beranda</a>
            <button type="button" class="atk-btn atk-btn-outline" onclick="history.length > 1 ? history.back() : location.assign('/')">
                Halaman Sebelumnya
            </button>
        </div>

        <p class="atk-error-footer">Agung ATK &mdash; Sistem Informasi Penjualan ATK Kuningan</p>
    </main>
</body>
</html>
