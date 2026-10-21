<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sistem Informasi Penjualan Toko Jual Beli Alat Tulis Kerja di lingkungan Kota/Kabupaten Kuningan">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Agung ATK') - Sistem Informasi Penjualan ATK Kuningan</title>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 CSS & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">

    @include('partials.theme')

    @include('partials.theme-init')

    <style>
        body {
            background-color: var(--atk-bg);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
    </style>

    @stack('styles')
</head>
<body>
    {{-- Header / Navbar --}}
    @include('components.navbar')

    {{-- Flash Message (Global) --}}
    <div class="container mt-3">
        @include('partials.flash')
    </div>

    {{-- Main Content --}}
    <main class="flex-grow-1">
        {{-- Container bawaan layout; view yang sudah membawa container sendiri dapat melewatinya
             dengan mendefinisikan section `no-container`. --}}
        @hasSection('no-container')
            @yield('content')
        @else
            <div class="container py-4">
                @yield('content')
            </div>
        @endif
    </main>

    {{-- Footer --}}
    @include('components.footer')

    {{-- Chatbot widget mengambang (semua menu publik / luar) --}}
    @include('partials.visual-search')
    @include('partials.home-chatbot')

    <!-- Bootstrap 5.3 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    {{-- Loading state global: submit button otomatis spinner + terkunci (anti double-submit) --}}
    <script>
        (function () {
            document.addEventListener('submit', function (event) {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                const button = form.querySelector('button[type="submit"]:not([data-no-loading])');
                if (!button || button.dataset.loading === '1') {
                    return;
                }

                button.dataset.loading = '1';
                button.dataset.originalHtml = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Memproses...';
            });

            window.addEventListener('pageshow', function (event) {
                if (!event.persisted) {
                    return;
                }
                document.querySelectorAll('button[data-loading="1"]').forEach(function (button) {
                    button.disabled = false;
                    button.removeAttribute('data-loading');
                    if (button.dataset.originalHtml) {
                        button.innerHTML = button.dataset.originalHtml;
                        delete button.dataset.originalHtml;
                    }
                });
            });
        })();
    </script>

    @stack('scripts')
</body>
</html>
