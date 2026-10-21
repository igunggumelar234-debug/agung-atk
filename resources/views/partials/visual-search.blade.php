{{-- =====================================================================
     Visual Search ATK — pencarian produk berbasis gambar (bukan barcode).
     Dipakai oleh: halaman pencarian katalog, chatbot Beranda, dan /ai.
     API global:
       window.AtkVisualSearch.open(context, onResult?)
         - context: 'catalog' | 'chatbot'
         - onResult(data, photoUrl): bila diisi, hasil JSON dikirim ke callback
           (dipakai chatbot untuk menyisipkan balasan + kartu produk ke
           percakapan); bila kosong, hasil dirender di dalam modal.
     Kamera memakai getUserMedia(); stream dihentikan saat modal ditutup,
     pengambilan gambar selesai, atau pengguna meninggalkan halaman.
     Fallback unggah gambar selalu tersedia bila izin kamera ditolak.
     ===================================================================== --}}
<div class="modal fade" id="atkVsModal" tabindex="-1" aria-labelledby="atkVsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="atkVsModalLabel">
                    <i class="bi bi-camera-video me-2" aria-hidden="true"></i>Cari Produk dengan Foto
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                {{-- Pilihan langkah pertama: kamera atau unggah --}}
                <div id="atkVsChooser">
                    <p class="small text-muted-atk mb-3">
                        Ambil foto barang ATK (pulpen, buku tulis, gunting, stapler, dll) atau unggah gambar
                        dari perangkat — sistem menganalisis foto lalu mencari produk paling mirip di katalog.
                        <span class="d-block fst-italic">Ini bukan pemindai barcode.</span>
                    </p>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" id="atkVsStartCamera" class="btn btn-primary-atk flex-fill">
                            <i class="bi bi-camera me-1" aria-hidden="true"></i> Ambil Foto
                        </button>
                        <button type="button" id="atkVsStartUpload" class="btn btn-outline-secondary flex-fill">
                            <i class="bi bi-image me-1" aria-hidden="true"></i> Unggah Gambar
                        </button>
                    </div>
                </div>

                {{-- Pratinjau kamera langsung --}}
                <div id="atkVsCamera" hidden>
                    <video id="atkVsVideo" class="w-100 rounded bg-dark" autoplay playsinline muted
                           style="max-height: 320px; object-fit: contain;"></video>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <button type="button" id="atkVsCapture" class="btn btn-primary-atk flex-fill">
                            <i class="bi bi-camera" aria-hidden="true"></i> Ambil Foto
                        </button>
                        <button type="button" id="atkVsCameraCancel" class="btn btn-outline-secondary flex-fill">
                            Batal
                        </button>
                    </div>
                </div>

                {{-- Pratinjau hasil jepretan / unggahan --}}
                <div id="atkVsPreview" hidden>
                    <img id="atkVsPreviewImg" alt="Pratinjau foto yang akan dianalisis"
                         class="w-100 rounded border" style="max-height: 320px; object-fit: contain;">
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <button type="button" id="atkVsRetake" class="btn btn-outline-secondary flex-fill">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Ulangi Foto
                        </button>
                        <button type="button" id="atkVsUse" class="btn btn-primary-atk flex-fill">
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Gunakan Foto
                        </button>
                        <button type="button" id="atkVsPreviewCancel" class="btn btn-outline-danger flex-fill">
                            Batal
                        </button>
                    </div>
                </div>

                {{-- Indikator proses analisis --}}
                <div id="atkVsLoading" hidden class="text-center py-4" role="status" aria-live="polite">
                    <div class="spinner-border text-primary mb-2" aria-hidden="true"></div>
                    <div class="fw-semibold">Menganalisis gambar…</div>
                    <div class="small text-muted-atk">Mencari produk ATK yang paling mirip di katalog.</div>
                </div>

                {{-- Hasil analisis --}}
                <div id="atkVsResults" hidden>
                    <div id="atkVsMessage" class="alert d-none" role="alert"></div>
                    <div id="atkVsGrid" class="row row-cols-2 row-cols-md-3 g-3"></div>
                    <div id="atkVsSuggest" class="mt-3" hidden>
                        <div class="small fw-semibold mb-1">Alternatif lain:</div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" id="atkVsSuggestPhoto" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-camera me-1" aria-hidden="true"></i> Coba foto lain
                            </button>
                            <button type="button" id="atkVsSuggestUpload" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-image me-1" aria-hidden="true"></i> Unggah lebih jelas
                            </button>
                            <button type="button" id="atkVsSuggestManual" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-keyboard me-1" aria-hidden="true"></i> Ketik nama barang
                            </button>
                            <a id="atkVsSuggestCategory" href="{{ route('products.index') }}"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-collection me-1" aria-hidden="true"></i> Lihat katalog ATK
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Status/error kamera & analisis (terlihat di semua langkah) --}}
                <div id="atkVsStatus" class="small text-muted-atk mt-3" role="status" aria-live="polite"></div>

                <input type="file" id="atkVsFile" accept="image/jpeg,image/png,image/webp"
                       class="visually-hidden" aria-hidden="true" tabindex="-1">
            </div>
            <div class="modal-footer">
                <button type="button" id="atkVsAgain" class="btn btn-outline-secondary" hidden>
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Foto / Unggah Lain
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        'use strict';

        const cfg = {
            endpoint: @json(route('image.search')),
            cartEndpoint: @json(route('cart.store')),
            catalogUrl: @json(route('products.index')),
            maxUploadKb: @json((int) config('services.image_search.max_upload_kb', 2048)),
            canAddToCart: @json(auth()->check() && auth()->user()->isCustomer()),
            csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
        };

        const modalEl = document.getElementById('atkVsModal');
        if (!modalEl) {
            return;
        }

        // Catatan: bundle Bootstrap dimuat SETELAH partial ini, jadi ketersediaan
        // `bootstrap` hanya dicek saat pengguna membuka modal (runtime), bukan
        // saat halaman dimuat.

        const chooser = document.getElementById('atkVsChooser');
        const cameraPanel = document.getElementById('atkVsCamera');
        const previewPanel = document.getElementById('atkVsPreview');
        const loadingPanel = document.getElementById('atkVsLoading');
        const resultsPanel = document.getElementById('atkVsResults');
        const statusEl = document.getElementById('atkVsStatus');
        const video = document.getElementById('atkVsVideo');
        const previewImg = document.getElementById('atkVsPreviewImg');
        const fileInput = document.getElementById('atkVsFile');
        const messageEl = document.getElementById('atkVsMessage');
        const gridEl = document.getElementById('atkVsGrid');
        const suggestEl = document.getElementById('atkVsSuggest');
        const againBtn = document.getElementById('atkVsAgain');

        let stream = null;
        let capturedBlob = null;
        let capturedUrl = null;
        let capturedFromUpload = false;
        let context = 'catalog';
        let resultCallback = null;
        let busy = false;

        function showStep(step) {
            chooser.hidden = step !== 'chooser';
            cameraPanel.hidden = step !== 'camera';
            previewPanel.hidden = step !== 'preview';
            loadingPanel.hidden = step !== 'loading';
            resultsPanel.hidden = step !== 'results';
            againBtn.hidden = step !== 'results';
        }

        function setStatus(text, isError) {
            statusEl.textContent = text || '';
            statusEl.classList.toggle('text-danger', !!isError);
            statusEl.classList.toggle('text-muted-atk', !isError);
        }

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(function (track) {
                    track.stop();
                });
                stream = null;
            }
            if (video) {
                video.srcObject = null;
            }
        }

        function resetAll() {
            stopCamera();
            if (capturedUrl) {
                URL.revokeObjectURL(capturedUrl);
                capturedUrl = null;
            }
            capturedBlob = null;
            capturedFromUpload = false;
            busy = false;
            fileInput.value = '';
            messageEl.className = 'alert d-none';
            messageEl.textContent = '';
            gridEl.innerHTML = '';
            suggestEl.hidden = true;
            previewImg.removeAttribute('src');
            setStatus('');
            showStep('chooser');
        }

        function cameraUnavailable(message) {
            stopCamera();
            setStatus(message);
            showStep('chooser');
        }

        async function startCamera() {
            stopCamera();
            showStep('camera');
            document.getElementById('atkVsCapture').disabled = true;

            if (!window.isSecureContext) {
                cameraUnavailable('Kamera hanya tersedia pada koneksi aman (HTTPS atau localhost). Silakan unggah gambar dari perangkat.');
                return;
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                cameraUnavailable('Browser ini tidak mendukung akses kamera. Silakan unggah gambar dari perangkat.');
                return;
            }

            setStatus('Meminta izin kamera… Bila browser menampilkan permintaan, pilih "Izinkan".');
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
                    audio: false
                });
                video.srcObject = stream;
                try { await video.play(); } catch (e) { /* autoplay muted diperbolehkan */ }
                document.getElementById('atkVsCapture').disabled = false;
                setStatus('Arahkan kamera ke barang ATK lalu tekan "Ambil Foto".');
            } catch (error) {
                const name = error && error.name;
                if (name === 'NotAllowedError' || name === 'SecurityError') {
                    cameraUnavailable('Izin kamera ditolak. Anda tetap bisa mencari produk dengan mengunggah gambar dari perangkat.');
                } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                    cameraUnavailable('Kamera tidak ditemukan di perangkat ini. Silakan unggah gambar dari perangkat.');
                } else {
                    cameraUnavailable('Kamera tidak dapat diakses. Silakan unggah gambar dari perangkat.');
                }
            }
        }

        function capturePhoto() {
            if (!stream || !video.videoWidth) {
                setStatus('Kamera belum siap. Tunggu sebentar atau unggah gambar.', true);
                return;
            }
            const scale = Math.min(1, 1280 / video.videoWidth);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
            canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            stopCamera();
            canvas.toBlob(function (blob) {
                if (!blob) {
                    cameraUnavailable('Gagal mengambil gambar. Coba lagi atau unggah gambar dari perangkat.');
                    return;
                }
                capturedFromUpload = false;
                showPhotoPreview(blob);
            }, 'image/jpeg', 0.9);
        }

        function showPhotoPreview(blob) {
            if (capturedUrl) {
                URL.revokeObjectURL(capturedUrl);
            }
            capturedBlob = blob;
            capturedUrl = URL.createObjectURL(blob);
            previewImg.src = capturedUrl;
            showStep('preview');
            setStatus('Periksa gambar — tekan "Gunakan Foto" untuk menganalisis, atau "Ulangi Foto" bila kurang jelas.');
        }

        function openFilePicker() {
            fileInput.value = '';
            fileInput.click();
        }

        async function analyzePhoto() {
            if (busy || !capturedBlob) {
                return;
            }
            busy = true;
            showStep('loading');
            setStatus('');

            try {
                const form = new FormData();
                form.append('image', capturedBlob, 'foto-atk.jpg');
                form.append('context', context);

                const response = await fetch(cfg.endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': cfg.csrf
                    },
                    body: form
                });

                if (response.status === 429) {
                    showFailure('Terlalu banyak permintaan pencarian gambar. Mohon tunggu sebentar lalu coba lagi.');
                    return;
                }
                if (response.status === 422) {
                    const error = await response.json().catch(function () { return {}; });
                    const first = error.errors && error.errors.image && error.errors.image[0];
                    showFailure(first || ('Gambar tidak valid. Gunakan JPG, PNG, atau WebP maksimal ' + cfg.maxUploadKb + ' KB.'));
                    return;
                }
                if (!response.ok) {
                    showFailure('Analisis gambar gagal karena gangguan teknis. Silakan coba lagi.');
                    return;
                }

                const data = await response.json();

                if (typeof resultCallback === 'function') {
                    const callback = resultCallback;
                    const photoUrl = capturedUrl;
                    capturedUrl = null; // serahkan kepemilikan URL ke callback
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.hide();
                    resetAfterCallback();
                    callback(data, photoUrl);
                    return;
                }

                renderResults(data);
            } catch (error) {
                showFailure('Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.');
            } finally {
                busy = false;
            }
        }

        function resetAfterCallback() {
            stopCamera();
            capturedBlob = null;
            capturedFromUpload = false;
            fileInput.value = '';
            setStatus('');
            showStep('chooser');
        }

        function showFailure(message) {
            showStep('results');
            renderMessage('alert-danger', message);
            gridEl.innerHTML = '';
            suggestEl.hidden = false;
        }

        function renderMessage(alertClass, text) {
            messageEl.className = 'alert ' + alertClass;
            messageEl.textContent = text;
        }

        function renderResults(data) {
            showStep('results');
            gridEl.innerHTML = '';
            const status = data.status || 'uncertain';

            if (status === 'recognized') {
                renderMessage('alert-success', data.message || 'Gambar dikenali.');
            } else if (status === 'not_atk' || status === 'uncertain') {
                renderMessage('alert-warning', data.message || 'Maaf, gambar belum dapat dikenali sebagai produk ATK.');
            } else {
                renderMessage('alert-danger', data.message || 'Gambar tidak dapat diproses. Silakan coba lagi.');
            }

            const products = Array.isArray(data.products) ? data.products : [];
            if (products.length) {
                gridEl.innerHTML = renderProducts(products, 'grid');
            }
            suggestEl.hidden = false;
        }

        /**
         * Render kartu produk (konsisten dengan gaya katalog).
         * variant: 'grid' (modal) | 'chat' (balasan chatbot).
         */
        function renderProducts(products, variant) {
            return products.map(function (product) {
                const thumb = product.image_url
                    ? '<img src="' + escapeAttr(product.image_url) + '" alt="' + escapeAttr(product.name) + '" class="w-100 h-100" style="object-fit: cover;">'
                    : '<i class="bi bi-card-image display-6 text-secondary opacity-25" aria-hidden="true"></i>';
                const imageBox = '<div class="bg-light rounded d-flex align-items-center justify-content-center overflow-hidden" style="height: ' + (variant === 'chat' ? '56px; width: 56px; flex: 0 0 56px;' : '140px;') + '">' + thumb + '</div>';

                let stockBadge;
                if (product.stock_available === false || product.stock <= 0) {
                    stockBadge = '<span class="badge bg-danger">Stok Habis</span>';
                } else if (product.min_stock && product.stock <= product.min_stock) {
                    stockBadge = '<span class="badge bg-warning text-dark">Sisa ' + product.stock + (product.unit ? ' ' + escapeHtml(product.unit) : '') + '</span>';
                } else {
                    stockBadge = '<span class="badge bg-success-subtle text-success">Stok ' + product.stock + (product.unit ? ' ' + escapeHtml(product.unit) : '') + '</span>';
                }

                const viewBtn = '<a href="' + escapeAttr(product.url) + '" class="btn btn-sm btn-outline-primary">Lihat Produk</a>';
                let addBtn = '';
                if (cfg.canAddToCart && product.stock_available !== false && product.stock > 0) {
                    addBtn = '<button type="button" class="btn btn-sm btn-primary-atk" data-atk-add-cart="' + product.id + '">Tambah ke Keranjang</button>';
                }

                if (variant === 'chat') {
                    return '<div class="d-flex gap-2 align-items-center border rounded p-2" style="white-space: normal;">'
                        + imageBox
                        + '<div class="flex-grow-1" style="min-width: 0;">'
                        + '<div class="small fw-semibold" style="white-space: normal;">' + escapeHtml(product.name) + '</div>'
                        + '<div class="fw-bold text-primary-atk">' + escapeHtml(product.price_formatted) + '</div>'
                        + '<div class="mt-1">' + stockBadge + '</div>'
                        + '</div>'
                        + '<div class="d-flex flex-column gap-1">' + viewBtn + addBtn + '</div>'
                        + '</div>';
                }

                return '<div class="col"><div class="card card-custom h-100 border-0 shadow-sm">'
                    + '<a href="' + escapeAttr(product.url) + '" class="text-decoration-none">' + imageBox + '</a>'
                    + '<div class="card-body d-flex flex-column p-3">'
                    + (product.category ? '<span class="badge bg-primary-subtle text-primary align-self-start mb-2 text-truncate" style="max-width: 100%;">' + escapeHtml(product.category) + '</span>' : '')
                    + '<div class="fw-bold text-dark mb-1 fs-6" style="white-space: normal;">' + escapeHtml(product.name) + '</div>'
                    + '<div class="fw-bold text-primary-atk fs-5 mb-2">' + escapeHtml(product.price_formatted) + '</div>'
                    + '<div class="mt-auto">' + stockBadge + '</div>'
                    + '</div>'
                    + '<div class="card-footer bg-transparent d-flex flex-column gap-1 py-2 px-3">' + viewBtn + addBtn + '</div>'
                    + '</div></div>';
            }).join('');
        }

        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function escapeAttr(value) {
            return escapeHtml(value);
        }

        window.AtkVisualSearch = {
            open: function (nextContext, onResult) {
                if (typeof bootstrap === 'undefined') {
                    return;
                }
                context = nextContext === 'chatbot' ? 'chatbot' : 'catalog';
                resultCallback = typeof onResult === 'function' ? onResult : null;
                resetAll();
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            },
            renderProducts: renderProducts
        };

        /* ---- Aksi tombol ---- */
        document.getElementById('atkVsStartCamera').addEventListener('click', startCamera);
        document.getElementById('atkVsStartUpload').addEventListener('click', openFilePicker);
        document.getElementById('atkVsCapture').addEventListener('click', capturePhoto);
        document.getElementById('atkVsCameraCancel').addEventListener('click', function () {
            stopCamera();
            setStatus('');
            showStep('chooser');
        });
        document.getElementById('atkVsRetake').addEventListener('click', function () {
            if (capturedFromUpload) {
                openFilePicker();
            } else {
                startCamera();
            }
        });
        document.getElementById('atkVsUse').addEventListener('click', analyzePhoto);
        document.getElementById('atkVsPreviewCancel').addEventListener('click', function () {
            resetAll();
        });
        againBtn.addEventListener('click', function () {
            resetAll();
        });
        document.getElementById('atkVsSuggestPhoto').addEventListener('click', startCamera);
        document.getElementById('atkVsSuggestUpload').addEventListener('click', openFilePicker);
        document.getElementById('atkVsSuggestManual').addEventListener('click', function () {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            const target = context === 'catalog'
                ? document.getElementById('q')
                : document.getElementById('chatInput') || document.getElementById('aiInput');
            if (target) {
                target.focus();
            }
        });

        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0];
            if (!file) {
                return;
            }
            if (file.type && ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
                fileInput.value = '';
                setStatus('Format file harus gambar JPG, PNG, atau WebP.', true);
                return;
            }
            if (file.size > cfg.maxUploadKb * 1024) {
                fileInput.value = '';
                setStatus('Ukuran gambar maksimal ' + cfg.maxUploadKb + ' KB. Kecilkan gambar lalu coba lagi.', true);
                return;
            }
            capturedFromUpload = true;
            showPhotoPreview(file);
        });

        /* ---- Tutup modal = hentikan kamera & bersihkan ---- */
        modalEl.addEventListener('hidden.bs.modal', function () {
            resetAll();
        });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopCamera();
            }
        });
        window.addEventListener('pagehide', stopCamera);

        /* ---- Tambah ke keranjang via AJAX (hanya pelanggan login) ---- */
        document.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-atk-add-cart]');
            if (!button) {
                return;
            }
            event.preventDefault();
            const original = 'Tambah ke Keranjang';
            button.disabled = true;
            try {
                const response = await fetch(cfg.cartEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': cfg.csrf
                    },
                    body: 'product_id=' + encodeURIComponent(button.getAttribute('data-atk-add-cart')) + '&quantity=1'
                });
                const data = await response.json().catch(function () { return {}; });
                if (response.ok && data.success) {
                    button.textContent = '✓ Ditambahkan';
                    setTimeout(function () { button.textContent = original; button.disabled = false; }, 2500);
                } else {
                    button.textContent = (data.message || 'Gagal menambahkan').substring(0, 60);
                    setTimeout(function () { button.textContent = original; button.disabled = false; }, 4000);
                }
            } catch (error) {
                button.textContent = 'Gagal — coba lagi';
                setTimeout(function () { button.textContent = original; button.disabled = false; }, 3000);
            }
        });
    })();
</script>
