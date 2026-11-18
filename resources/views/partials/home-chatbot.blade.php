{{--
    Chatbot Widget Mengambang — memakai AI Assistant Agung ATK (tersedia di semua menu).
    Endpoint route('home.chat') dengan throttle; riwayat pesan
    dirender server-side ($homeChatMessages via View Composer) lalu diteruskan via fetch.
--}}
<div class="home-chatbot">
    <button type="button" id="chatFab" class="chatbot-fab" aria-expanded="false" aria-controls="chatPanel"
        aria-label="Buka chatbot Asisten Agung ATK">
        <i class="bi bi-robot" aria-hidden="true"></i>
    </button>

    <div id="chatPanel" class="chatbot-panel" role="dialog" aria-label="Chatbot Asisten Agung ATK" hidden>
        <div class="chatbot-header">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-robot" aria-hidden="true"></i>
                <div class="lh-sm">
                    <div class="fw-semibold">Asisten Agung ATK</div>
                    <div class="small opacity-75">Tanya produk ATK atau pertanyaan apa saja</div>
                </div>
            </div>
            <button type="button" id="chatClose" class="chatbot-close" aria-label="Tutup chatbot">&times;</button>
        </div>

        <div id="chatMessages" class="chatbot-messages" aria-live="polite">
            @forelse($homeChatMessages ?? collect() as $msg)
                <div class="chatbot-bubble {{ $msg->role === 'user' ? 'chatbot-bubble-user' : 'chatbot-bubble-bot' }}">{{ $msg->content }}</div>
            @empty
                <div class="chatbot-bubble chatbot-bubble-bot">Halo! Saya Asisten Agung ATK. Tanya stok &amp; harga produk, minta rekomendasi ATK, atau tanya pertanyaan umum apa saja — saya bantu.</div>
            @endforelse
        </div>

        <form id="chatForm" action="{{ route('home.chat') }}" method="POST" class="chatbot-input-row">
            @csrf
            <label for="chatInput" class="visually-hidden">Tulis pertanyaan Anda</label>
            <input type="text" id="chatInput" name="message" class="form-control chatbot-input"
                placeholder="Tulis pertanyaan..." maxlength="2000" autocomplete="off" required>
            <button type="button" id="chatVisualBtn" class="btn chatbot-send" data-no-loading
                title="Cari produk dari foto (Visual Search ATK)"
                aria-label="Cari produk dari foto barang ATK">
                <i class="bi bi-camera" aria-hidden="true"></i>
            </button>
            <button type="submit" id="chatSend" class="btn chatbot-send" data-no-loading aria-label="Kirim pesan">
                <i class="bi bi-send-fill" aria-hidden="true"></i>
            </button>
        </form>
    </div>
</div>

