{{-- =====================================================================
     Design system bersama "Agung ATK" (Modern · Clean · Simple · Professional)
     Dipakai oleh layouts/app (storefront) & layouts/dashboard (area akun).
     Satu sumber kebenaran untuk warna, tombol, kartu, badge, form, tabel,
     empty-state, loading-state, dan utilitas responsif.
     ===================================================================== --}}
<style>
    :root {
        --atk-primary: #1e3a8a;
        --atk-primary-hover: #1e40af;
        --atk-primary-soft: #eff6ff;
        --atk-secondary: #0f172a;
        --atk-accent: #f59e0b;
        --atk-accent-hover: #d97706;
        --atk-bg: #f8fafc;
        --atk-card-bg: #ffffff;
        --atk-text: #334155;
        --atk-muted: #64748b;
        --atk-border: #e2e8f0;
        --atk-radius: 0.5rem;
        --atk-shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.07);
        --atk-shadow-md: 0 8px 20px -8px rgba(15, 23, 42, 0.15);

        /* Seragamkan radius bawaan Bootstrap (tombol, input, badge, card) */
        --bs-border-radius: 0.5rem;
        --bs-border-radius-sm: 0.375rem;
        --bs-border-radius-lg: 0.75rem;
    }

    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--atk-text);
        -webkit-font-smoothing: antialiased;
    }

    /* ============================== Tombol ============================== */
    .btn {
        border-radius: var(--atk-radius);
        font-weight: 600;
        transition: all 0.18s ease-in-out;
    }

    .btn-xs {
        padding: 0.18rem 0.5rem;
        font-size: 0.78rem;
    }

    .btn-primary-atk {
        background-color: var(--atk-primary);
        border-color: var(--atk-primary);
        color: #ffffff;
    }

    .btn-primary-atk:hover,
    .btn-primary-atk:focus-visible {
        background-color: var(--atk-primary-hover);
        border-color: var(--atk-primary-hover);
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 6px 14px -6px rgba(30, 58, 138, 0.55);
    }

    .btn-accent-atk {
        background-color: var(--atk-accent);
        border-color: var(--atk-accent);
        color: var(--atk-secondary);
    }

    .btn-accent-atk:hover,
    .btn-accent-atk:focus-visible {
        background-color: var(--atk-accent-hover);
        border-color: var(--atk-accent-hover);
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 6px 14px -6px rgba(217, 119, 6, 0.55);
    }

    .btn:disabled,
    .btn[disabled] {
        transform: none;
        box-shadow: none;
    }

    .btn .spinner-border-sm {
        vertical-align: -0.125em;
    }

    .btn-group-xs .btn {
        padding: 0.2rem 0.45rem;
        font-size: 0.78rem;
    }

    /* ============================== Kartu =============================== */
    .card {
        border-radius: var(--atk-radius);
    }

    .card-custom {
        border: 1px solid var(--atk-border);
        border-radius: var(--atk-radius);
        background-color: var(--atk-card-bg);
        box-shadow: var(--atk-shadow-sm);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .card-custom:hover {
        box-shadow: var(--atk-shadow-md);
    }

    .card-stat {
        background: var(--atk-card-bg);
        border: 1px solid var(--atk-border);
        border-radius: var(--atk-radius);
        padding: 1.25rem;
        box-shadow: var(--atk-shadow-sm);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .card-stat:hover {
        transform: translateY(-2px);
        box-shadow: var(--atk-shadow-md);
    }

    .card-header {
        background-color: var(--atk-card-bg);
        border-bottom: 1px solid var(--atk-border);
        font-weight: 700;
        color: var(--atk-secondary);
    }

    /* ============================== Badge =============================== */
    .badge {
        font-weight: 600;
        letter-spacing: 0.01em;
    }

    .badge-soft {
        padding: 0.4rem 0.7rem;
        font-size: 0.74rem;
        border: 1px solid transparent;
    }

    .role-badge-admin { background-color: #fee2e2; color: #991b1b; }
    .role-badge-seller, .role-badge-penjual, .role-badge-anggota { background-color: #dcfce7; color: #166534; }
    .role-badge-courier, .role-badge-kurir { background-color: #e0f2fe; color: #075985; }
    .role-badge-customer, .role-badge-pelanggan { background-color: #fef3c7; color: #92400e; }

    /* ============================== Form ================================ */
    .form-control,
    .form-select {
        border-color: #dbe1ea;
        color: var(--atk-secondary);
    }

    .form-control::placeholder {
        color: #94a3b8;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--atk-primary);
        box-shadow: 0 0 0 0.2rem rgba(30, 58, 138, 0.14);
    }

    .form-label {
        font-weight: 600;
        color: var(--atk-secondary);
        font-size: 0.9rem;
        margin-bottom: 0.4rem;
    }

    .form-text {
        color: var(--atk-muted);
        font-size: 0.82rem;
    }

    .input-group-text {
        background-color: #f1f5f9;
        border-color: #dbe1ea;
        color: var(--atk-muted);
    }

    /* ============================== Tabel =============================== */
    .table > :not(caption) > * > * {
        padding: 0.75rem 0.9rem;
        vertical-align: middle;
    }

    .table thead th {
        background-color: #f1f5f9 !important;
        /* Audit a11y: #475569 memberi kontras 6.8:1 pada #f1f5f9 (AA) */
        color: #475569;
        font-size: 0.73rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom-width: 1px;
        white-space: nowrap;
    }

    .table-hover > tbody > tr:hover > * {
        background-color: #f8fafc;
        color: inherit;
    }

    /* ========================= Empty / Error state ====================== */
    .empty-state {
        text-align: center;
        padding: 2.25rem 1.25rem;
    }

    .empty-state-icon {
        font-size: 2.4rem;
        color: #94a3b8;
        line-height: 1;
    }

    .empty-state-title {
        font-weight: 700;
        color: var(--atk-secondary);
        margin-top: 0.6rem;
        font-size: 1rem;
    }

    .empty-state-text {
        color: var(--atk-muted);
        font-size: 0.9rem;
        max-width: 430px;
        margin: 0.35rem auto 0;
    }

    /* ============================ Utilitas ============================== */
    .bg-primary-atk { background-color: var(--atk-primary) !important; }
    .text-primary-atk { color: var(--atk-primary) !important; }
    .text-muted-atk { color: var(--atk-muted) !important; }

    .gradient-brand {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
    }

    .feature-icon-wrapper {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.75rem;
        flex-shrink: 0;
    }

    .option-card {
        cursor: pointer;
        border: 1.5px solid var(--atk-border);
        border-radius: var(--atk-radius);
        background: #ffffff;
        transition: border-color 0.15s ease, background-color 0.15s ease;
        height: 100%;
    }

    .option-card:hover {
        border-color: #93c5fd;
    }

    .option-card.is-selected {
        border-color: var(--atk-primary);
        background-color: var(--atk-primary-soft);
    }

    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .content-ellipsis {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Progres langkah status pesanan / pengiriman */
    .status-steps {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }

    .status-step {
        padding: 0.35rem 0.7rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #94a3b8;
        border: 1px solid var(--atk-border);
    }

    .status-step.is-done {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }

    .status-step.is-current {
        background: var(--atk-primary);
        color: #ffffff;
        border-color: var(--atk-primary);
        box-shadow: 0 4px 10px -4px rgba(30, 58, 138, 0.5);
    }

    .status-step.is-cancelled {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    /* ===================== Audit a11y: kontras AA ========================
       Elemen di bawah ini sebelumnya di bawah 4.5:1 (Lighthouse axe):
       badge pada background subtle, serta teks abu/merah di sidebar gelap.
       Hanya warna teks yang digelapkan — class & makna tidak berubah. */
    /* Utility Bootstrap memakai !important, jadi override juga penting. */
    .badge.text-success {
        color: #0f5132 !important;
    }

    .badge.text-primary {
        color: #0a58ca !important;
    }

    .badge.text-secondary {
        color: #5a6268 !important;
    }

    .dashboard-sidebar .text-secondary {
        color: #94a3b8 !important;
    }

    .dashboard-sidebar .btn-outline-danger {
        color: #ff8a90;
        border-color: #ff8a90;
    }

    /* Footer gelap: text-secondary (#6c757d) hanya 3.3:1 pada bg-dark */
    footer .text-secondary {
        color: #adb5bd !important;
    }

    /* Teks hijau di footer gelap: #198754 hanya 3.8:1 -> hijau terang */
    footer .text-success {
        color: #4ade80 !important;
    }

    /* Breadcrumb: tautan & item aktif 4.3:1 pada body bg -> gelapkan */
    .breadcrumb-item a,
    .breadcrumb-item.active {
        color: #475569 !important;
    }

    /* ============================ Responsif ============================= */
    @media (max-width: 575.98px) {
        .table > :not(caption) > * > * {
            padding: 0.6rem 0.7rem;
        }
    }

    /* ============================ Transisi tema ======================== */
    body, .card, .card-custom, .card-stat, .top-navbar, .table, .form-control,
    .form-select, .btn, .modal-content, .dropdown-menu, .dropdown-item,
    .input-group-text, .badge, .chatbot-panel, .chatbot-messages,
    .chatbot-input-row, .chatbot-input {
        transition: background-color 0.25s ease, border-color 0.25s ease,
            color 0.25s ease, box-shadow 0.25s ease;
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
        }
    }

    /* =========================== Mode Gelap =============================
       Variabel identitas Agung ATK dipertahankan; hanya kecerahan yang
       disesuaikan agar kontras nyaman di latar gelap (bukan sekadar
       membalik warna). Warna primer dicerahkan agar tombol tetap terbaca.
       ================================================================== */
    :root {
        color-scheme: light;
    }

    [data-theme="dark"] {
        color-scheme: dark;
        --atk-primary: #3b82f6;
        --atk-primary-hover: #60a5fa;
        --atk-primary-soft: #172554;
        --atk-secondary: #f1f5f9;
        --atk-accent: #fbbf24;
        --atk-accent-hover: #f59e0b;
        --atk-bg: #0f172a;
        --atk-card-bg: #1e293b;
        --atk-text: #e2e8f0;
        --atk-muted: #94a3b8;
        --atk-border: #334155;
        --atk-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.45);
        --atk-shadow-md: 0 8px 20px -8px rgba(0, 0, 0, 0.65);

        /* Utilitas Bootstrap ikut menyesuaikan */
        --bs-body-bg: #0f172a;
        --bs-body-color: #e2e8f0;
        --bs-secondary-color: #94a3b8;
        --bs-tertiary-color: #64748b;
        --bs-border-color: #334155;
    }

    [data-theme="dark"] body {
        background-color: #0f172a;
    }

    /* Topbar dashboard (warna putih hardcoded di layout) */
    [data-theme="dark"] .top-navbar {
        background-color: #1e293b;
    }

    [data-theme="dark"] .top-navbar .text-secondary {
        color: #94a3b8 !important;
    }

    /* Tabel */
    [data-theme="dark"] .table thead th {
        background-color: #0f172a !important;
        color: #94a3b8;
    }

    [data-theme="dark"] .table-hover > tbody > tr:hover > * {
        background-color: rgba(59, 130, 246, 0.08);
    }

    /* Form & input */
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select {
        background-color: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    [data-theme="dark"] .form-control::placeholder {
        color: #64748b;
    }

    [data-theme="dark"] .form-control:focus,
    [data-theme="dark"] .form-select:focus {
        background-color: #0f172a;
        color: #f1f5f9;
        border-color: var(--atk-primary);
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
    }

    [data-theme="dark"] .input-group-text {
        background-color: #1e293b;
        border-color: #334155;
        color: #94a3b8;
    }

    /* Dropdown (menu tema, menu profil, filter) */
    [data-theme="dark"] .dropdown-menu {
        background-color: #1e293b;
        border-color: #334155;
        color: #e2e8f0;
    }

    [data-theme="dark"] .dropdown-item {
        color: #e2e8f0;
    }

    [data-theme="dark"] .dropdown-item:hover,
    [data-theme="dark"] .dropdown-item:focus {
        background-color: #334155;
        color: #f1f5f9;
    }

    [data-theme="dark"] .dropdown-item.active,
    [data-theme="dark"] .dropdown-item:active {
        background-color: var(--atk-primary);
        color: #ffffff;
    }

    [data-theme="dark"] .dropdown-divider {
        border-top-color: #334155;
    }

    /* Modal & dialog */
    [data-theme="dark"] .modal-content {
        background-color: #1e293b;
        color: #e2e8f0;
        border-color: #334155;
    }

    [data-theme="dark"] .modal-header {
        border-bottom-color: #334155;
    }

    [data-theme="dark"] .modal-footer {
        border-top-color: #334155;
    }

    [data-theme="dark"] .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }

    /* Utilitas Bootstrap yang hardcoded terang */
    [data-theme="dark"] .bg-light {
        background-color: #1e293b !important;
    }

    /* Kotak chat AI (latar inline #f8fafc) -> latar gelap halaman agar
       gelembung bot (.bg-white -> #1e293b) tetap kontras di atasnya. */
    [data-theme="dark"] #chatBox {
        background-color: #0f172a !important;
    }

    [data-theme="dark"] .bg-white {
        background-color: #1e293b !important;
    }

    [data-theme="dark"] .text-dark {
        color: #f1f5f9 !important;
    }

    [data-theme="dark"] .text-body {
        color: #e2e8f0 !important;
    }

    [data-theme="dark"] .btn-outline-secondary {
        color: #94a3b8;
        border-color: #475569;
        background-color: transparent;
    }

    [data-theme="dark"] .btn-outline-secondary:hover,
    [data-theme="dark"] .btn-outline-secondary:focus-visible {
        background-color: #334155;
        border-color: #475569;
        color: #f1f5f9;
    }

    /* Breadcrumb */
    [data-theme="dark"] .breadcrumb-item a,
    [data-theme="dark"] .breadcrumb-item.active {
        color: #94a3b8 !important;
    }

    /* Alert & flash message */
    [data-theme="dark"] .alert-success {
        background-color: #052e16;
        color: #4ade80;
        border-color: #14532d;
    }

    [data-theme="dark"] .alert-info {
        background-color: #082f49;
        color: #38bdf8;
        border-color: #0c4a6e;
    }

    [data-theme="dark"] .alert-warning {
        background-color: #451a03;
        color: #fbbf24;
        border-color: #78350f;
    }

    [data-theme="dark"] .alert-danger {
        background-color: #450a0a;
        color: #f87171;
        border-color: #7f1d1d;
    }

    /* Progres langkah status pesanan / pengiriman */
    [data-theme="dark"] .status-step {
        background: #1e293b;
        color: #64748b;
        border-color: #334155;
    }

    /* Kartu opsi (pilihan alamat / metode pembayaran) */
    [data-theme="dark"] .option-card {
        background: #1e293b;
    }

    [data-theme="dark"] .option-card:hover {
        border-color: #475569;
    }

    [data-theme="dark"] .option-card.is-selected {
        background-color: #172554;
    }

    /* Empty state */
    [data-theme="dark"] .empty-state-icon {
        color: #64748b;
    }

    /* Chatbot mengambang */
    [data-theme="dark"] .chatbot-panel {
        background-color: #1e293b;
    }

    [data-theme="dark"] .chatbot-messages {
        background-color: #0f172a;
    }

    [data-theme="dark"] .chatbot-bubble-bot {
        background-color: #0f172a;
        color: #e2e8f0;
        border-color: #334155;
    }

    [data-theme="dark"] .chatbot-input-row {
        background-color: #1e293b;
        border-top-color: #334155;
    }

    [data-theme="dark"] .chatbot-input {
        background-color: #0f172a;
        color: #e2e8f0;
        border-color: #334155;
    }

    /* Hero halaman publik (beranda, login, registrasi) */
    [data-theme="dark"] .auth-hero {
        background: linear-gradient(180deg, #172554 0%, #0f172a 100%) !important;
    }
</style>
