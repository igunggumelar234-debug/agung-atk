@extends('layouts.dashboard')

@section('title', 'AI Assistant')
@section('page-title', 'AI Assistant')

@section('content')
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="p-2 rounded-3 bg-primary-subtle text-primary"><i class="bi bi-robot fs-5"></i></span>
            <div>
                <h6 class="mb-0 fw-bold text-dark">Asisten AI Agung ATK</h6>
                <div class="small text-muted">
                    Menjawab berdasarkan data produk toko
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Database Aktual</span>
                </div>
            </div>
        </div>
        <form action="{{ route('ai.reset') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm fw-semibold">
                <i class="bi bi-plus-lg me-1"></i>Percakapan Baru
            </button>
        </form>
    </div>

    <div id="chatBox" class="card-body p-3" style="height: 55vh; min-height: 320px; overflow-y: auto; background: #f8fafc;">
        @if($conversation && $conversation->messages->isNotEmpty())
            @foreach($conversation->messages as $msg)
                <div class="d-flex {{ $msg->role === 'user' ? 'justify-content-end' : 'justify-content-start' }} mb-3">
                    <div class="msg-bubble {{ $msg->role === 'user' ? 'bg-primary text-white' : 'bg-white border' }} rounded-3 px-3 py-2 shadow-sm"
                         style="max-width: 85%;">
                        {{ $msg->content }}
                    </div>
                </div>
            @endforeach
        @else
            <div id="welcomeBox" class="text-center pt-4 px-2">
                <i class="bi bi-stars fs-1 text-primary"></i>
                <h6 class="fw-bold text-dark mt-2">Halo! Aku Asisten AI Agung ATK</h6>
                <p class="small text-muted mb-3">
                    Aku bantu cari, bandingkan, dan rekomendasikan produk dari katalog toko
                    sesuai kebutuhan &amp; budget-mu.
                </p>
                <div class="d-flex flex-wrap gap-2 justify-content-center" id="suggestions">
                    <button type="button" class="btn btn-outline-primary btn-sm suggestion">Saya mahasiswa, budget Rp50.000, apa yang cocok?</button>
                    <button type="button" class="btn btn-outline-primary btn-sm suggestion">Cari pulpen untuk menulis</button>
                    <button type="button" class="btn btn-outline-primary btn-sm suggestion">Rekomendasi untuk mengarsip dokumen</button>
                    <button type="button" class="btn btn-outline-primary btn-sm suggestion">Produk apa saja yang tersedia?</button>
                    <button type="button" class="btn btn-outline-primary btn-sm suggestion">Berapa jumlah seluruh produk?</button>
                </div>
            </div>
        @endif
    </div>

    <div class="card-footer bg-white py-3">
        <form id="aiForm" action="{{ route('ai.message') }}" method="POST" class="d-flex gap-2">
            @csrf
            <input type="text" name="message" id="aiInput" class="form-control"
                   aria-label="Pesan untuk asisten AI"
                   placeholder="Tulis pertanyaanmu... (mis. Saya mahasiswa, budget Rp50.000)"
                   maxlength="2000" autocomplete="off" required>
            <button type="button" id="aiVisualBtn" class="btn btn-outline-secondary fw-semibold px-3"
                    title="Cari produk dari foto (Visual Search ATK)"
                    aria-label="Cari produk dari foto barang ATK">
                <i class="bi bi-camera" aria-hidden="true"></i>
            </button>
            <button type="submit" id="aiSend" class="btn btn-primary-atk fw-semibold px-3" data-no-loading>
                <i class="bi bi-send"></i><span class="d-none d-sm-inline ms-1">Kirim</span>
            </button>
        </form>
        <div class="small text-muted mt-2">
            <i class="bi bi-info-circle me-1"></i>
            Jawaban menggunakan data produk aktual toko — nama, harga, stok, dan kategori tidak akan dikarang.
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    .msg-bubble {
        white-space: pre-line;
        line-height: 1.5;
        word-break: break-word;
    }

    .msg-head {
        font-weight: 700;
        letter-spacing: .02em;
    }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('aiForm');
        const input = document.getElementById('aiInput');
        const box = document.getElementById('chatBox');
        const sendBtn = document.getElementById('aiSend');
        let typing = null;

        // Visual Search: kamera kirim foto barang ATK → backend menganalisis →
        // balasan asisten + kartu produk nyata dari katalog (bukan barcode).
        const visualBtn = document.getElementById('aiVisualBtn');
        if (visualBtn && window.AtkVisualSearch) {
            visualBtn.addEventListener('click', function () {
                AtkVisualSearch.open('chatbot', function (data, photoUrl) {
                    const welcome = document.getElementById('welcomeBox');
                    if (welcome) {
                        welcome.remove();
                    }

                    // Bubble pengguna dengan pratinjau foto kecil.
                    const userWrap = document.createElement('div');
                    userWrap.className = 'd-flex mb-3 justify-content-end';
                    const userBubble = document.createElement('div');
                    userBubble.className = 'msg-bubble rounded-3 px-3 py-2 shadow-sm bg-primary text-white';
                    userBubble.style.maxWidth = '85%';
                    userBubble.style.whiteSpace = 'normal';
                    if (photoUrl) {
                        const photo = document.createElement('img');
                        photo.src = photoUrl;
                        photo.alt = 'Foto barang ATK yang dicari';
                        photo.className = 'rounded d-block mb-1';
                        photo.style.maxHeight = '140px';
                        photo.style.objectFit = 'contain';
                        userBubble.appendChild(photo);
                    }
                    userBubble.appendChild(document.createTextNode('Cari produk dari foto (Visual Search)'));
                    userWrap.appendChild(userBubble);
                    box.appendChild(userWrap);

                    // Balasan asisten: teks hasil + kartu produk dari database.
                    addBubble('assistant', data.message || 'Maaf, gambar belum dapat dikenali sebagai produk ATK.');

                    const products = Array.isArray(data.products) ? data.products : [];
                    if (products.length) {
                        const wrap = document.createElement('div');
                        wrap.className = 'd-flex mb-3 justify-content-start';
                        const holder = document.createElement('div');
                        holder.className = 'd-flex flex-column gap-2';
                        holder.style.maxWidth = '85%';
                        holder.style.whiteSpace = 'normal';
                        holder.innerHTML = AtkVisualSearch.renderProducts(products, 'chat');
                        wrap.appendChild(holder);
                        box.appendChild(wrap);
                        scrollToBottom();
                    }
                });
            });
        }

        function scrollToBottom() {
            box.scrollTop = box.scrollHeight;
        }

        function addBubble(role, text) {
            const wrap = document.createElement('div');
            wrap.className = 'd-flex mb-3 justify-content-' + (role === 'user' ? 'end' : 'start');

            const bubble = document.createElement('div');
            bubble.className = 'msg-bubble rounded-3 px-3 py-2 shadow-sm '
                + (role === 'user' ? 'bg-primary text-white' : 'bg-white border');
            bubble.style.maxWidth = '85%';
            renderLines(bubble, text, role !== 'user');

            wrap.appendChild(bubble);
            box.appendChild(wrap);
            scrollToBottom();

            return wrap;
        }

        // Render balasan terstruktur (header kapital + baris data) sebagai
        // node teks murni — aman dari injeksi HTML, format daftar tetap rapi.
        function renderLines(container, text, allowHeader) {
            const lines = String(text == null ? '' : text).split('\n');
            lines.forEach(function (line, index) {
                const row = document.createElement('div');
                if (allowHeader && index === 0 && /^[A-Z0-9][A-Z0-9 ()&\-:.]{3,}$/.test(line.trim())) {
                    row.className = 'msg-head';
                }
                row.textContent = line === '' ? '\u00A0' : line;
                container.appendChild(row);
            });
        }

        function showTyping() {
            typing = addBubble('assistant', 'Mengetik...');
            typing.querySelector('.msg-bubble').classList.add('text-muted', 'fst-italic');
        }

        function hideTyping() {
            if (typing) {
                typing.remove();
                typing = null;
            }
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const message = input.value.trim();
            if (!message) {
                return;
            }

            const welcome = document.getElementById('welcomeBox');
            if (welcome) {
                welcome.remove();
            }

            addBubble('user', message);
            input.value = '';
            sendBtn.disabled = true;
            showTyping();

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: new URLSearchParams({ message: message }),
                });

                hideTyping();

                if (response.status === 422) {
                    const error = await response.json();
                    const first = error.errors && error.errors.message && error.errors.message[0];
                    addBubble('assistant', first || 'Pesan tidak valid.');
                } else if (!response.ok) {
                    addBubble('assistant', 'Maaf, terjadi gangguan saat menghubungi asisten. Coba lagi ya.');
                } else {
                    const data = await response.json();
                    addBubble('assistant', data.reply);
                }
            } catch (error) {
                hideTyping();
                addBubble('assistant', 'Maaf, terjadi kesalahan jaringan. Coba kirim ulang ya.');
            } finally {
                sendBtn.disabled = false;
                input.focus();
            }
        });

        document.querySelectorAll('.suggestion').forEach(function (button) {
            button.addEventListener('click', function () {
                input.value = button.textContent.trim();
                if (form.requestSubmit) {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            });
        });

        scrollToBottom();
    })();
</script>
@endpush
