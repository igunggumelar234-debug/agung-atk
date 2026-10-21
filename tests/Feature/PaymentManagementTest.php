<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    private User $admin;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();

        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * Buat order + payment pending untuk pelanggan (default: transfer).
     */
    private function createPayment(array $orderOverrides = [], array $paymentOverrides = []): Payment
    {
        $order = Order::create(array_merge([
            'order_number' => 'ORD-TEST-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $this->address->id,
            'total_amount' => 10000,
            'shipping_cost' => 10000,
            'discount_amount' => 0,
            'grand_total' => 20000,
            'status' => 'pending',
        ], $orderOverrides));

        return Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_number' => 'PAY-TEST-'.uniqid(),
            'method' => 'transfer',
            'amount' => 20000,
            'status' => 'pending',
        ], $paymentOverrides));
    }

    /* =====================================================================
     | HALAMAN DETAIL PEMBAYARAN (PELANGGAN)
     * ===================================================================*/

    public function test_guest_is_redirected_to_login_from_payment_page(): void
    {
        $payment = $this->createPayment();

        $this->get('/payments/'.$payment->id)->assertRedirect('/login');
        $this->post('/payments/'.$payment->id.'/proof')->assertRedirect('/login');
        $this->get('/payments/'.$payment->id.'/proof')->assertRedirect('/login');
    }

    public function test_non_customer_roles_are_forbidden_from_payment_page(): void
    {
        $payment = $this->createPayment();

        foreach ([$this->admin, User::where('email', 'penjual@agungatk.com')->firstOrFail(), User::where('email', 'kurir@agungatk.com')->firstOrFail()] as $user) {
            $this->actingAs($user)->get('/payments/'.$payment->id)->assertStatus(403);
            $this->actingAs($user)->post('/payments/'.$payment->id.'/proof', ['proof' => UploadedFile::fake()->image('b.jpg')])->assertStatus(403);
        }
    }

    public function test_customer_can_view_own_payment_detail(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id)
            ->assertStatus(200)
            ->assertSee($payment->payment_number)
            ->assertSee(number_format((float) $payment->amount, 0, ',', '.'))
            ->assertSee('Menunggu Pembayaran')
            ->assertSee('Transfer Bank')
            ->assertSee('Unggah Bukti Pembayaran');
    }

    public function test_customer_cannot_view_other_users_payment(): void
    {
        $seller = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $payment = $this->createPayment(['user_id' => $seller->id]);

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id)
            ->assertStatus(404);
    }

    public function test_payment_detail_shows_verified_status(): void
    {
        $payment = $this->createPayment([], ['status' => 'verified', 'verified_at' => now()]);

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id)
            ->assertStatus(200)
            ->assertSee('Terverifikasi');
    }

    public function test_payment_detail_shows_rejected_status_and_reason(): void
    {
        $payment = $this->createPayment([], ['status' => 'rejected', 'rejection_reason' => 'Bukti tidak jelas, nominal tidak sesuai.']);

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id)
            ->assertStatus(200)
            ->assertSee('Ditolak')
            ->assertSee('Bukti tidak jelas, nominal tidak sesuai.');
    }

    public function test_cod_payment_does_not_show_upload_form(): void
    {
        $payment = $this->createPayment([], ['method' => 'cod']);

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id)
            ->assertStatus(200)
            ->assertSee('COD (Bayar di Tempat)')
            ->assertDontSee('Unggah Bukti Pembayaran');
    }

    /* =====================================================================
     | UPLOAD BUKTI PEMBAYARAN
     * ===================================================================*/

    public function test_customer_can_upload_proof_for_transfer(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('bukti-transfer.png', 400, 300),
            ])
            ->assertSessionHas('success');

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->proof_image);
        $this->assertSame('pending', $fresh->status);
        Storage::disk('local')->assertExists($fresh->proof_image);
    }

    public function test_customer_can_upload_proof_for_qris(): void
    {
        $payment = $this->createPayment([], ['method' => 'qris']);

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('bukti-qris.jpg', 400, 300),
            ])
            ->assertSessionHas('success');

        Storage::disk('local')->assertExists($payment->fresh()->proof_image);
    }

    public function test_proof_upload_is_rejected_for_cod(): void
    {
        $payment = $this->createPayment([], ['method' => 'cod']);

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('bukti.png'),
            ])
            ->assertSessionHasErrors('proof');

        $this->assertNull($payment->fresh()->proof_image);
    }

    public function test_proof_upload_is_rejected_when_not_pending(): void
    {
        $verified = $this->createPayment([], ['status' => 'verified']);
        $rejected = $this->createPayment([], ['status' => 'rejected']);

        foreach ([$verified, $rejected] as $payment) {
            $this->actingAs($this->pelanggan)
                ->post('/payments/'.$payment->id.'/proof', [
                    'proof' => UploadedFile::fake()->image('bukti.png'),
                ])
                ->assertSessionHasErrors('proof');

            $this->assertNull($payment->fresh()->proof_image);
        }
    }

    public function test_proof_upload_rejects_non_image_file(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('proof');

        $this->assertNull($payment->fresh()->proof_image);
    }

    public function test_proof_upload_rejects_file_above_2mb(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->create('besar.jpg', 3000, 'image/jpeg'),
            ])
            ->assertSessionHasErrors('proof');

        $this->assertNull($payment->fresh()->proof_image);
    }

    public function test_reupload_replaces_old_proof_file(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('pertama.png'),
            ]);

        $oldPath = $payment->fresh()->proof_image;
        Storage::disk('local')->assertExists($oldPath);

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('kedua.png'),
            ]);

        $newPath = $payment->fresh()->proof_image;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_proof_is_stored_on_private_disk_not_public(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('rahasia.png'),
            ]);

        $path = $payment->fresh()->proof_image;

        // Tidak ada di disk publik (tidak terekspos lewat /storage)
        Storage::disk('public')->assertMissing($path);
        $this->assertStringStartsWith('payment-proofs/', $path);
    }

    public function test_customer_cannot_upload_proof_for_other_users_payment(): void
    {
        $seller = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $payment = $this->createPayment(['user_id' => $seller->id]);

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('bukti.png'),
            ])
            ->assertStatus(404);
    }

    /* =====================================================================
     | STREAMING BUKTI (AUTHORIZATION)
     * ===================================================================*/

    public function test_owner_can_view_proof_file(): void
    {
        $payment = $this->createPayment();
        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', ['proof' => UploadedFile::fake()->image('bukti.png')]);

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id.'/proof')
            ->assertStatus(200);
    }

    public function test_admin_can_view_proof_file(): void
    {
        $payment = $this->createPayment();
        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', ['proof' => UploadedFile::fake()->image('bukti.png')]);

        $this->actingAs($this->admin)
            ->get('/payments/'.$payment->id.'/proof')
            ->assertStatus(200);
    }

    public function test_other_customer_cannot_view_proof_file(): void
    {
        // Pelanggan kedua dengan payment & bukti miliknya sendiri
        $pelanggan2 = User::create([
            'name' => 'Pelanggan Dua',
            'email' => 'pelanggan2@agungatk.com',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'pelanggan')->first()->id,
            'is_active' => true,
        ]);
        $address2 = Address::where('user_id', $this->pelanggan->id)->first();
        $foreign = $this->createPayment(['user_id' => $pelanggan2->id, 'address_id' => $address2->id]);

        $this->actingAs($pelanggan2)
            ->post('/payments/'.$foreign->id.'/proof', ['proof' => UploadedFile::fake()->image('rahasia2.png')])
            ->assertSessionHas('success');

        // Pelanggan pertama bukan pemilik & bukan admin → 403
        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$foreign->id.'/proof')
            ->assertStatus(403);
    }

    public function test_proof_returns_404_when_no_file_uploaded(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->pelanggan)
            ->get('/payments/'.$payment->id.'/proof')
            ->assertStatus(404);
    }

    /* =====================================================================
     | ADMIN — LIST & DETAIL
     * ===================================================================*/

    public function test_admin_can_view_payment_list(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)
            ->get('/admin/payments')
            ->assertStatus(200)
            ->assertSee($payment->payment_number);
    }

    public function test_non_admin_cannot_access_payment_management(): void
    {
        foreach ([$this->pelanggan, User::where('email', 'penjual@agungatk.com')->firstOrFail(), User::where('email', 'kurir@agungatk.com')->firstOrFail()] as $user) {
            $payment = $this->createPayment();

            $this->actingAs($user)->get('/admin/payments')->assertStatus(403);
            $this->actingAs($user)->get('/admin/payments/'.$payment->id)->assertStatus(403);
            $this->actingAs($user)->patch('/admin/payments/'.$payment->id.'/verify')->assertStatus(403);
            $this->actingAs($user)->patch('/admin/payments/'.$payment->id.'/reject', ['rejection_reason' => 'x'])->assertStatus(403);
        }
    }

    public function test_admin_can_filter_payments_by_status(): void
    {
        $pending = $this->createPayment();
        $verified = $this->createPayment([], ['status' => 'verified', 'verified_at' => now()]);

        $this->actingAs($this->admin)
            ->get('/admin/payments?status=pending')
            ->assertStatus(200)
            ->assertSee($pending->payment_number)
            ->assertDontSee($verified->payment_number);

        $this->actingAs($this->admin)
            ->get('/admin/payments?status=verified')
            ->assertStatus(200)
            ->assertSee($verified->payment_number)
            ->assertDontSee($pending->payment_number);
    }

    public function test_admin_can_search_payments(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)
            ->get('/admin/payments?q='.$payment->payment_number)
            ->assertStatus(200)
            ->assertSee($payment->payment_number);
    }

    public function test_admin_payment_detail_shows_proof_and_actions(): void
    {
        $payment = $this->createPayment();
        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', ['proof' => UploadedFile::fake()->image('bukti.png')]);

        $this->actingAs($this->admin)
            ->get('/admin/payments/'.$payment->id)
            ->assertStatus(200)
            ->assertSee('Verifikasi Pembayaran')
            ->assertSee('Tolak Pembayaran')
            ->assertSee($payment->order->order_number);
    }

    /* =====================================================================
     | VERIFIKASI & PENOLAKAN
     * ===================================================================*/

    public function test_admin_can_verify_payment_and_order_becomes_paid(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/verify')
            ->assertSessionHas('success');

        $fresh = $payment->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($this->admin->id, (int) $fresh->verified_by);

        // Order pending → paid
        $this->assertSame('paid', $fresh->order->status);
    }

    public function test_verify_does_not_change_non_pending_order_status(): void
    {
        $payment = $this->createPayment(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/verify')
            ->assertSessionHas('success');

        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('cancelled', $payment->order->fresh()->status);
    }

    public function test_verify_rejects_already_processed_payment(): void
    {
        $verified = $this->createPayment([], ['status' => 'verified', 'verified_at' => now()]);
        $rejected = $this->createPayment([], ['status' => 'rejected']);

        foreach ([$verified, $rejected] as $payment) {
            $this->actingAs($this->admin)
                ->patch('/admin/payments/'.$payment->id.'/verify')
                ->assertSessionHasErrors('payment');

            $this->assertSame($payment->status, $payment->fresh()->status);
        }
    }

    public function test_admin_can_reject_payment_with_reason_and_order_stays_pending(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/reject', [
                'rejection_reason' => 'Bukti transfer buram dan nominal tidak sesuai.',
            ])
            ->assertSessionHas('success');

        $fresh = $payment->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Bukti transfer buram dan nominal tidak sesuai.', $fresh->rejection_reason);
        $this->assertSame($this->admin->id, (int) $fresh->verified_by);

        // Order TIDAK diubah saat penolakan
        $this->assertSame('pending', $fresh->order->status);
    }

    public function test_rejection_requires_reason(): void
    {
        $payment = $this->createPayment();

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/reject', ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_reject_does_not_change_verified_payment(): void
    {
        $payment = $this->createPayment([], ['status' => 'verified', 'verified_at' => now()]);

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/reject', ['rejection_reason' => 'alasan'])
            ->assertSessionHasErrors('payment');

        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->rejection_reason);
    }

    /* =====================================================================
     | ALUR LENGKAP: CHECKOUT → UPLOAD → VERIFIKASI
     * ===================================================================*/

    public function test_checkout_redirects_to_payment_detail_page(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();

        $response = $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ]);

        $payment = Payment::latest('id')->firstOrFail();
        $response->assertRedirect(route('payments.show', $payment));
    }

    public function test_full_flow_checkout_upload_verify(): void
    {
        // 1. Checkout
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ]);

        $payment = Payment::latest('id')->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('pending', $payment->order->status);

        // 2. Pelanggan upload bukti
        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('bukti-lengkap.png'),
            ])
            ->assertSessionHas('success');

        // 3. Admin melihat & memverifikasi
        $this->actingAs($this->admin)
            ->get('/admin/payments/'.$payment->id)
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/verify')
            ->assertSessionHas('success');

        // 4. Status akhir
        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('paid', $payment->order->fresh()->status);
        $this->assertNotNull($payment->fresh()->proof_image);
    }
}
