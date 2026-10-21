@extends('layouts.dashboard')

@section('title', 'Dashboard Administrator')
@section('page-title', 'Dashboard Administrator')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Total Omset</span>
                <span class="p-2 rounded-3 bg-success-subtle text-success"><i class="bi bi-wallet2 fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-1">Rp {{ number_format($stats['total_revenue'], 0, ',', '.') }}</h4>
            <div class="small text-muted"><i class="bi bi-arrow-up-right text-success"></i> Transaksi terverifikasi</div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Total Pesanan</span>
                <span class="p-2 rounded-3 bg-primary-subtle text-primary"><i class="bi bi-cart3 fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-1">{{ $stats['total_orders'] }}</h4>
            <div class="small text-muted">{{ $stats['pending_orders'] }} pesanan butuh tindakan</div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Katalog Produk</span>
                <span class="p-2 rounded-3 bg-warning-subtle text-warning-emphasis"><i class="bi bi-box-seam fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-1">{{ $stats['total_products'] }}</h4>
            <div class="small text-muted">Dalam {{ $stats['total_categories'] }} kategori ATK</div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Alert Stok Tipis</span>
                <span class="p-2 rounded-3 bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-danger mb-1">{{ $stats['low_stock_products'] }}</h4>
            <div class="small text-muted">Perlu restock segera</div>
        </div>
    </div>
</div>