<style>
    .chatbot-fab {
        position: fixed;
        right: 24px;
        bottom: 24px;
        z-index: 1040;
        width: 58px;
        height: 58px;
        border: none;
        border-radius: 50%;
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: #fff;
        font-size: 1.5rem;
        box-shadow: 0 8px 24px rgba(30, 58, 138, .35);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease;
    }

    .chatbot-fab:hover,
    .chatbot-fab:focus-visible {
        transform: translateY(-2px) scale(1.04);
        box-shadow: 0 10px 28px rgba(30, 58, 138, .45);
    }

    .chatbot-panel {
        position: fixed;
        right: 24px;
        bottom: 96px;
        z-index: 1040;
        width: min(380px, calc(100vw - 32px));
        max-height: min(560px, calc(100vh - 130px));
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 16px 48px rgba(15, 23, 42, .22);
        overflow: hidden;
    }

    .chatbot-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .5rem;
        padding: .85rem 1rem;
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: #fff;
        font-size: .95rem;
    }

    .chatbot-header i {
        font-size: 1.35rem;
    }

    .chatbot-close {
        border: none;
        background: transparent;
        color: #fff;
        font-size: 1.5rem;
        line-height: 1;
        padding: 0 .25rem;
        cursor: pointer;
        opacity: .85;
    }

    .chatbot-close:hover,
    .chatbot-close:focus-visible {
        opacity: 1;
    }

    .chatbot-messages {
        flex: 1 1 auto;
        overflow-y: auto;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: .5rem;
        background: #f8fafc;
        min-height: 140px;
    }

    .chatbot-bubble {
        max-width: 85%;
        padding: .55rem .85rem;
        border-radius: 14px;
        font-size: .9rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    .chatbot-bubble-bot {
        align-self: flex-start;
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #1e293b;
        border-bottom-left-radius: 4px;
    }

    .chatbot-bubble-user {
        align-self: flex-end;
        background: #1e3a8a;
        color: #fff;
        border-bottom-right-radius: 4px;
    }

    .chatbot-bubble-error {
        align-self: center;
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        font-size: .82rem;
        max-width: 95%;
    }

    .chatbot-bubble-head {
        font-weight: 700;
        letter-spacing: .02em;
    }

    .chatbot-typing {
        align-self: flex-start;
        display: inline-flex;
        gap: .3rem;
        padding: .65rem .9rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        border-bottom-left-radius: 4px;
    }

    .chatbot-typing span {
        width: .45rem;
        height: .45rem;
        border-radius: 50%;
        background: #94a3b8;
        animation: chatbot-blink 1.2s infinite ease-in-out;
    }

    .chatbot-typing span:nth-child(2) { animation-delay: .2s; }
    .chatbot-typing span:nth-child(3) { animation-delay: .4s; }

    @keyframes chatbot-blink {
        0%, 80%, 100% { opacity: .25; transform: translateY(0); }
        40% { opacity: 1; transform: translateY(-2px); }
    }

    .chatbot-input-row {
        display: flex;
        gap: .5rem;
        padding: .75rem;
        border-top: 1px solid #e2e8f0;
        background: #fff;
        margin: 0;
    }

    .chatbot-input {
        border-radius: 10px;
        font-size: .9rem;
    }

    .chatbot-send {
        flex: 0 0 auto;
        width: 42px;
        border-radius: 10px;
        background: #1e3a8a;
        color: #fff;
    }

    .chatbot-send:hover,
    .chatbot-send:focus-visible {
        background: #16307a;
        color: #fff;
    }

    .chatbot-send:disabled {
        opacity: .6;
    }

    @media (max-width: 575.98px) {
        .chatbot-fab {
            right: 16px;
            bottom: 16px;
            width: 52px;
            height: 52px;
            font-size: 1.3rem;
        }

        .chatbot-panel {
            right: 16px;
            bottom: 80px;
            width: calc(100vw - 32px);
            max-height: calc(100vh - 110px);
        }
    }
</style>

<script>
    (function () {
        const fab = document.getElementById('chatFab');
        const panel = document.getElementById('chatPanel');
        const closeBtn = document.getElementById('chatClose');
        const form = document.getElementById('chatForm');
        const input = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSend');
        const messages = document.getElementById('chatMessages');

        if (!fab || !panel || !form || !input || !sendBtn || !messages) {
            return;
        }

        let pending = false;

        // Visual Search: kamera kirim foto barang ATK → backend menganalisis →
        // balasan chatbot + kartu produk nyata dari katalog (bukan barcode).
        const visualBtn = document.getElementById('chatVisualBtn');
        if (visualBtn && window.AtkVisualSearch) {
            visualBtn.addEventListener('click', function () {
                AtkVisualSearch.open('chatbot', function (data, photoUrl) {
                    // Bubble pengguna dengan pratinjau foto kecil.
                    const userBubble = document.createElement('div');
                    userBubble.className = 'chatbot-bubble chatbot-bubble-user';
                    userBubble.style.whiteSpace = 'normal';
                    if (photoUrl) {
                        const photo = document.createElement('img');
                        photo.src = photoUrl;
                        photo.alt = 'Foto barang ATK yang dicari';
                        photo.className = 'rounded d-block mb-1';
                        photo.style.maxHeight = '120px';
                        photo.style.objectFit = 'contain';
                        userBubble.appendChild(photo);
                    }
                    userBubble.appendChild(document.createTextNode('Cari produk dari foto (Visual Search)'));
                    messages.appendChild(userBubble);

                    // Bubble asisten: penjelasan hasil + kartu produk dari database.
                    const botBubble = document.createElement('div');
                    botBubble.className = 'chatbot-bubble chatbot-bubble-bot';
                    botBubble.style.whiteSpace = 'normal';
                    const reply = document.createElement('div');
                    reply.textContent = data.message || 'Maaf, gambar belum dapat dikenali sebagai produk ATK.';
                    botBubble.appendChild(reply);

                    const products = Array.isArray(data.products) ? data.products : [];
                    if (products.length) {
                        const holder = document.createElement('div');
                        holder.className = 'd-flex flex-column gap-2 mt-2';
                        holder.style.whiteSpace = 'normal';
                        holder.innerHTML = AtkVisualSearch.renderProducts(products, 'chat');
                        botBubble.appendChild(holder);
                    }
                    messages.appendChild(botBubble);
                    scrollDown();
                });
            });
        }

        function scrollDown() {
            messages.scrollTop = messages.scrollHeight;
        }

        function addBubble(text, className) {
            const bubble = document.createElement('div');
            bubble.className = 'chatbot-bubble ' + className;
            renderLines(bubble, text);
            messages.appendChild(bubble);
            scrollDown();
            return bubble;
        }

        // Render balasan terstruktur (header kapital + baris data) sebagai
        // node teks murni — aman dari injeksi HTML, format daftar tetap rapi.
        function renderLines(container, text) {
            const lines = String(text == null ? '' : text).split('\n');
            lines.forEach(function (line, index) {
                const row = document.createElement('div');
                if (index === 0 && /^[A-Z0-9][A-Z0-9 ()&\-:.]{3,}$/.test(line.trim())) {
                    row.className = 'chatbot-bubble-head';
                }
                row.textContent = line === '' ? '\u00A0' : line;
                container.appendChild(row);
            });
        }

        function addTyping() {
            const typing = document.createElement('div');
            typing.className = 'chatbot-typing';
            typing.setAttribute('role', 'status');
            typing.setAttribute('aria-label', 'Asisten sedang mengetik');
            typing.innerHTML = '<span></span><span></span><span></span>';
            messages.appendChild(typing);
            scrollDown();
            return typing;
        }

        function extractError(bodyText, status) {
            try {
                const data = JSON.parse(bodyText);
                if (data && data.errors && data.errors.message && data.errors.message[0]) {
                    return data.errors.message[0];
                }
                if (data && data.message) {
                    return data.message;
                }
            } catch (error) {
                // Bukan JSON — pakai pesan generik di bawah.
            }

            if (status === 429) {
                return 'Terlalu banyak permintaan. Mohon tunggu sebentar lalu coba lagi.';
            }

            return 'Maaf, terjadi gangguan saat menghubungi asisten. Silakan coba lagi.';
        }

        function setOpen(open) {
            panel.hidden = !open;
            fab.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                input.focus();
                scrollDown();
            }
        }

        fab.addEventListener('click', function () {
            setOpen(panel.hidden);
        });

        closeBtn.addEventListener('click', function () {
            setOpen(false);
            fab.focus();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                fab.focus();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (pending) {
                return;
            }

            const text = input.value.trim();
            if (text === '') {
                return;
            }

            pending = true;
            sendBtn.disabled = true;

            // Ambil nilai form SEBELUM input dikosongkan, agar field message
            // ikut terbawa dalam FormData (kalau tidak, server menerima pesan kosong).
            const data = new FormData(form);

            addBubble(text, 'chatbot-bubble-user');
            input.value = '';

            const typing = addTyping();

            fetch(form.action, {
                method: 'POST',
                body: data,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.text().then(function (bodyText) {
                    typing.remove();

                    if (!response.ok) {
                        addBubble(extractError(bodyText, response.status), 'chatbot-bubble-error');
                        return;
                    }

                    let data = null;
                    try {
                        data = JSON.parse(bodyText);
                    } catch (error) {
                        data = null;
                    }

                    if (data && typeof data.reply === 'string' && data.reply !== '') {
                        addBubble(data.reply, 'chatbot-bubble-bot');
                    } else {
                        addBubble('Maaf, asisten tidak memberi balasan. Silakan coba lagi.', 'chatbot-bubble-error');
                    }
                });
            }).catch(function () {
                typing.remove();
                addBubble('Koneksi bermasalah. Periksa jaringan Anda lalu coba lagi.', 'chatbot-bubble-error');
            }).finally(function () {
                pending = false;
                sendBtn.disabled = false;
                input.focus();
            });
        });
    })();
</script>
