@extends('layouts.dashboard')

@section('title', 'Detail Pesanan ' . $order->order_number)
@section('page-title', 'Detail Pesanan: ' . $order->order_number)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Pesanan
    </a>
    @include('partials.order-status', ['status' => $order->status])
</div>

@if ($order->status === 'cancelled')
    <div class="alert alert-danger small">
        <i class="bi bi-x-octagon me-1"></i>
        Pesanan dibatalkan{{ $order->cancelled_at ? ' pada ' . $order->cancelled_at->translatedFormat('d M Y H:i') : '' }}.
        @if ($order->cancelled_reason)
            Alasan: {{ $order->cancelled_reason }}
        @endif
    </div>
@endif

@php
    $cancellable = in_array($order->status, \App\Services\OrderCancellationService::CANCELLABLE_BY_ADMIN, true);
@endphp

@if ($cancellable)
    {{-- Pembatalan pesanan: stok otomatis dikembalikan ke katalog --}}
    <div class="card card-custom border-danger mb-4">
        <div class="card-header text-danger"><i class="bi bi-x-octagon me-1"></i> Batalkan Pesanan</div>
        <div class="card-body">
            <p class="small text-muted mb-2">
                Membatalkan pesanan akan <strong>mengembalikan seluruh stok</strong> item ke katalog,
                menolak pembayaran yang masih pending, dan mencatat alasan pada jejak audit.
            </p>
            <form action="{{ route('admin.orders.cancel', $order) }}" method="POST"
                  onsubmit="return confirm('Batalkan pesanan #{{ $order->order_number }} dan kembalikan stok?');">
                @csrf
                @method('PATCH')
                <div class="mb-3">
                    <label for="cancelled_reason" class="form-label small fw-semibold">Alasan Pembatalan <span class="text-danger">*</span></label>
                    <textarea name="cancelled_reason" id="cancelled_reason" rows="2" maxlength="500" required
                              class="form-control form-control-sm @error('cancelled_reason') is-invalid @enderror"
                              placeholder="Mis. Stok habis / permintaan pelanggan">{{ old('cancelled_reason') }}</textarea>
                    @error('cancelled_reason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="bi bi-x-octagon me-1"></i> Batalkan Pesanan &amp; Kembalikan Stok
                </button>
            </form>
        </div>
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-7">
        {{-- Item pesanan --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-bag-check text-primary me-1"></i> Produk Dipesan</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th class="text-center">Harga</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->orderDetails as $detail)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $detail->product->name ?? 'Produk dihapus' }}</div>
                                    @if ($detail->product)
                                        <span class="small text-muted">Kategori: {{ $detail->product->category->name ?? '-' }}</span>
                                    @endif
                                </td>
                                <td class="text-center small">Rp {{ number_format($detail->price, 0, ',', '.') }}</td>
                                <td class="text-center">{{ $detail->quantity }}</td>
                                <td class="text-end fw-semibold">Rp {{ number_format($detail->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state py-3">
                                        <i class="bi bi-box-seam empty-state-icon"></i>
                                        <div class="empty-state-title">Tidak ada produk</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end text-muted small border-0">Subtotal Produk</td>
                            <td class="text-end border-0">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end text-muted small border-0">Ongkos Kirim</td>
                            <td class="text-end border-0">Rp {{ number_format($order->shipping_cost, 0, ',', '.') }}</td>
                        </tr>
                        @if ((float) $order->discount_amount > 0)
                            <tr>
                                <td colspan="3" class="text-end text-muted small border-0">Diskon</td>
                                <td class="text-end border-0 text-danger">-Rp {{ number_format($order->discount_amount, 0, ',', '.') }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td colspan="3" class="text-end fw-bold border-0">Total</td>
                            <td class="text-end fw-bold text-primary border-0">Rp {{ number_format($order->grand_total, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        @if ($order->notes)
            <div class="card card-custom">
                <div class="card-header"><i class="bi bi-chat-left-text text-primary me-1"></i> Catatan Pelanggan</div>
                <div class="card-body small">{{ $order->notes }}</div>
            </div>
        @endif
    </div>

    <div class="col-12 col-lg-5">
        {{-- Pelanggan --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-person text-primary me-1"></i> Pelanggan</div>
            <div class="card-body small">
                <div class="d-flex justify-content-between py-1">
                    <span class="text-muted">Nama</span>
                    <span class="fw-semibold">{{ $order->user->name ?? '-' }}</span>
                </div>
                <div class="d-flex justify-content-between py-1">
                    <span class="text-muted">Email</span>
                    <span class="fw-semibold">{{ $order->user->email ?? '-' }}</span>
                </div>
                <div class="d-flex justify-content-between py-1">
                    <span class="text-muted">Telepon</span>
                    <span class="fw-semibold">{{ $order->user->phone ?: '-' }}</span>
                </div>
                <div class="d-flex justify-content-between py-1">
                    <span class="text-muted">Dipesan</span>
                    <span class="fw-semibold">{{ $order->created_at->translatedFormat('d M Y H:i') }}</span>
                </div>
                @if ($order->completed_at)
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Selesai</span>
                        <span class="fw-semibold">{{ $order->completed_at->translatedFormat('d M Y H:i') }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Pembayaran --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-wallet2 text-primary me-1"></i> Pembayaran</div>
            <div class="card-body small">
                @if ($order->payment)
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">No. Pembayaran</span>
                        <span class="fw-semibold">{{ $order->payment->payment_number }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <span class="text-muted">Status</span>
                        @include('partials.payment-status', ['status' => $order->payment->status])
                    </div>
                    <a href="{{ route('admin.payments.show', $order->payment) }}" class="btn btn-outline-primary btn-sm w-100 mt-2">
                        <i class="bi bi-wallet2 me-1"></i> Kelola Pembayaran
                    </a>
                @else
                    <p class="text-muted mb-0">Belum ada pembayaran untuk pesanan ini.</p>
                @endif
            </div>
        </div>

        {{-- Pengiriman --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-truck text-primary me-1"></i> Pengiriman</div>
            <div class="card-body small">
                @if ($order->shipment)
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">No. Resi</span>
                        <span class="fw-semibold">{{ $order->shipment->tracking_number }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <span class="text-muted">Status</span>
                        @include('shipments.partials.status-badge', ['status' => $order->shipment->status])
                    </div>
                    @if ($order->shipment->courier)
                        <div class="d-flex justify-content-between py-1">
                            <span class="text-muted">Kurir</span>
                            <span class="fw-semibold">{{ $order->shipment->courier->name }}</span>
                        </div>
                    @endif
                    <a href="{{ route('admin.shipments.index') }}" class="btn btn-outline-primary btn-sm w-100 mt-2">
                        <i class="bi bi-truck me-1"></i> Kelola Pengiriman
                    </a>
                @else
                    <p class="text-muted mb-1">Belum ada pengiriman dibuat untuk pesanan ini.</p>
                    <a href="{{ route('admin.shipments.index') }}" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-truck me-1"></i> Buat Pengiriman
                    </a>
                @endif
            </div>
        </div>

        {{-- Alamat --}}
        <div class="card card-custom">
            <div class="card-header"><i class="bi bi-geo-alt text-primary me-1"></i> Alamat Pengiriman</div>
            <div class="card-body small">
                @if ($order->address)
                    <div class="fw-semibold">{{ $order->address->recipient_name }}
                        <span class="badge bg-light text-dark border ms-1">{{ $order->address->label }}</span>
                    </div>
                    <div class="text-muted mb-1">{{ $order->address->phone }}</div>
                    <div>{{ $order->address->full_address }}</div>
                    <div>{{ $order->address->district }}, {{ $order->address->city }}
                        {{ $order->address->postal_code ? '&middot; ' . $order->address->postal_code : '' }}</div>
                @else
                    <p class="text-muted mb-0">Alamat tidak tersedia.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