{{-- Rekap Status Pesanan --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Pesanan Baru</span>
                <span class="p-2 rounded-3 bg-primary-subtle text-primary"><i class="bi bi-bag-plus fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-1">{{ $stats['orders_new'] }}</h4>
            <div class="small text-muted">Pending & baru dibayar</div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Pesanan Diproses</span>
                <span class="p-2 rounded-3 bg-warning-subtle text-warning-emphasis"><i class="bi bi-gear-wide-connected fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-1">{{ $stats['orders_processed'] }}</h4>
            <div class="small text-muted">Processing & siap kirim</div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Pesanan Dikirim</span>
                <span class="p-2 rounded-3 bg-info-subtle text-info-emphasis"><i class="bi bi-send-check fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-1">{{ $stats['orders_shipped'] }}</h4>
            <div class="small text-muted">Dalam perjalanan / sampai</div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Pesanan Selesai</span>
                <span class="p-2 rounded-3 bg-success-subtle text-success"><i class="bi bi-check2-circle fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-success mb-1">{{ $stats['orders_completed'] }}</h4>
            <div class="small text-muted">Tuntas (completed)</div>
        </div>
    </div>
</div>

{{-- Jumlah Pengguna per Role --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Total Pelanggan</span>
                <span class="p-2 rounded-3 bg-primary-subtle text-primary"><i class="bi bi-people fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-0">{{ $stats['total_customers'] }}</h4>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Total Anggota</span>
                <span class="p-2 rounded-3 bg-warning-subtle text-warning-emphasis"><i class="bi bi-shop fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-0">{{ $stats['total_members'] }}</h4>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-stat">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-secondary small fw-semibold">Total Kurir</span>
                <span class="p-2 rounded-3 bg-info-subtle text-info-emphasis"><i class="bi bi-bicycle fs-5"></i></span>
            </div>
            <h4 class="fw-bold text-dark mb-0">{{ $stats['total_couriers'] }}</h4>
        </div>
    </div>
</div>

{{-- Grafik Penjualan: Harian / Mingguan / Bulanan --}}
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-graph-up me-2 text-primary"></i>Grafik Penjualan</h6>
        <span class="badge bg-light text-secondary border small">Pesanan terverifikasi (paid s/d completed)</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-4">
                <h6 class="small fw-bold text-dark mb-1">Harian (14 Hari Terakhir)</h6>
                <div style="position: relative; height: 240px;">
                    <canvas id="chartDaily"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <h6 class="small fw-bold text-dark mb-1">Mingguan (8 Minggu Terakhir)</h6>
                <div style="position: relative; height: 240px;">
                    <canvas id="chartWeekly"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <h6 class="small fw-bold text-dark mb-1">Bulanan (12 Bulan Terakhir)</h6>
                <div style="position: relative; height: 240px;">
                    <canvas id="chartMonthly"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Pesanan Terbaru --}}
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-clock-history me-2 text-primary"></i>Pesanan Terbaru Toko</h6>
                <span class="badge bg-light text-secondary border">5 Terakhir</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>No. Order</th>
                            <th>Pelanggan</th>
                            <th>Total</th>
                            <th>Status Order</th>
                            <th>Status Bayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                            <tr>
                                <td class="fw-semibold text-primary">{{ $order->order_number }}</td>
                                <td>
                                    <div class="fw-bold text-dark">{{ $order->user->name }}</div>
                                    <div class="text-muted small">{{ $order->user->phone }}</div>
                                </td>
                                <td class="fw-bold">Rp {{ number_format($order->grand_total, 0, ',', '.') }}</td>
                                <td>
                                    @include('partials.order-status', ['status' => $order->status])
                                </td>
                                <td>
                                    @if($order->payment)
                                        @include('partials.payment-status', ['status' => $order->payment->status])
                                    @else
                                        <span class="badge badge-soft bg-secondary-subtle text-secondary border border-secondary-subtle">Belum Dibuat</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada pesanan masuk.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Alert Stok Menipis --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-bell me-2 text-danger"></i>Peringatan Stok Menipis</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush small">
                    @forelse($lowStockItems as $item)
                        <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <div>
                                <div class="fw-semibold text-dark">{{ $item->name }}</div>
                                <div class="text-muted small">SKU: {{ $item->sku }}</div>
                            </div>
                            <span class="badge bg-danger rounded-pill">Sisa: {{ $item->stock }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center py-4 text-muted">
                            <i class="bi bi-check-circle text-success fs-4 d-block mb-1"></i>
                            Seluruh stok produk dalam kondisi aman.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- Aktivitas Terakhir (Audit Log) --}}
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-clock-history me-2 text-secondary"></i>Aktivitas Terakhir (Audit Log)</h5>
        <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-sm btn-outline-secondary fw-semibold">
            <i class="bi bi-arrow-right-circle me-1"></i> Lihat Semua
        </a>
    </div>
    <div class="card-body p-0">
        <ul class="list-group list-group-flush small">
            @forelse($recentActivities as $log)
                <li class="list-group-item d-flex justify-content-between align-items-center py-3 gap-3 flex-wrap">
                    <div>
                        <div class="fw-semibold text-dark">
                            {{ $log->user?->name ?? 'Sistem' }}
                            <span class="badge role-badge-{{ $log->role }} ms-1">{{ $log->role ?? '-' }}</span>
                        </div>
                        <div class="text-muted">{{ $log->summary ?: $log->activity_type }}</div>
                    </div>
                    <span class="text-muted text-nowrap">{{ $log->created_at->diffForHumans() }}</span>
                </li>
            @empty
                <li class="list-group-item text-center py-4 text-muted">
                    <i class="bi bi-clock-history fs-4 d-block mb-1"></i>
                    Belum ada aktivitas tercatat.
                </li>
            @endforelse
        </ul>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script>
    const salesData = @json($chartData);
    const rupiah = new Intl.NumberFormat('id-ID');
    const salesCharts = {};

    // Warna sumbu grafik mengikuti tema aktif (terang/gelap).
    function atkChartColors() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            tick: dark ? '#94a3b8' : '#475569',
            grid: dark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(100, 116, 139, 0.15)',
        };
    }

    function renderSalesChart(canvasId, series, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        const colors = atkChartColors();

        salesCharts[canvasId] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: series.labels,
                datasets: [{
                    label: 'Penjualan (Rp)',
                    data: series.values,
                    backgroundColor: color,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => 'Rp ' + rupiah.format(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: { color: colors.tick },
                        grid: { color: colors.grid },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: colors.tick, callback: (v) => rupiah.format(v) },
                        grid: { color: colors.grid },
                    },
                },
            },
        });
    }

    // Perbarui warna grafik saat tema berubah.
    window.addEventListener('atk:theme-changed', function () {
        const colors = atkChartColors();
        Object.values(salesCharts).forEach(function (chart) {
            ['x', 'y'].forEach(function (axis) {
                chart.options.scales[axis].ticks.color = colors.tick;
                chart.options.scales[axis].grid.color = colors.grid;
            });
            chart.update();
        });
    });

    renderSalesChart('chartDaily', salesData.daily, '#0d6efd');
    renderSalesChart('chartWeekly', salesData.weekly, '#198754');
    renderSalesChart('chartMonthly', salesData.monthly, '#6f42c1');
</script>
@endpush

