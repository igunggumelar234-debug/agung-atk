{{--
    Tombol pemilih tema: Terang (matahari), Gelap (bulan), Sistem (monitor).
    Dipakai di navbar publik & topbar dashboard. Preferensi disimpan di
    localStorage ('atk-theme') dan diterapkan via atribut data-theme di <html>.
    $buttonClass menyesuaikan gaya tombol dengan konteks letaknya.
--}}
@php($themeButtonClass = $buttonClass ?? 'btn-outline-secondary btn-sm')
<div class="dropdown" data-theme-toggle>
    <button type="button" class="{{ $themeButtonClass }} d-inline-flex align-items-center gap-1"
            data-bs-toggle="dropdown" aria-expanded="false"
            aria-label="Pilih tema tampilan" title="Pilih tema tampilan">
        <i class="bi bi-sun-fill" data-theme-icon="light" aria-hidden="true"></i>
        <span class="d-none d-sm-inline" data-theme-label>Tema</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <button type="button" class="dropdown-item" data-theme-option="light" aria-current="false">
                <i class="bi bi-sun me-2"></i> Mode Terang
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item" data-theme-option="dark" aria-current="false">
                <i class="bi bi-moon me-2"></i> Mode Gelap
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item" data-theme-option="system" aria-current="false">
                <i class="bi bi-monitor me-2"></i> Mode Sistem
            </button>
        </li>
    </ul>
</div>

<script>
    (function () {
        var STORAGE_KEY = 'atk-theme';
        var root = document.documentElement;

        function savedMode() {
            try {
                return localStorage.getItem(STORAGE_KEY) || 'system';
            } catch (e) {
                return 'system';
            }
        }

        function resolvedTheme() {
            if (savedMode() === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            return savedMode();
        }

        // Terapkan tema + sinkronkan ikon/label seluruh toggle di halaman.
        function applyTheme() {
            var theme = resolvedTheme();
            root.setAttribute('data-theme', theme);
            root.style.colorScheme = theme;

            document.querySelectorAll('[data-theme-toggle]').forEach(function (toggle) {
                var icon = toggle.querySelector('[data-theme-icon]');
                if (icon) {
                    icon.className = 'bi ' + (theme === 'dark' ? 'bi-moon-fill' : 'bi-sun-fill');
                    icon.setAttribute('data-theme-icon', theme);
                }
                var label = toggle.querySelector('[data-theme-label]');
                if (label) {
                    label.textContent = theme === 'dark' ? 'Gelap' : 'Terang';
                }
                toggle.querySelectorAll('[data-theme-option]').forEach(function (item) {
                    var isActive = item.getAttribute('data-theme-option') === savedMode();
                    item.classList.toggle('active', isActive);
                    item.setAttribute('aria-current', isActive ? 'true' : 'false');
                });
            });

            // Beri tahu komponen lain (mis. grafik Chart.js) agar menyesuaikan warna.
            window.dispatchEvent(new CustomEvent('atk:theme-changed', {
                detail: { theme: theme, mode: savedMode() },
            }));
        }

        // Mode Sistem: ikuti perubahan preferensi OS secara langsung.
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (savedMode() === 'system') {
                applyTheme();
            }
        });

        // Klik salah satu opsi tema (delegasi — berlaku untuk semua toggle).
        document.addEventListener('click', function (event) {
            var option = event.target.closest('[data-theme-option]');
            if (!option) {
                return;
            }
            try {
                localStorage.setItem(STORAGE_KEY, option.getAttribute('data-theme-option'));
            } catch (e) {
                // localStorage tidak tersedia — tema hanya berlaku sesi ini.
            }
            applyTheme();
        });

        // Sinkronkan ikon awal (tema sudah diterapkan oleh partials.theme-init).
        applyTheme();
    })();
</script>
