<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $penjual;

    private User $pelanggan;

    private User $kurir;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
    }

    /**
     * Buat pesanan (default: milik pelanggan, status pending).
     */
    private function createOrder(string $status = 'pending', ?\DateTimeInterface $createdAt = null): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-RPT-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $this->address->id,
            'total_amount' => 25000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 25000,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        if ($createdAt) {
            $order->created_at = $createdAt;
            $order->save();
        }

        return $order;
    }

    /* =====================================================================
     | DASHBOARD ADMIN
     * ===================================================================*/

    public function test_admin_dashboard_shows_required_stats_and_charts(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/dashboard');

        $response->assertStatus(200);

        // Kartu statistik yang diminta
        $response->assertSee('Total Omset');
        $response->assertSee('Katalog Produk');
        $response->assertSee('Pesanan Baru');
        $response->assertSee('Pesanan Diproses');
        $response->assertSee('Pesanan Dikirim');
        $response->assertSee('Pesanan Selesai');
        $response->assertSee('Total Pelanggan');
        $response->assertSee('Total Anggota');
        $response->assertSee('Total Kurir');

        // Grafik harian, mingguan, bulanan (Chart.js CDN + data JSON)
        $response->assertSee('Grafik Penjualan');
        $response->assertSee('cdn.jsdelivr.net/npm/chart.js');
        $response->assertSee('chartDaily');
        $response->assertSee('chartWeekly');
        $response->assertSee('chartMonthly');
        $response->assertSee('"daily"', false);
        $response->assertSee('"weekly"', false);
        $response->assertSee('"monthly"', false);
    }

    public function test_admin_dashboard_counts_new_orders_and_roles(): void
    {
        // Tiga pesanan baru (pending) → kartu "Pesanan Baru" = pending + paid
        $this->createOrder('pending');
        $this->createOrder('pending');
        $this->createOrder('pending');

        $expectedNew = Order::whereIn('status', ['pending', 'paid'])->count();
        $expectedKurir = User::whereHas('role', fn ($q) => $q->where('name', 'kurir'))->count();

        $response = $this->actingAs($this->admin)->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertSeeInOrder(['Pesanan Baru', '>'.$expectedNew.'</h4>']);
        $response->assertSeeInOrder(['Total Kurir', '>'.$expectedKurir.'</h4>']);
    }

    /* =====================================================================
     | LAPORAN: AUTORIZASI (HANYA ADMIN)
     * ===================================================================*/

    public function test_guest_and_non_admin_cannot_access_reports(): void
    {
        $urls = [
            route('admin.reports.index'),
            route('admin.reports.export', ['type' => 'transaksi']),
            route('admin.reports.print', ['type' => 'stok']),
        ];

        // Tamu: redirect ke login (dicek dulu — actingAs persisten dalam satu test)
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        // Non-admin → 403
        foreach ([$this->penjual, $this->pelanggan, $this->kurir] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertStatus(403);
            }
        }
    }

    /* =====================================================================
     | LAPORAN: JENIS & TAMPILAN
     * ===================================================================*/

    public function test_default_report_is_sales_recap(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.reports.index'));

        $response->assertStatus(200);
        $response->assertSee('Laporan Penjualan');
        $response->assertSee('Tanggal');
        $response->assertSee('Jumlah Pesanan');
        $response->assertSee('Pendapatan (Rp)');
        $response->assertSee('Export CSV');
        $response->assertSee('Cetak / Simpan PDF');
    }

    public function test_all_five_report_types_render(): void
    {
        $markers = [
            'penjualan' => 'Jumlah Pesanan',
            'transaksi' => 'No. Order',
            'produk-terlaris' => 'Terjual',
            'stok' => 'Stok Minimum',
            'pelanggan' => 'Total Belanja (Rp)',
        ];

        foreach ($markers as $type => $marker) {
            $this->actingAs($this->admin)
                ->get(route('admin.reports.index', ['type' => $type]))
                ->assertStatus(200)
                ->assertSee($marker);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'transaksi']))
            ->assertSee('Laporan Transaksi');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'produk-terlaris']))
            ->assertSee('Produk Terlaris');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'stok']))
            ->assertSee('Laporan Stok Produk');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'pelanggan']))
            ->assertSee('Laporan Pelanggan');
    }

    /* =====================================================================
     | LAPORAN: FILTER
     * ===================================================================*/

    public function test_sales_recap_lists_dates_with_orders(): void
    {
        $threeDaysAgo = now()->subDays(3);
        $this->createOrder('completed', $threeDaysAgo);
        $this->createOrder('completed', now());

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'penjualan']))
            ->assertStatus(200)
            ->assertSee($threeDaysAgo->format('d M Y'))
            ->assertSee(now()->format('d M Y'));
    }

    public function test_transaction_report_filters_by_date_range(): void
    {
        $oldOrder = $this->createOrder('completed', now()->subDays(10));
        $recentOrder = $this->createOrder('completed', now());

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', [
                'type' => 'transaksi',
                'start_date' => now()->subDays(5)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]))
            ->assertStatus(200)
            ->assertSee($recentOrder->order_number)
            ->assertDontSee($oldOrder->order_number);
    }

    public function test_transaction_report_filters_by_status(): void
    {
        $pendingOrder = $this->createOrder('pending');
        $completedOrder = $this->createOrder('completed');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'transaksi', 'status' => 'pending']))
            ->assertStatus(200)
            ->assertSee($pendingOrder->order_number)
            ->assertDontSee($completedOrder->order_number);
    }

    public function test_stock_report_filters_by_category(): void
    {
        $productsByCategory = Product::query()->get()->groupBy('category_id');
        $this->assertGreaterThanOrEqual(2, $productsByCategory->count(), 'Seeder minimal punya 2 kategori berbeda.');

        $categoryA = $productsByCategory->first();
        $categoryB = $productsByCategory->skip(1)->first();
        $productA = $categoryA->first();
        $productB = $categoryB->first();

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', [
                'type' => 'stok',
                'category_id' => $productA->category_id,
            ]))
            ->assertStatus(200)
            ->assertSee($productA->name)
            ->assertDontSee($productB->name);
    }

    public function test_empty_date_range_shows_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', [
                'type' => 'transaksi',
                'start_date' => '2000-01-01',
                'end_date' => '2000-01-02',
            ]))
            ->assertStatus(200)
            ->assertSee('Tidak ada data untuk filter yang dipilih.');
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'transaksi', 'status' => 'ngawur']))
            ->assertSessionHasErrors('status');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'hacked']))
            ->assertSessionHasErrors('type');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['start_date' => '32-13-2026']))
            ->assertSessionHasErrors('start_date');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');
    }

    /* =====================================================================
     | LAPORAN: ISI PER JENIS
     * ===================================================================*/

    public function test_best_seller_report_aggregates_quantity_and_revenue(): void
    {
        $product = Product::whereDoesntHave('orderDetails')->firstOrFail();
        $revenue = number_format((float) $product->price * 5, 0, ',', '.');

        $order = $this->createOrder('completed');
        OrderDetail::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => 5,
            'subtotal' => (float) $product->price * 5,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'produk-terlaris']))
            ->assertStatus(200)
            ->assertSee($product->name)
            ->assertSee($revenue);
    }

    public function test_customer_report_shows_order_totals(): void
    {
        $expectedTotal = number_format(
            (float) Order::where('user_id', $this->pelanggan->id)->sum('grand_total'),
            0,
            ',',
            '.'
        );

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['type' => 'pelanggan']))
            ->assertStatus(200)
            ->assertSee($this->pelanggan->email)
            ->assertSee($expectedTotal);
    }

    /* =====================================================================
     | EXPORT CSV & CETAK/PDF
     * ===================================================================*/

    public function test_csv_export_streams_filtered_report(): void
    {
        $order = $this->createOrder('completed');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.export', ['type' => 'transaksi']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );

        $csv = $response->streamedContent();
        $this->assertStringContainsString('No. Order', $csv);
        $this->assertStringContainsString($order->order_number, $csv);
        $this->assertStringContainsString('laporan_transaksi', (string) $response->headers->get('content-disposition'));
    }

    public function test_print_view_renders_standalone_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.print', ['type' => 'stok']));

        $response->assertStatus(200);
        $response->assertSee('Laporan Stok Produk');
        $response->assertSee('window.print');
        $response->assertSee('Agung ATK Kuningan');
        // Halaman cetak tidak memakai layout dashboard (tanpa sidebar)
        $response->assertDontSee('dashboard-sidebar');
    }
}
