<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Jenis laporan yang tersedia (hanya admin).
     */
    public const TYPES = [
        'penjualan' => 'Laporan Penjualan',
        'transaksi' => 'Laporan Transaksi',
        'produk-terlaris' => 'Produk Terlaris',
        'stok' => 'Laporan Stok Produk',
        'pelanggan' => 'Laporan Pelanggan',
    ];

    /**
     * Nilai status order yang diterima filter.
     */
    public const STATUSES = [
        'pending', 'paid', 'processing', 'ready_to_ship', 'shipping', 'delivered', 'completed', 'cancelled',
    ];

    /**
     * Halaman laporan (tabel responsive + filter + aksi export).
     */
    public function index(Request $request): View
    {
        $report = $this->buildReport($request);

        return view('admin.reports.index', [
            'report' => $report,
            'types' => self::TYPES,
            'categories' => Category::orderBy('name')->get(),
            'statuses' => self::STATUSES,
        ]);
    }

    /**
     * Halaman cetak (print browser -> Simpan sebagai PDF).
     * Sengaja tanpa layout dashboard agar bebas sidebar/JS eksternal.
     */
    public function printView(Request $request): View
    {
        $report = $this->buildReport($request);

        return view('admin.reports.print', [
            'report' => $report,
            'printedAt' => now()->format('d M Y H:i'),
        ]);
    }

    /**
     * Export CSV laporan sesuai filter aktif (native PHP, tanpa package).
     */
    public function export(Request $request): StreamedResponse
    {
        $report = $this->buildReport($request);

        $filename = 'laporan_'.str_replace('-', '_', $report['type']).'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');

            // BOM agar Excel membaca UTF-8 dengan benar
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $report['columns']);

            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map([$this, 'sanitizeCsvCell'], array_values($row)));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Cegah CSV formula injection (OWASP): sel teks yang diawali karakter
     * formula (=, +, -, @, tab, CR) diberi awalan kutip tunggal agar
     * Excel/LibreOffice memperlakukannya sebagai teks — bukan mengevaluasi
     * rumus. Data sel bisa dipengaruhi pengguna (nama pelanggan, catatan).
     */
    private function sanitizeCsvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Validasi & kumpulkan filter dari query string.
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
        ], [
            'start_date.date' => 'Tanggal awal tidak valid.',
            'end_date.date' => 'Tanggal akhir tidak valid.',
            'category_id.exists' => 'Kategori tidak ditemukan.',
            'status.in' => 'Status order tidak valid.',
            'type.in' => 'Jenis laporan tidak valid.',
        ]);

        return [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'category_id' => $request->input('category_id'),
            'status' => $request->input('status'),
        ];
    }

    /**
     * Susun laporan: kolom + baris data (dipakai halaman, cetak, dan CSV).
     */
    private function buildReport(Request $request): array
    {
        $type = $request->input('type', 'penjualan');
        if (! array_key_exists($type, self::TYPES)) {
            $type = 'penjualan';
        }

        $filters = $this->filters($request);

        [$columns, $rows] = match ($type) {
            'transaksi' => $this->transaksiReport($filters),
            'produk-terlaris' => $this->bestSellersReport($filters),
            'stok' => $this->stockReport($filters),
            'pelanggan' => $this->customersReport($filters),
            default => $this->salesReport($filters),
        };

        return [
            'type' => $type,
            'title' => self::TYPES[$type],
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $filters,
            'filter_labels' => $this->filterLabels($filters),
            'total_rows' => count($rows),
        ];
    }

    /**
     * Label ramah untuk info filter aktif di layar & cetak.
     */
    private function filterLabels(array $filters): array
    {
        $category = ! empty($filters['category_id'])
            ? Category::find($filters['category_id'])?->name
            : null;

        return [
            'period' => ($filters['start_date'] ?: 'Semua awal').' s.d. '.($filters['end_date'] ?: 'sekarang'),
            'category' => $category ?? 'Semua kategori',
            'status' => $filters['status'] ? strtoupper($filters['status']) : 'Semua status',
        ];
    }

    /**
     * Query pesanan dengan filter tanggal & status.
     */
    private function filteredOrders(array $filters): Builder
    {
        $query = Order::query();

        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    /**
     * Laporan Penjualan: rekap jumlah pesanan & omzet per hari.
     */
    private function salesReport(array $filters): array
    {
        $orders = $this->filteredOrders($filters)->get(['created_at', 'grand_total']);

        $grouped = $orders
            ->groupBy(fn ($order) => $order->created_at->format('Y-m-d'))
            ->sortKeysDesc();

        $rows = [];
        foreach ($grouped as $date => $group) {
            $rows[] = [
                Carbon::parse($date)->format('d M Y'),
                (string) $group->count(),
                number_format((float) $group->sum('grand_total'), 0, ',', '.'),
            ];
        }

        return [
            ['Tanggal', 'Jumlah Pesanan', 'Pendapatan (Rp)'],
            $rows,
        ];
    }

    /**
     * Laporan Transaksi: daftar pesanan individual dalam periode/status.
     */
    private function transaksiReport(array $filters): array
    {
        $orders = $this->filteredOrders($filters)
            ->with(['user', 'orderDetails'])
            ->latest('created_at')
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                $order->order_number,
                $order->created_at->format('d M Y H:i'),
                $order->user?->name ?? '-',
                (string) $order->orderDetails->sum('quantity'),
                number_format((float) $order->grand_total, 0, ',', '.'),
                strtoupper($order->status),
            ];
        }

        return [
            ['No. Order', 'Tanggal', 'Pelanggan', 'Items', 'Total (Rp)', 'Status'],
            $rows,
        ];
    }

    /**
     * Laporan Produk Terlaris: total qty & omzet per produk.
     */
    private function bestSellersReport(array $filters): array
    {
        $orderIds = $this->filteredOrders($filters)->pluck('id');

        $details = OrderDetail::whereIn('order_id', $orderIds)
            ->get(['order_id', 'product_id', 'quantity', 'subtotal']);

        $grouped = $details->groupBy('product_id');

        $products = Product::with('category')
            ->whereIn('id', $grouped->keys())
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('category_id', $filters['category_id']))
            ->get();

        $rows = collect();
        foreach ($products as $product) {
            $items = $grouped->get($product->id);
            $rows->push([
                'product' => $product,
                'qty' => (int) $items->sum('quantity'),
                'revenue' => (float) $items->sum('subtotal'),
            ]);
        }

        $rows = $rows->sortByDesc('qty')->values();

        $data = [];
        foreach ($rows as $index => $row) {
            $data[] = [
                (string) ($index + 1),
                $row['product']->name,
                $row['product']->category?->name ?? '-',
                (string) $row['qty'],
                number_format($row['revenue'], 0, ',', '.'),
            ];
        }

        return [
            ['#', 'Produk', 'Kategori', 'Terjual', 'Pendapatan (Rp)'],
            $data,
        ];
    }

    /**
     * Laporan Stok: snapshot stok produk (urut paling sedikit lebih dulu).
     */
    private function stockReport(array $filters): array
    {
        $products = Product::with('category')
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('category_id', $filters['category_id']))
            ->orderBy('stock')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($products as $product) {
            $stock = (int) $product->stock;
            $status = $stock <= 0 ? 'Habis'
                : ($stock <= (int) $product->min_stock ? 'Menipis' : 'Aman');

            $rows[] = [
                $product->name,
                $product->sku,
                $product->category?->name ?? '-',
                (string) $stock,
                (string) (int) $product->min_stock,
                $status,
                number_format((float) $product->price, 0, ',', '.'),
            ];
        }

        return [
            ['Produk', 'SKU', 'Kategori', 'Stok', 'Stok Minimum', 'Status', 'Harga (Rp)'],
            $rows,
        ];
    }

    /**
     * Laporan Pelanggan: jumlah pesanan & total belanja per pelanggan.
     */
    private function customersReport(array $filters): array
    {
        $role = Role::where('name', 'pelanggan')->first();

        $ordersByUser = $this->filteredOrders($filters)
            ->get(['user_id', 'grand_total', 'created_at'])
            ->groupBy('user_id');

        $users = $role
            ? User::where('role_id', $role->id)->get()
            : collect();

        $rows = $users->map(function (User $user) use ($ordersByUser) {
            $orders = $ordersByUser->get($user->id);

            return [
                'user' => $user,
                'count' => $orders?->count() ?? 0,
                'total' => (float) ($orders?->sum('grand_total') ?? 0),
                'last' => $orders?->max(fn ($order) => $order->created_at),
            ];
        })->sortByDesc('total')->values();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['user']->name,
                $row['user']->email,
                $row['user']->phone ?? '-',
                (string) $row['count'],
                number_format($row['total'], 0, ',', '.'),
                $row['last'] ? $row['last']->format('d M Y') : '-',
            ];
        }

        return [
            ['Pelanggan', 'Email', 'No. HP', 'Jumlah Pesanan', 'Total Belanja (Rp)', 'Pesanan Terakhir'],
            $data,
        ];
    }
}
