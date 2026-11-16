# SEQUENCE DIAGRAM — AGUNG ATK

---

## 1. Autentikasi & Deteksi Role

```mermaid
sequenceDiagram
    actor U as Pengguna
    participant FE as Frontend (Blade)
    participant R as Router/Middleware
    participant C as AuthController
    participant DB as MySQL

    U->>FE: isi form /login (email, sandi)
    FE->>R: POST /login
    R->>R: throttle:login (5/menit) + CSRF
    R->>C: login(Request)
    C->>DB: SELECT users WHERE email = ?
    alt kredensial salah / akun nonaktif
        C-->>FE: redirect + error (percobaan tercatat)
    else benar & aktif
        C->>C: Auth::login + session regenerate
        C->>DB: INSERT sessions
        C-->>FE: redirect redirectBasedOnRole()
        FE-->>U: dashboard sesuai role
    end
```

---

## 2. Checkout (Transaksi Konsisten)

```mermaid
sequenceDiagram
    actor U as Pelanggan
    participant FE as Frontend
    participant MW as CustomerMiddleware
    participant CO as CheckoutController
    participant DB as MySQL
    participant NM as NotificationManager
    participant Q as Queue Worker

    U->>FE: klik "Buat Pesanan"
    FE->>MW: POST /checkout
    MW->>MW: cek auth + is_active
    MW->>CO: store(Request)
    CO->>CO: validasi server-side (items, alamat)
    CO->>DB: BEGIN TRANSACTION
    CO->>DB: INSERT orders (pending)
    CO->>DB: INSERT order_details (snapshot harga)
    CO->>DB: UPDATE products.stock -= qty
    alt stok tidak cukup
        CO->>DB: ROLLBACK
        CO-->>FE: error "stok tidak cukup"
    else sukses
        CO->>DB: COMMIT
        CO->>NM: dispatch('order.created', data)
        NM->>DB: INSERT notification_logs (pending)
        NM->>Q: push job (queue database)
        CO-->>FE: redirect /orders + sukses
        Q->>Q: kirim Telegram/Email (async)
    end
```

---

## 3. Chatbot AI — Alur Lengkap (autentikasi → permission → data → AI → balasan)

```mermaid
sequenceDiagram
    actor U as Pengguna (role apapun / tamu)
    participant W as Widget Chat (Frontend)
    participant R as Route POST /home/chat
    participant G as ChatGuard (input)
    participant CMD as commandReply (lokal)
    participant DS as ChatbotDataService
    participant DB as MySQL (read-only)
    participant AI as AIService
    participant LL as Provider LLM (API eksternal)
    participant GO as ChatGuard (output)

    U->>W: kirim pesan
    W->>R: POST /home/chat (throttle 20/menit, CSRF)
    R->>AI: buildReply(message, user dari session)
    Note over R: user & role NYATA dari session server<br/>(bukan klaim dalam teks)
    AI->>G: passesInput(message)
    alt pola privasi/rahasia/injeksi
        G-->>U: respons tetap penolakan (LLM tidak dipanggil)
    else bersih
        AI->>AI: deteksi identitas ("siapa kamu?"/"siapa saya?")<br/>→ ChatbotProfile (role dari session)
        AI->>CMD: perintah slash?
        alt perintah (/menu, /stok, /pesanan, /lacak)
            CMD->>DB: query baca terbatas (bila perlu)
            CMD-->>U: balasan lokal deterministik (bahasa perintah)
        else bukan perintah
            AI->>DS: reply(lower, user)
            DS->>DS: detect(intent)
            alt intent data dikenali
                DS->>DB: SELECT ... scope per role + LIMIT
                DB-->>DS: hasil
                DS-->>AI: jawaban angka nyata / penolakan akses
                AI-->>U: balasan (tanpa LLM)
            else bukan intent data
                AI->>LL: POST /chat/completions<br/>(system prompt: identitas, bahasa, aturan peran<br/>+ konteks JSON produk/skema/role)
                alt API key valid
                    LL-->>AI: jawaban model
                    AI->>GO: sanitizeOutput(jawaban)
                    GO-->>U: balasan tersaring (selesai)
                else API tidak tersedia (401/timeout)
                    AI-->>U: fallback jujur lokal ("belum memiliki informasi cukup")
                end
            end
        end
    end
    AI->>DB: INSERT ai_conversations / ai_messages
```

