<script>
    // Terapkan tema SEBELUM halaman dirender untuk menghindari kedipan (FOUC).
    // Preferensi tersimpan di localStorage ('atk-theme'): 'light' | 'dark' | 'system'.
    // Nilai bawaan 'system' mengikuti preferensi perangkat (prefers-color-scheme).
    (function () {
        try {
            var saved = localStorage.getItem('atk-theme') || 'system';
            var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = saved === 'system' ? (systemDark ? 'dark' : 'light') : saved;
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        } catch (e) {
            // localStorage tidak tersedia — tema tetap bawaan (terang).
        }
    })();
</script>
