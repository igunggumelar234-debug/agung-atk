<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ChatbotProfile;
use App\Support\ChatGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Identitas pengguna & pencarian akun pelanggan pada chatbot:
 * "siapa saya" memakai nama aktual dari database, status pendaftaran
 * diverifikasi ke akun nyata, jumlah/daftar/pencarian pelanggan diskop
 * per role, privasi antar-pengguna ditegakkan server, dan perubahan data
 * langsung terbaca (tanpa cache nama).
 */
class ChatbotUserIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const DENIED = 'Maaf, Anda tidak memiliki izin untuk melihat informasi akun tersebut.';

    private User $admin;

    private User $anggota;

    private User $kurir;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->anggota = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | HELPER
     * ===================================================================*/

    private function chat(?User $user, string $message): TestResponse
    {
        if ($user !== null) {
            return $this->actingAs($user)
                ->postJson(route('home.chat'), ['message' => $message]);
        }

        return $this->withCredentials()
            ->withCookie(config('session.cookie'), hash('sha1', 'user-identity'))
            ->postJson(route('home.chat'), ['message' => $message]);
    }

    private function replyAs(?User $user, string $message): string
    {
        $response = $this->chat($user, $message);
        $response->assertOk();

        return (string) $response->json('reply');
    }

    private function createCustomer(string $name, array $overrides = []): User
    {
        return User::create(array_merge([
            'role_id' => Role::where('name', 'pelanggan')->firstOrFail()->id,
            'name' => $name,
            'email' => 'uji-'.uniqid().'@contoh.test',
            'password' => bcrypt('password-identitas-uji'),
            'is_active' => true,
        ], $overrides));
    }

    private function createOrderFor(User $customer, string $orderNumber): Order
    {
        return Order::create([
            'order_number' => $orderNumber,
            'user_id' => $customer->id,
            'address_id' => null,
            'total_amount' => 10000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 10000,
            'status' => 'ready_to_ship',
        ]);
    }

    private function customerTotal(): int
    {
        return User::whereHas('role', function ($query): void {
            $query->where('name', 'pelanggan');
        })->count();
    }

    /* =====================================================================
     | SKENARIO 1 & 5 — "SIAPA SAYA?" MENYEBUT NAMA AKUN AKTUAL
     * ===================================================================*/

    public function test_siapa_saya_shows_actual_account_name_and_role_per_role(): void
    {
        $cases = [
            [$this->pelanggan, 'pelanggan'],
            [$this->admin, 'admin'],
            [$this->anggota, 'anggota'],
            [$this->kurir, 'kurir'],
        ];

        foreach ($cases as [$user, $role]) {
            $reply = $this->replyAs($user, 'Siapa saya?');

            $this->assertStringContainsString(
                'Nama akun Anda adalah '.$user->name,
                $reply,
                'Nama akun untuk role '.$role.' tidak muncul.'
            );
            $this->assertStringContainsString('peran '.$role, $reply);
            $this->assertStringNotContainsString('$2y$', $reply, 'Hash password tidak boleh bocor.');
        }
    }

    public function test_identity_variations_and_typo_are_understood(): void
    {
        $variants = [
            'nama akun saya siapa',
            'kamu tahu nama aku?',
            'akun ini atas nama siapa?',
            'apa nama saya?',
            'saya login menggunakan akun apa?',
            'siapa sya',
        ];

        foreach ($variants as $variant) {
            $reply = $this->replyAs($this->anggota, $variant);

            $this->assertStringContainsString('Nama akun Anda adalah', $reply, 'Variasi gagal: '.$variant);
            $this->assertStringContainsString($this->anggota->name, $reply, 'Variasi gagal: '.$variant);
        }
    }

    /* =====================================================================
     | SKENARIO 2 — "APAKAH SAYA SUDAH TERDAFTAR?" DARI DATABASE
     * ===================================================================*/

    public function test_registered_question_verified_against_database(): void
    {
        $reply = $this->replyAs($this->pelanggan, 'Apakah saya sudah terdaftar?');

        $this->assertStringContainsString('Ya, akun Anda terdaftar', $reply);
        $this->assertStringContainsString($this->pelanggan->name, $reply);
        $this->assertStringContainsString('Status akun: aktif', $reply);
    }

    public function test_inactive_account_is_blocked_from_chat_and_reported_honestly(): void
    {
        $inactive = $this->createCustomer('Akun Nonaktif Uji', ['is_active' => false]);

        // Middleware akun menolak sesi chat untuk akun nonaktif (401).
        $this->chat($inactive, 'Apakah saya sudah terdaftar?')->assertStatus(401);

        // Jalur jawaban chatbot sendiri menyatakan status nonaktif apa adanya
        // (dipakai bila akun dinonaktifkan di tengah sesi/kanal tertaut).
        $reply = (new ChatbotProfile)->registeredReply($inactive);

        $this->assertStringContainsString('Status akun: nonaktif', $reply);
    }

    public function test_registered_question_as_guest_is_honest(): void
    {
        $reply = $this->replyAs(null, 'Apakah saya sudah terdaftar?');

        $this->assertStringContainsString('Anda belum login', $reply);
    }

    /* =====================================================================
     | SKENARIO 3 — JUMLAH PELANGGAN AKTUAL & SESUAI IZIN ROLE
     * ===================================================================*/

    public function test_customer_count_is_actual_for_admin_and_anggota(): void
    {
        $this->createCustomer('Agung Gumelar');
        $this->createCustomer('Agung Prasetyo');

        $expected = $this->customerTotal();

        $asAdmin = $this->replyAs($this->admin, 'Berapa jumlah pelanggan yang terdaftar?');
        $this->assertStringContainsString($expected.' pelanggan terdaftar', $asAdmin);

        $asAnggota = $this->replyAs($this->anggota, 'berapa orang pelanggan yang ada?');
        $this->assertStringContainsString($expected.' pelanggan terdaftar', $asAnggota);

        $typo = $this->replyAs($this->admin, 'brp jml pelanggan terdaftar?');
        $this->assertStringContainsString($expected.' pelanggan terdaftar', $typo);
    }

    public function test_customer_count_denied_for_courier_and_customer(): void
    {
        $this->assertSame(self::DENIED, $this->replyAs($this->kurir, 'Berapa jumlah pelanggan yang terdaftar?'));
        $this->assertSame(self::DENIED, $this->replyAs($this->pelanggan, 'Berapa jumlah pelanggan yang terdaftar?'));
    }

    public function test_customer_count_as_guest_requires_login(): void
    {
        $reply = $this->replyAs(null, 'Berapa jumlah pelanggan yang terdaftar?');

        $this->assertStringContainsString('Anda belum login', $reply);
    }

    /* =====================================================================
     | SKENARIO 4 — PENCARIAN NAMA PELANGGAN (ADA / TIDAK ADA / GANDA)
     * ===================================================================*/

    public function test_admin_finds_customer_by_name(): void
    {
        $this->createCustomer('Agung Gumelar');

        $reply = $this->replyAs($this->admin, 'Apakah pelanggan bernama Agung Gumelar ada?');

        $this->assertStringContainsString('Ya, pelanggan dengan nama Agung Gumelar terdaftar', $reply);
        $this->assertStringContainsString('status akun aktif', $reply);
        $this->assertStringNotContainsString('@contoh.test', $reply, 'Email pelanggan tidak ikut ditampilkan.');
    }

    public function test_search_reports_honestly_when_customer_not_found(): void
    {
        $reply = $this->replyAs($this->admin, 'Apakah pelanggan bernama Zonk Kwik terdaftar?');

        $this->assertSame('Maaf, saya tidak menemukan akun pelanggan dengan nama tersebut.', $reply);
    }

    public function test_multiple_name_matches_ask_for_safe_differentiator(): void
    {
        $this->createCustomer('Agung Gumelar');
        $this->createCustomer('Agung Prasetyo');

        $reply = $this->replyAs($this->admin, 'ada pelanggan namanya agung?');

        $this->assertStringContainsString('lebih dari satu pelanggan', $reply);
        $this->assertStringContainsString('username/email atau ID akun', $reply);
        $this->assertStringNotContainsString('Agung Gumelar', $reply);
        $this->assertStringNotContainsString('Agung Prasetyo', $reply);
    }

    public function test_search_by_username_email_works(): void
    {
        $this->createCustomer('Agung Username', ['email' => 'agung.username@contoh.test']);

        $reply = $this->replyAs(
            $this->admin,
            'apakah akun pelanggan dengan username agung.username@contoh.test tersedia?'
        );

        $this->assertStringContainsString('terdaftar atas nama Agung Username', $reply);
    }

    public function test_anggota_can_search_specific_customer_name(): void
    {
        $this->createCustomer('Anggota Boleh Cari');

        $reply = $this->replyAs($this->anggota, 'cari pelanggan bernama anggota boleh cari');

        $this->assertStringContainsString('Ya, pelanggan dengan nama Anggota Boleh Cari terdaftar', $reply);
    }

    public function test_search_without_keyword_asks_for_clarification(): void
    {
        $reply = $this->replyAs($this->admin, 'pelanggan yang sudah terdaftar');

        $this->assertStringContainsString('Sebutkan nama atau username pelanggan', $reply);
    }

    /* =====================================================================
     | SKENARIO 5 — PRIVASI: PELANGGAN & KURIR TIDAK BUKA DATA AKUN LAIN
     * ===================================================================*/

    public function test_customer_asking_for_other_customers_gets_privacy_refusal(): void
    {
        $reply = $this->replyAs($this->pelanggan, 'Siapa pelanggan lain yang terdaftar?');

        $this->assertSame(ChatGuard::PRIVACY_REFUSAL, $reply);
    }

    public function test_customer_list_search_denied_for_customer_and_courier(): void
    {
        $this->createCustomer('Akun Terlarang Uji');

        $this->assertSame(
            self::DENIED,
            $this->replyAs($this->pelanggan, 'Sebutkan pelanggan yang terdaftar')
        );
        $this->assertSame(
            self::DENIED,
            $this->replyAs($this->pelanggan, 'ada pelanggan bernama akun terlarang uji?')
        );
        $this->assertSame(
            self::DENIED,
            $this->replyAs($this->kurir, 'ada pelanggan bernama akun terlarang uji?')
        );
    }

    /* =====================================================================
     | SKENARIO 6 — KURIR: NAMA PELANGGAN HANYA DARI PENUGASANNYA
     * ===================================================================*/

    public function test_courier_gets_customer_name_only_for_assigned_shipment(): void
    {
        $pesananSaya = $this->createOrderFor($this->pelanggan, 'ORD-NAMA-KURIR');
        Shipment::create([
            'order_id' => $pesananSaya->id,
            'courier_id' => $this->kurir->id,
            'tracking_number' => 'TRK-NAMA-KURIR',
            'status' => 'waiting',
            'recipient_name' => $this->pelanggan->name,
        ]);

        $pelangganLain = $this->createCustomer('Rahasia Pelanggan Lain');
        $pesananLain = $this->createOrderFor($pelangganLain, 'ORD-NAMA-LAIN');
        Shipment::create([
            'order_id' => $pesananLain->id,
            'courier_id' => $this->admin->id, // ditugaskan ke kurir lain
            'tracking_number' => 'TRK-NAMA-LAIN',
            'status' => 'waiting',
            'recipient_name' => $pelangganLain->name,
        ]);

        $reply = $this->replyAs($this->kurir, 'Siapa nama pelanggan pesanan yang saya antar?');

        $this->assertStringContainsString($this->pelanggan->name, $reply);
        $this->assertStringContainsString('ORD-NAMA-KURIR', $reply);
        $this->assertStringNotContainsString('Rahasia Pelanggan Lain', $reply);
    }

    public function test_courier_without_assignment_gets_honest_empty_state(): void
    {
        $reply = $this->replyAs($this->kurir, 'Siapa nama pelanggan pesanan yang saya antar?');

        $this->assertStringContainsString('Belum ada penugasan aktif', $reply);
    }

    public function test_staff_are_asked_for_order_number_instead_of_global_lookup(): void
    {
        $asAdmin = $this->replyAs($this->admin, 'siapa nama pelanggan yang saya antar?');
        $this->assertStringContainsString('Sebutkan nomor pesanan', $asAdmin);

        $asAnggota = $this->replyAs($this->anggota, 'siapa nama pelanggan yang saya antar?');
        $this->assertStringContainsString('Sebutkan nomor pesanan', $asAnggota);
    }

    public function test_order_lookup_shows_customer_name_for_authorized_role(): void
    {
        $this->createOrderFor($this->pelanggan, 'ORD-NAMA-PELANGGAN');

        $reply = $this->replyAs($this->admin, 'cek status pesanan ORD-NAMA-PELANGGAN');

        $this->assertStringContainsString('Pelanggan: '.$this->pelanggan->name, $reply);
    }

    /* =====================================================================
     | SKENARIO 7 — PERUBAHAN NAMA LANGSUNG TERBACA (TANPA CACHE)
     * ===================================================================*/

    public function test_renamed_customer_is_visible_on_next_question(): void
    {
        $customer = $this->createCustomer('Nama Awal Uji');

        $before = $this->replyAs($this->admin, 'ada pelanggan bernama nama awal uji?');
        $this->assertStringContainsString('terdaftar di sistem Agung ATK', $before);

        $customer->update(['name' => 'Nama Baru Terbaru']);

        $after = $this->replyAs($this->admin, 'ada pelanggan bernama nama awal uji?');
        $this->assertSame('Maaf, saya tidak menemukan akun pelanggan dengan nama tersebut.', $after);

        $renamed = $this->replyAs($this->admin, 'ada pelanggan bernama nama baru terbaru?');
        $this->assertStringContainsString('Nama Baru Terbaru', $renamed);
    }

    public function test_self_identity_shows_renamed_name_immediately(): void
    {
        $this->pelanggan->update(['name' => 'Nama Self Baru']);

        $reply = $this->replyAs($this->pelanggan, 'Siapa saya?');

        $this->assertStringContainsString('Nama akun Anda adalah Nama Self Baru', $reply);
    }

    /* =====================================================================
     | SKENARIO 8 — TAMU: IDENTITAS BELUM DAPAT DIVERIFIKASI
     * ===================================================================*/

    public function test_guest_identity_cannot_be_verified(): void
    {
        $reply = $this->replyAs(null, 'Siapa saya?');

        $this->assertSame(ChatbotProfile::GUEST_IDENTITY_REPLY, $reply);
    }

    /* =====================================================================
     | ANGGOTA: DAftar AKUN TIDAK OTOMATIS DIDAPAT
     * ===================================================================*/

    public function test_anggota_gets_search_hint_instead_of_full_customer_list(): void
    {
        $this->createCustomer('Anggota Dilarang List');

        $reply = $this->replyAs($this->anggota, 'sebutkan pelanggan yang terdaftar');

        $this->assertStringContainsString('hanya tersedia untuk admin', $reply);
        $this->assertStringNotContainsString('Anggota Dilarang List', $reply);
    }

    public function test_admin_list_contains_names_without_emails(): void
    {
        $this->createCustomer('Daftar Nama Uji');

        $reply = $this->replyAs($this->admin, 'sebutkan pelanggan yang terdaftar');

        $this->assertStringContainsString('Daftar pelanggan terdaftar', $reply);
        $this->assertStringContainsString('Daftar Nama Uji', $reply);
        $this->assertStringContainsString($this->pelanggan->name, $reply);
        $this->assertStringNotContainsString('@agungatk.com', $reply);
        $this->assertStringNotContainsString('@contoh.test', $reply);
    }
}
