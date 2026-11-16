# CLASS DIAGRAM — AGUNG ATK

Disusun dari **struktur kode aktual** (bukan rancangan fiktif). Kelas yang tidak ada di implementasi tidak digambar.

---

## 1. Diagram Kelas Inti (Mermaid)

```mermaid
classDiagram
    class User {
        -int id
        -int role_id
        -string name
        -string email
        -string phone
        -bool is_active
        -string password
        +role() Role
        +orders() hasMany Order
        +addresses() hasMany Address
        +reviews() hasMany Review
        +channelIdentities() hasMany ChannelIdentity
        +courierShipments() hasMany Shipment
        +isAdmin() bool
        +isAnggota() bool
        +isCourier() bool
        +isCustomer() bool
    }
    class Role {
        -int id
        -string name
        -string label
    }
    class Category {
        -int id
        -string name
        -string slug
        +products() hasMany Product
    }
    class Product {
        -int id
        -int category_id
        -string name
        -string sku
        -string barcode
        -decimal price
        -int stock
        -int min_stock
        -bool is_active
        +category() belongsTo Category
        +productImages() hasMany ProductImage
        +orderDetails() hasMany OrderDetail
        +reviews() hasMany Review
        +scopeActive()
        +scopeLowStock()
    }
    class ProductImage {
        -int id
        -int product_id
        -string image_path
        -bool is_primary
    }
    class Order {
        -int id
        -string order_number
        -int user_id
        -int address_id
        -decimal grand_total
        -string status
        +user() belongsTo User
        +address() belongsTo Address
        +orderDetails() hasMany OrderDetail
        +payment() hasOne Payment
        +shipment() hasOne Shipment
    }
    class OrderDetail {
        -int id
        -int order_id
        -int product_id
        -string product_name
        -decimal price
        -int quantity
        -decimal subtotal
    }
    class Payment {
        -int id
        -int order_id
        -string payment_number
        -string method
        -decimal amount
        -string status
        -int verified_by
        +order() belongsTo Order
        +verifier() belongsTo User
    }
    class Shipment {
        -int id
        -int order_id
        -int courier_id
        -string tracking_number
        -string courier_name
        -string status
        +order() belongsTo Order
        +courier() belongsTo User
    }
    class Review {
        -int id
        -int order_id
        -int product_id
        -int user_id
        -int rating
        -bool is_approved
    }
    class Address {
        -int id
        -int user_id
        -string recipient_name
        -string phone
        -string full_address
        -bool is_primary
    }
    class ActivityLog {
        -int id
        -int user_id
        -string activity_type
        -string subject_type
        -int subject_id
        -string summary
    }
    class ChannelIdentity {
        -int id
        -int user_id
        -string channel
        -string identifier
        -string status
        -string verification_code
        -datetime code_expires_at
        -datetime verified_at
        +user() belongsTo User
    }
    class NotificationLog {
        -int id
        -string event_key
        -string channel
        -string recipient
        -string status
        -datetime sent_at
    }
    class NotificationSetting {
        -int id
        -string group
        -string key
        -bool enabled
    }
    class AiConversation {
        -int id
        -int user_id
        -string session_id
        -string title
        +messages() hasMany AiMessage
    }
    class AiMessage {
        -int id
        -int ai_conversation_id
        -string role
        -string content
    }

    User "1" --> "1" Role : role_id
    User "1" --> "*" Address : user_id
    User "1" --> "*" Order : user_id
    User "1" --> "*" Review : user_id
    User "1" --> "*" ChannelIdentity : user_id
    User "1" --> "*" Shipment : courier_id
    User "1" --> "*" ActivityLog : user_id
    User "1" --> "*" AiConversation : user_id
    User "1" --> "*" Payment : verified_by
    Category "1" --> "*" Product : category_id
    Product "1" --> "*" ProductImage : product_id
    Product "1" --> "*" OrderDetail : product_id
    Product "1" --> "*" Review : product_id
    Order "1" --> "*" OrderDetail : order_id
    Order "1" --> "1" Payment : order_id
    Order "1" --> "1" Shipment : order_id
    Order "1" --> "*" Review : order_id
    Order "0..1" --> "1" Address : address_id
    AiConversation "1" --> "*" AiMessage : ai_conversation_id
```

---

## 2. Lapisan Service & Support (kode aktual)

