<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bagian 6 MASTER — pembatas laju (throttle) pada route berisiko yang
 * sebelumnya tanpa batas: review produk, unggah bukti pembayaran, dan
 * export laporan CSV. Tanpa batas, sesi valid dapat membanjiri tabel
 * reviews/berkas bukti dan membebani query export dalam hitungan detik.
 *
 * Catatan: middleware throttle berjalan SEBELUM controller, sehingga
 * permintaan yang gagal validasi (422) tetap ikut terhitung — itu yang
 * dipakai tes ini agar tidak perlu data valid ulang tiap iterasi.
 */
class ThrottleProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
    }

    public function test_review_submission_is_rate_limited_to_ten_per_minute(): void
    {
        $product = Product::where('is_active', true)->firstOrFail();

        $this->actingAs($this->pelanggan);

        // Payload kosong -> gagal validasi (422 JSON); tetap terhitung throttle.
        foreach (range(1, 10) as $ignored) {
            $this->postJson(route('reviews.store', $product->slug), [])
                ->assertStatus(422);
        }

        $this->postJson(route('reviews.store', $product->slug), [])
            ->assertStatus(429);
    }

    public function test_payment_proof_upload_is_rate_limited_to_ten_per_minute(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-THRTL-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => null,
            'total_amount' => 10000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 10000,
            'status' => 'pending',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-THRTL-'.uniqid(),
            'method' => 'transfer',
            'amount' => 10000,
            'status' => 'pending',
        ]);

        $this->actingAs($this->pelanggan);

        // Tanpa berkas -> gagal validasi (422 JSON); tetap terhitung throttle.
        foreach (range(1, 10) as $ignored) {
            $this->postJson(route('payments.proof.upload', $payment->id), [])
                ->assertStatus(422);
        }

        $this->postJson(route('payments.proof.upload', $payment->id), [])
            ->assertStatus(429);
    }

    public function test_report_export_is_rate_limited_to_five_per_minute(): void
    {
        $this->actingAs($this->admin);

        foreach (range(1, 5) as $ignored) {
            $this->get(route('admin.reports.export'))->assertOk();
        }

        $this->get(route('admin.reports.export'))->assertStatus(429);
    }
}