**Titik kritis yang terlihat pada diagram:**
1. **Autentikasi** — `user` & role diambil dari session server.
2. **Permission** — scope query ditentukan role di `ChatbotDataService` (bukan dari teks pengguna).
3. **Data** — hanya intent terbatas, read-only, ber-LIMIT.
4. **AI processing** — system prompt + konteks; API eksternal diganti aman bila gagal.
5. **Balasan** — melewati pagaran output sebelum tampil.

---

## 4. Pencarian Produk via Gambar (YOLOv8)

```mermaid
sequenceDiagram
    actor U as Pengguna
    participant FE as Form Unggah (Frontend)
    participant PC as Product/ImageSearch Controller
    participant AR as ATKImageRecognitionService
    participant YS as YoloDetectionService
    participant PY as ai-service/server.py (YOLOv8 :8090)
    participant DB as MySQL

    U->>FE: foto kamera/unggah (≤ 2 MB)
    FE->>PC: POST unggah gambar
    PC->>PC: validasi tipe & ukuran file
    PC->>AR: recognize(path)
    AR->>YS: detect(path)
    YS->>PY: POST /detect (timeout 10s, connect 2s)
    alt layanan YOLO hidup
        PY-->>YS: [{class, confidence, ...}]
        YS-->>AR: hasil deteksi
    else layanan mati/timeout
        PY--xYS: timeout
        AR->>AR: fallback pengenalan bawaan
    end
    AR->>AR: banding confidence & margin dengan ambang
    AR->>DB: cari produk katalog cocok dengan kelas
    alt kelas bukan ATK
        AR-->>U: "Barang Tidak Sesuai Kriteria Toko"
    else cocok & stok 0
        AR-->>U: "Stok Kosong"
    else cocok & stok ada
        AR-->>U: daftar saran produk (nama, harga, stok)
    else tidak yakin
        AR-->>U: hasil uncertain (jujur, tanpa mengarang)
    end
```

---

## 5. Notifikasi Multi-Kanal (event → queue → kirim)

```mermaid
sequenceDiagram
    participant BIZ as Fitur Bisnis (mis. AdminPaymentController)
    participant NM as NotificationManager
    participant CFG as config/notifications.php
    participant DB as MySQL
    participant Q as Queue Worker
    participant TG as Telegram Bot API
    participant SM as SMTP (Mailpit)
    participant WA as WhatsApp Cloud API

    BIZ->>NM: dispatch('payment.verified', data)
    NM->>CFG: audience, event, jendela dedupe
    NM->>DB: SELECT penerima per role (aktif)
    loop per penerima & per kanal
        NM->>NM: channelStatus() & channelEnabled()
        alt kanal tidak dikonfigurasi
            NM->>NM: lewati dengan aman
        else kanal aktif & belum dikirim (dedupe)
            NM->>DB: INSERT notification_logs (pending)
            NM->>Q: queue job (NOTIFICATIONS_QUEUE)
            Q->>TG: sendMessage (bila TELEGRAM_ENABLED)
            Q->>SM: Mail::send (email)
            Q->>WA: Graph API (bila WHATSAPP_ENABLED & kredensial valid)
            Q->>DB: UPDATE status sent/failed (+ retry 3x)
        end
    end
```

---

## 6. Webhook Telegram Masuk (balasan chatbot via kanal)

```mermaid
sequenceDiagram
    participant TG as Telegram Servers
    participant R as POST /webhooks/telegram
    participant V as Validasi Secret
    participant IC as InboundChatService
    participant CI as ChannelIdentityService
    participant AI as AIService
    participant J as SendChannelReplyJob
    participant Q as Queue Worker
    participant TGS as Telegram Bot API

    TG->>R: update (pesan masuk)
    R->>R: throttle 60/menit + CSRF skip
    R->>V: cocokkan header X-Telegram-Bot-Api-Secret-Token
    alt secret tidak cocok
        V-->>TG: 403 (fail-closed)
    else cocok
        R->>R: dedupe update_id (cache 24 jam)
        R->>IC: handle('telegram', chatId, text)
        IC->>CI: identitas: verified? → akun user aktif, else tamu
        IC->>AI: buildReply(text, user?)
        AI-->>IC: balasan
        IC-->>Q: dispatch SendChannelReplyJob (tries 3, backoff)
        Q->>TGS: sendMessage(chatId, balasan)
        Q-->>R: webhook tetap 200 (job asinkron)
    end
```