```mermaid
classDiagram
    class NotificationManager {
        +dispatch(string eventKey, array data)
        +sendTest(string channel, ?string message, ?string recipient) array
        +channelStatus(string channel) array
        +recipients(string eventKey, array data) array
        -channelEnabled(string channel) bool
        -missingWhatsApp() array
        -missingEmail() array
    }
    class TelegramNotificationService {
        +send(string chatId, string text) bool
        +getMe() array
        +setWebhook(string url) array
        +deleteWebhook() array
        +webhookInfo() array
    }
    class WhatsAppNotificationService {
        +sendText(string to, string text) bool
        +sendTemplate(string to) bool
        +verifySignature(string payload, string header) bool
    }
    class ChannelIdentityService {
        +requestCode(User user, string channel) string
        +claim(User user, string channel, string identifier, string code) bool
        +resolve(string channel, string identifier) ?ChannelIdentity
        -isLocked(string channel, string identifier) bool
    }
    class InboundChatService {
        +handle(string channel, string identifier, string text) string
    }
    class SendChannelReplyJob {
        -string channel
        -string identifier
        -string reply
        +handle() void
        #backoff() array
    }
    class AIService {
        +buildReply(string message, ?User user) string
        -commandReply(string, Collection, ?User) ?string
        -localReply(string, ?User, Collection) string
        -askApi(array messages) ?string
        -catalog() Collection
        -multilingualGreeting(string) ?string
    }
    class ChatGuard {
        +passesInput(string message) bool
        +sanitizeOutput(string reply) string
        -detectLeak(string) bool
    }
    class ChatbotDataService {
        +reply(string lower, ?User user) ?string
        -detect(string) ?string
        -orderLookupReply(string, ?User) string
        -trackingLookupReply(string, ?User) string
        -lowStockReply() string
    }
    class DatabaseKnowledgeService {
        +schemaForPrompt() string
        +tables() array
    }
    class ChatbotProfile {
        +isWhoAmIQuestion(string) bool
        +whoAmIReply(bool loggedIn, ?string role) string
        +isIdentityQuestion(string) bool
    }
    class ATKImageRecognitionService {
        +recognize(string path) array
        -viaYolo(string path) ?array
        -fallback(string path) array
    }
    class YoloDetectionService {
        +detect(string path) array
    }

    NotificationManager --> TelegramNotificationService : kanal
    NotificationManager --> WhatsAppNotificationService : kanal
    NotificationManager --> NotificationLog : mencatat
    InboundChatService --> ChannelIdentityService : identitas
    InboundChatService --> AIService : membalas
    SendChannelReplyJob --> TelegramNotificationService : kirim
    SendChannelReplyJob --> WhatsAppNotificationService : kirim
    AIService --> ChatGuard : pagaran input/output
    AIService --> ChatbotDataService : intent data
    AIService --> DatabaseKnowledgeService : konteks skema
    AIService --> ChatbotProfile : identitas
    ATKImageRecognitionService --> YoloDetectionService : hybrid
```

---

## 3. Contoh Controller (tipikal)

```mermaid
classDiagram
    class CatalogController {
        +index(Request) view
        +show(Product) view
        +scan(string code) redirect
    }
    class CheckoutController {
        +index() view
        +store(Request) redirect
    }
    class AdminPaymentController {
        +index() view
        +show(Payment) view
        +verify(Payment, Request) redirect
    }
    class WebhookController {
        +whatsappVerify(Request) response
        +whatsappWebhook(Request) response
        +telegramWebhook(Request) response
    }
    class AIController {
        +chat(Request) json
        +reset(Request) json
    }
    CheckoutController --> Order : membuat (transaksi)
    CheckoutController --> OrderDetail : membuat
    AdminPaymentController --> Payment : verifikasi
    WebhookController --> InboundChatService : pesan masuk
    AIController --> AIService : membalas
```

---

## 4. Catatan Penerapan SOLID (jujur)

| Prinsip | Penerapan | Catatan |
|---|---|---|
| **S**ingle Responsibility | `NotificationManager` (orkestrasi) terpisah dari `Telegram/WhatsApp*Service` (transport); `ChatGuard` (keamanan) terpisah dari `AIService` (logika jawaban); `ChannelIdentityService` (tautan) terpisah dari `InboundChatService` (percakapan) | ✅ Kuat |
| **O**pen/Closed | Menambah kanal notifikasi = menambah service + baris config (tidak mengubah inti dispatch); menambah intent chatbot = menambah pola di `ChatbotDataService` | ✅ Cukup kuat |
| **L**iskov Substitution | Kanal notifikasi mengikuti kontrak kirim & status seragam (`channelStatus`) | ✅ |
| **I**nterface Segregation | **Tidak ada interface kustom** — kontrak disepakati lewat pola kode & docblock | ⚠️ Jujur: belum ada `interface`; kandidat bila pengembangan: `NotifiableChannel`, `ChatHandler` |
| **D**ependency Inversion | Controller bergantung pada service via method aplikasi; `AIService` bergantung pada collaborator (guard, data, profile) yang dapat ditukar | ⚠️ Sebagian — beberapa keputusan di-hardcode pada `AIService::buildReply` |

**Kesimpulan:** penerapan SOLID **baik** pada lapisan service; perbaikan lanjutan yang wajar (tanpa refactor besar) adalah memperkenalkan interface pada batas kanal bila roadmap pengembangan membutuhkannya.
