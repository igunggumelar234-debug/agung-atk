@extends('layouts.dashboard')

@section('title', 'Pesanan')
@section('page-title', 'Semua Pesanan')

@section('content')
{{-- Filter & pencarian --}}
<div class="card card-custom mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.orders.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label for="q" class="form-label">Cari</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="q" value="{{ $search }}" class="form-control"
                           placeholder="No. pesanan / nama pelanggan / email">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">Semua Status</option>
                    @foreach (['pending' => 'Belum Dibayar', 'paid' => 'Sudah Dibayar', 'processing' => 'Diproses', 'ready_to_ship' => 'Siap Kirim', 'shipping' => 'Dikirim', 'delivered' => 'Terkirim', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>
                            {{ $label }} ({{ $counts[$value] ?? 0 }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-3 d-grid">
                <button type="submit" class="btn btn-primary-atk">
                    <i class="bi bi-funnel me-1"></i> Terapkan
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt text-primary me-1"></i> Daftar Pesanan</span>
        <span class="badge bg-light text-dark border">{{ $orders->total() }} pesanan</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>No. Pesanan</th>
                    <th>Pelanggan</th>
                    <th class="text-center">Item</th>
                    <th class="text-end">Total</th>
                    <th class="text-center">Pembayaran</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Tanggal</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $order->order_number }}</span>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $order->user->name ?? '-' }}</div>
                            <div class="small text-muted">{{ $order->user->email ?? '-' }}</div>
                        </td>
                        <td class="text-center">{{ $order->orderDetails_count }}</td>
                        <td class="text-end fw-semibold">Rp {{ number_format($order->grand_total, 0, ',', '.') }}</td>
                        <td class="text-center">
                            @if ($order->payment)
                                @include('partials.payment-status', ['status' => $order->payment->status])
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Belum Dibuat</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @include('partials.order-status', ['status' => $order->status])
                        </td>
                        <td class="text-center small text-muted">
                            {{ $order->created_at->translatedFormat('d/m/Y') }}<br>
                            {{ $order->created_at->translatedFormat('H:i') }}
                        </td>
                        <td class="text-center">
                            <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-sm btn-outline-primary"
                               title="Lihat detail">
                                <i class="bi bi-eye"></i><span class="visually-hidden">Detail</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state py-3">
                                <i class="bi bi-inbox empty-state-icon"></i>
                                <div class="empty-state-title">Tidak ada pesanan ditemukan</div>
                                <p class="empty-state-text">
                                    {{ $search || $status ? 'Coba ubah kata kunci atau filter status.' : 'Belum ada pesanan masuk ke toko.' }}
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $orders->links() }}
</div>
@endsection
