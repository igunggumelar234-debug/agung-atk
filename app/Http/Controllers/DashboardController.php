<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Status pesanan yang dihitung sebagai pendapatan toko (sudah dibayar,
     * tidak dibatalkan) — dipakai untuk total omset & grafik penjualan.
     */
    private const REVENUE_STATUSES = [
        'paid', 'processing', 'ready_to_ship', 'shipping', 'delivered', 'completed',
    ];

    /**
     * Dashboard untuk Administrator Toko.
     */
    public function admin(): View
    {
        $stats = [
            'total_users' => User::count(),
            'total_products' => Product::count(),
            'total_categories' => Category::count(),
            'total_orders' => Order::count(),
            'total_revenue' => Order::whereIn('status', self::REVENUE_STATUSES)->sum('grand_total'),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'low_stock_products' => Product::lowStock()->count(),
        ];

        // Rekap pesanan per status untuk 4 kartu status dashboard
        $statusCounts = Order::groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->pluck('total', 'status');

        $stats['orders_new'] = (int) $statusCounts->get('pending', 0) + (int) $statusCounts->get('paid', 0);
        $stats['orders_processed'] = (int) $statusCounts->get('processing', 0) + (int) $statusCounts->get('ready_to_ship', 0);
        $stats['orders_shipped'] = (int) $statusCounts->get('shipping', 0) + (int) $statusCounts->get('delivered', 0);
        $stats['orders_completed'] = (int) $statusCounts->get('completed', 0);

        // Jumlah pengguna per role resmi (admin, anggota, kurir, pelanggan)
        $roleCounts = Role::withCount('users')->get()->keyBy('name');
        $stats['total_customers'] = (int) ($roleCounts->get('pelanggan')?->users_count ?? 0);
        $stats['total_members'] = (int) ($roleCounts->get('anggota')?->users_count ?? 0)
            + (int) ($roleCounts->get('penjual')?->users_count ?? 0);
        $stats['total_couriers'] = (int) ($roleCounts->get('kurir')?->users_count ?? 0);

        $recentOrders = Order::with(['user', 'payment', 'shipment'])
            ->latest()
            ->take(5)
            ->get();

        $lowStockItems = Product::lowStock()->take(5)->get();

        // Jejak audit terakhir untuk panel "Aktivitas Terakhir" (hanya admin)
        $recentActivities = ActivityLog::with('user')->latest()->take(8)->get();

        // Seri data grafik: harian (14 hari), mingguan (8 minggu), bulanan (12 bulan)
        $chartData = $this->salesChartData();

        return view('dashboard.admin', compact('stats', 'recentOrders', 'lowStockItems', 'chartData', 'recentActivities'));
    }

    /**
     * Menyiapkan data grafik penjualan harian, mingguan, dan bulanan.
     *
     * Periode panjang (12 bulan) diambil dalam satu query lalu di-agregasi
     * di PHP agar konsisten antara MySQL (dev) dan SQLite (test).
     */
    private function salesChartData(): array
    {
        $orders = Order::whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['created_at', 'grand_total']);

        // Label nol untuk14 hari,8 minggu (Senin), dan12 bulan terakhir
        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $daily[$date->format('Y-m-d')] = ['label' => $date->format('d M'), 'value' => 0];
        }

        $weekly = [];
        for ($i = 7; $i >= 0; $i--) {
            $date = now()->subWeeks($i)->startOfWeek();
            $weekly[$date->format('Y-m-d')] = ['label' => $date->format('d M'), 'value' => 0];
        }

        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i)->startOfMonth();
            $monthly[$date->format('Y-m')] = ['label' => $date->format('M Y'), 'value' => 0];
        }

        foreach ($orders as $order) {
            $day = $order->created_at->format('Y-m-d');
            if (isset($daily[$day])) {
                $daily[$day]['value'] += $order->grand_total;
            }

            $week = $order->created_at->copy()->startOfWeek()->format('Y-m-d');
            if (isset($weekly[$week])) {
                $weekly[$week]['value'] += $order->grand_total;
            }

            $month = $order->created_at->format('Y-m');
            if (isset($monthly[$month])) {
                $monthly[$month]['value'] += $order->grand_total;
            }
        }

        $pack = fn (array $series) => [
            'labels' => array_column($series, 'label'),
            'values' => array_map(fn ($v) => (int) $v['value'], array_values($series)),
        ];

        return [
            'daily' => $pack($daily),
            'weekly' => $pack($weekly),
            'monthly' => $pack($monthly),
        ];
    }

    /**
     * Dashboard untuk Anggota / Pegawai Toko (karyawan internal).
     * Menampilkan antrean pekerjaan pesanan, ketersediaan stok,
     * peringatan stok rendah, dan riwayat aktivitas sendiri.
     */
    public function anggota(): View
    {
        $ordersToProcess = Order::with(['user', 'orderDetails', 'payment'])
            ->whereIn('status', ['paid', 'processing'])
            ->latest()
            ->take(8)
            ->get();

        $stats = [
            'orders_to_process' => Order::whereIn('status', ['paid', 'processing'])->count(),
            'ready_to_ship' => Order::where('status', 'ready_to_ship')->count(),
            'total_products' => Product::count(),
            'low_stock_count' => Product::lowStock()->count(),
        ];

        $lowStockItems = Product::lowStock()->orderBy('stock')->take(6)->get();

        $recentActivities = ActivityLog::where('user_id', auth()->id())
            ->latest()
            ->take(8)
            ->get();

        return view('dashboard.anggota', compact('ordersToProcess', 'stats', 'lowStockItems', 'recentActivities'));
    }

    /**
     * Dashboard untuk Kurir Pengantaran Wilayah Kuningan.
     */
    public function courier(Request $request): View
    {
        $courierId = $request->user()->id;

        $activeDeliveries = Shipment::with(['order.user', 'order.address'])
            ->where('courier_id', $courierId)
            ->whereIn('status', ['waiting', 'picked_up', 'shipping'])
            ->latest()
            ->get();

        $stats = [
            'active_tasks' => $activeDeliveries->count(),
            'completed_tasks' => Shipment::where('courier_id', $courierId)->where('status', 'delivered')->count(),
            'available_orders' => Shipment::whereNull('courier_id')->where('status', 'waiting')->count(),
        ];

        return view('dashboard.courier', compact('activeDeliveries', 'stats'));
    }

    /**
     * Dashboard untuk Pelanggan (pembeli) — bukan Anggota Toko.
     * "Anggota" adalah karyawan internal, bukan istilah keanggotaan belanja.
     */
    public function customer(Request $request): View
    {
        $user = $request->user();

        $recentOrders = Order::with(['orderDetails.product', 'payment', 'shipment'])
            ->where('user_id', $user->id)
            ->latest()
            ->take(5)
            ->get();

        $addresses = $user->addresses()->take(3)->get();

        $stats = [
            'total_orders' => $user->orders()->count(),
            'active_orders' => $user->orders()->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'total_reviews' => $user->reviews()->count(),
        ];

        return view('dashboard.customer', compact('recentOrders', 'addresses', 'stats'));
    }
}
