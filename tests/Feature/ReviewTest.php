<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Review;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $penjual;

    private User $pelanggan;

    private User $kurir;

    private Address $address;

    private Product $product;      // produk tanpa review (dari seeder)

    private Product $seededReview; // produk yang sudah punya review seeder (product1)

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
        $this->product = Product::whereDoesntHave('reviews')->firstOrFail();
        $this->seededReview = Product::whereHas('reviews')->firstOrFail();
    }

    /**
     * Buat pesanan (default: milik pelanggan, berisi $product, status completed).
     */
    private function createOrder(
        ?Product $product = null,
        string $status = 'completed',
        ?User $customer = null,
        ?Address $address = null
    ): Order {
        $customer ??= $this->pelanggan;
        $address ??= Address::where('user_id', $customer->id)->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-REV-'.uniqid(),
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'total_amount' => 50000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 50000,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        if ($product) {
            OrderDetail::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
                'subtotal' => $product->price,
            ]);
        }

        return $order;
    }

    private function createCustomer(string $email): User
    {
        $customer = User::create([
            'name' => 'Pelanggan Kedua',
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => Role::whereIn('name', ['customer', 'pelanggan'])->first()->id,
            'is_active' => true,
        ]);

        Address::create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Pelanggan Kedua',
            'phone' => '08111111111',
            'full_address' => 'Jl. Contoh No. 2',
            'city' => 'Kuningan',
            'district' => 'Kuningan',
            'postal_code' => '45511',
            'is_primary' => true,
        ]);

        return $customer;
    }

    /* =====================================================================
     | AUTENTIKASI & AUTHORIZATION
     * ===================================================================*/

    public function test_guest_is_redirected_from_review_submission(): void
    {
        $order = $this->createOrder($this->product);

        $this->post(route('reviews.store', $this->product), [
            'order_id' => $order->id,
            'rating' => 5,
            'comment' => 'Mantap sekali produknya.',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('reviews', ['order_id' => $order->id]);
    }

    public function test_non_customer_roles_are_forbidden(): void
    {
        $order = $this->createOrder($this->product);

        foreach ([$this->admin, $this->penjual, $this->kurir] as $user) {
            $this->actingAs($user)
                ->post(route('reviews.store', $this->product), [
                    'order_id' => $order->id,
                    'rating' => 5,
                    'comment' => 'Seharusnya ditolak untuk role ini.',
                ])
                ->assertStatus(403);
        }

        $this->assertDatabaseMissing('reviews', ['order_id' => $order->id]);
    }

    /* =====================================================================
     | VALIDASI SERVER-SIDE Saat Menyimpan
     * ===================================================================*/

    public function test_customer_can_submit_review_for_completed_order(): void
    {
        $order = $this->createOrder($this->product);

        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order->id,
                'rating' => 4,
                'comment' => 'Penjualan cepat, stok sesuai, recommended.',
            ])
            ->assertRedirect(route('products.show', $this->product))
            ->assertSessionHas('success');

        $review = Review::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($this->pelanggan->id, (int) $review->user_id);
        $this->assertSame($this->product->id, (int) $review->product_id);
        $this->assertSame(4, (int) $review->rating);
        $this->assertSame('Penjualan cepat, stok sesuai, recommended.', $review->comment);
        $this->assertTrue($review->is_approved);

        // Review tampil di halaman detail produk
        $this->get(route('products.show', $this->product))
            ->assertStatus(200)
            ->assertSee('Penjualan cepat, stok sesuai, recommended.');
    }

    public function test_review_rejects_order_belonging_to_other_user(): void
    {
        $other = $this->createCustomer('pelanggan2@agungatk.com');
        $foreignOrder = $this->createOrder($this->product, 'completed', $other);

        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $foreignOrder->id,
                'rating' => 5,
                'comment' => 'Ini pesanan orang lain, harus ditolak.',
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertDatabaseMissing('reviews', ['order_id' => $foreignOrder->id]);
    }

    public function test_review_rejects_order_not_completed(): void
    {
        foreach (['pending', 'paid', 'processing', 'shipping', 'delivered', 'cancelled'] as $status) {
            $order = $this->createOrder($this->product, $status);

            $this->actingAs($this->pelanggan)
                ->post(route('reviews.store', $this->product), [
                    'order_id' => $order->id,
                    'rating' => 5,
                    'comment' => 'Pesanan belum selesai, harus ditolak.',
                ])
                ->assertSessionHasErrors('order_id');

            $this->assertDatabaseMissing('reviews', ['order_id' => $order->id]);
        }
    }

    public function test_review_rejects_product_not_in_order(): void
    {
        // Pesanan completed TETAPI tidak memuat produk yang direview
        $otherProduct = Product::where('id', '!=', $this->product->id)->whereDoesntHave('reviews')->firstOrFail();
        $order = $this->createOrder($otherProduct);

        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order->id,
                'rating' => 5,
                'comment' => 'Produk ini tidak ada di pesanan, harus ditolak.',
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertDatabaseMissing('reviews', ['order_id' => $order->id, 'product_id' => $this->product->id]);
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $order = $this->createOrder($this->product);

        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order->id,
                'rating' => 5,
                'comment' => 'Review pertama untuk pesanan ini.',
            ])
            ->assertSessionHas('success');

        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order->id,
                'rating' => 1,
                'comment' => 'Review kedua seharusnya ditolak.',
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertSame(1, Review::where('order_id', $order->id)->count());
    }

    public function test_rating_must_be_1_to_5(): void
    {
        foreach ([0, 6, -3, 'lima'] as $rating) {
            $order = $this->createOrder($this->product);

            $this->actingAs($this->pelanggan)
                ->post(route('reviews.store', $this->product), [
                    'order_id' => $order->id,
                    'rating' => $rating,
                    'comment' => 'Rating tidak valid harus ditolak.',
                ])
                ->assertSessionHasErrors('rating');

            $this->assertDatabaseMissing('reviews', ['order_id' => $order->id]);
        }
    }

    public function test_comment_is_required_and_limited(): void
    {
        // Wajib
        $order1 = $this->createOrder($this->product);
        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order1->id,
                'rating' => 5,
                'comment' => '',
            ])
            ->assertSessionHasErrors('comment');

        // Minimal 3 karakter
        $order2 = $this->createOrder($this->product);
        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order2->id,
                'rating' => 5,
                'comment' => 'ab',
            ])
            ->assertSessionHasErrors('comment');

        // Maksimal 1000 karakter
        $order3 = $this->createOrder($this->product);
        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $order3->id,
                'rating' => 5,
                'comment' => str_repeat('x', 1001),
            ])
            ->assertSessionHasErrors('comment');

        // Tidak ada satu pun yang tersimpan
        $this->assertDatabaseMissing('reviews', ['order_id' => $order1->id]);
        $this->assertDatabaseMissing('reviews', ['order_id' => $order2->id]);
        $this->assertDatabaseMissing('reviews', ['order_id' => $order3->id]);
    }

    public function test_database_rejects_duplicate_review_row(): void
    {
        $order = $this->createOrder($this->product);
        $payload = [
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'user_id' => $this->pelanggan->id,
            'rating' => 5,
            'comment' => 'Baris pertama.',
            'is_approved' => true,
        ];

        Review::create($payload);

        $this->expectException(QueryException::class);
        Review::create($payload);
    }

    /* =====================================================================
     | HALAMAN DETAIL PRODUK: RATING, JUMLAH, DAFTAR REVIEW, FORM
     * ===================================================================*/

    public function test_product_page_shows_rating_summary_and_review_list(): void
    {
        // Dua review (4 & 5) lewat dua pesanan berbeda milik pelanggan yang sama
        $orderA = $this->createOrder($this->product);
        $orderB = $this->createOrder($this->product);

        $this->actingAs($this->pelanggan)->post(route('reviews.store', $this->product), [
            'order_id' => $orderA->id, 'rating' => 4, 'comment' => 'Kualitas oke untuk harga segitu.',
        ])->assertSessionHas('success');

        $this->actingAs($this->pelanggan)->post(route('reviews.store', $this->product), [
            'order_id' => $orderB->id, 'rating' => 5, 'comment' => 'Pengiriman cepat ke Kuningan.',
        ])->assertSessionHas('success');

        $this->get(route('products.show', $this->product))
            ->assertStatus(200)
            ->assertSee('4.5')                    // rata-rata (4+5)/2
            ->assertSee('2 ulasan')               // jumlah review
            ->assertSee('Kualitas oke untuk harga segitu.')
            ->assertSee('Pengiriman cepat ke Kuningan.');
    }

    public function test_product_page_without_reviews_shows_empty_state(): void
    {
        $this->get(route('products.show', $this->product))
            ->assertStatus(200)
            ->assertSee('Belum ada ulasan untuk produk ini.')
            ->assertSee('Belum ada ulasan');      // ringkasan di header
    }

    public function test_review_form_shown_only_to_eligible_customer(): void
    {
        // (a) Tamu: tanpa form
        $this->get(route('products.show', $this->product))
            ->assertDontSee('name="rating"', false);

        // (b) Pelanggan TANPA pesanan eligible: tanpa form + ada info
        $this->actingAs($this->pelanggan)
            ->get(route('products.show', $this->product))
            ->assertDontSee('name="rating"', false)
            ->assertSee('Anda belum dapat mengulas produk ini');

        // (c) Admin: tidak pernah ditampilkan form review
        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertDontSee('name="rating"', false);

        // (d) Pelanggan DENGAN pesanan completed: form tampil + pesanan terpilih
        $order = $this->createOrder($this->product);
        $this->actingAs($this->pelanggan)
            ->get(route('products.show', $this->product))
            ->assertSee('name="rating"', false)
            ->assertSee('name="order_id"', false)
            ->assertSee($order->order_number);
    }

    public function test_form_lists_all_eligible_orders(): void
    {
        $orderA = $this->createOrder($this->product);
        $orderB = $this->createOrder($this->product);

        $response = $this->actingAs($this->pelanggan)
            ->get(route('products.show', $this->product))
            ->assertStatus(200);

        // Kedua pesanan eligible muncul di dropdown pemilihan pesanan
        $response->assertSee($orderA->order_number);
        $response->assertSee($orderB->order_number);

        // Pesanan yang sudah direview tidak lagi ditawarkan
        $this->actingAs($this->pelanggan)
            ->post(route('reviews.store', $this->product), [
                'order_id' => $orderA->id, 'rating' => 5, 'comment' => 'Ulasan untuk pesanan pertama.',
            ])->assertSessionHas('success');

        $this->actingAs($this->pelanggan)
            ->get(route('products.show', $this->product))
            ->assertSee($orderB->order_number)
            ->assertDontSee($orderA->order_number);
    }

    /* =====================================================================
     | ADMIN: MENGHAPUS REVIEW
     * ===================================================================*/

    public function test_admin_can_delete_review(): void
    {
        $order = $this->createOrder($this->product);
        $review = Review::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'user_id' => $this->pelanggan->id,
            'rating' => 1,
            'comment' => 'Review tidak sesuai yang akan dihapus admin.',
            'is_approved' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete('/admin/reviews/'.$review->id)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);

        $this->get(route('products.show', $this->product))
            ->assertDontSee('Review tidak sesuai yang akan dihapus admin.');
    }

    public function test_delete_review_forbidden_for_other_roles(): void
    {
        $order = $this->createOrder($this->product);
        $review = Review::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'user_id' => $this->pelanggan->id,
            'rating' => 5,
            'comment' => 'Review yang harus tetap aman.',
            'is_approved' => true,
        ]);

        // Tamu dulu (actingAs bersifat persisten dalam satu test)
        $this->delete('/admin/reviews/'.$review->id)->assertRedirect('/login');

        foreach ([$this->penjual, $this->pelanggan, $this->kurir] as $user) {
            $this->actingAs($user)
                ->delete('/admin/reviews/'.$review->id)
                ->assertStatus(403);
        }

        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }

    public function test_delete_button_only_visible_to_admin(): void
    {
        $order = $this->createOrder($this->product);
        $review = Review::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'user_id' => $this->pelanggan->id,
            'rating' => 3,
            'comment' => 'Tombol hapus hanya untuk admin.',
            'is_approved' => true,
        ]);

        $url = '/admin/reviews/'.$review->id;

        $this->get(route('products.show', $this->product))
            ->assertDontSee($url, false);

        $this->actingAs($this->pelanggan)
            ->get(route('products.show', $this->product))
            ->assertDontSee($url, false);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertSee($url, false);
    }
}
