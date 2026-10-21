@extends('layouts.dashboard')

@section('title', 'Detail Pesanan ' . $order->order_number)
@section('page-title', 'Detail Pesanan: ' . $order->order_number)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('orders.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Pesanan Saya
    </a>
    @include('partials.order-status', ['status' => $order->status])
</div>

{{-- Alur status pesanan --}}
<div class="card card-custom mb-4">
    <div class="card-body py-3">
        <div class="small text-muted fw-semibold mb-2">Status Pesanan</div>
        @php
            $flow = [
                'pending' => 'Menunggu Pembayaran',
                'paid' => 'Sudah Dibayar',
                'processing' => 'Diproses',
                'ready_to_ship' => 'Siap Kirim',
                'shipping' => 'Dikirim',
                'delivered' => 'Terkirim',
                'completed' => 'Selesai',
            ];
            $flowKeys = array_keys($flow);
            $currentIdx = array_search($order->status, $flowKeys, true);
        @endphp

        @if ($order->status === 'cancelled')
            <div class="alert alert-danger py-2 small mb-0">
                <i class="bi bi-x-octagon me-1"></i>
                Pesanan ini dibatalkan
                {{ $order->cancelled_at ? 'pada ' . $order->cancelled_at->translatedFormat('d M Y H:i') : '' }}.
                @if ($order->cancelled_reason)
                    <br>Alasan: {{ $order->cancelled_reason }}
                @endif
            </div>
        @else
            <div class="status-steps">
                @foreach ($flow as $key => $label)
                    <span class="status-step {{ $currentIdx !== false && $loop->index < $currentIdx ? 'is-done' : '' }} {{ $key === $order->status ? 'is-current' : '' }}">
                        {{ $label }}
                    </span>
                @endforeach
            </div>
            @if ($order->completed_at)
                <div class="small text-success mt-2 mb-0">
                    <i class="bi bi-check-circle me-1"></i>Selesai pada {{ $order->completed_at->translatedFormat('d M Y H:i') }}
                </div>
            @endif
        @endif
    </div>
</div>

@if ($order->status === 'pending')
    {{-- Batalkan pesanan sebelum bayar: stok otomatis dikembalikan ke katalog --}}
    <div class="card card-custom border-danger mb-4">
        <div class="card-header text-danger"><i class="bi bi-x-octagon me-1"></i> Batalkan Pesanan</div>
        <div class="card-body">
            <p class="small text-muted mb-2">
                Pesanan belum dibayar. Jika dibatalkan, seluruh stok item akan dikembalikan ke katalog.
            </p>
            <form action="{{ route('orders.cancel', $order) }}" method="POST"
                  onsubmit="return confirm('Batalkan pesanan #{{ $order->order_number }}?');">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-octagon me-1"></i> Batalkan Pesanan Ini
                </button>
            </form>
        </div>
    </div>
@endif

<div class="row g-4">
    {{-- Item pesanan --}}
    <div class="col-12 col-lg-7">
        <div class="card card-custom mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-bag-check text-primary"></i> Produk Dipesan
            </div>
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
                                        <a href="{{ route('products.show', $detail->product) }}" class="small text-decoration-none">
                                            Lihat produk <i class="bi bi-arrow-right"></i>
                                        </a>
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
                <div class="card-header"><i class="bi bi-chat-left-text text-primary me-1"></i> Catatan Pesanan</div>
                <div class="card-body small">{{ $order->notes }}</div>
            </div>
        @endif
    </div>

    {{-- Ringkasan kanan --}}
    <div class="col-12 col-lg-5">
        {{-- Pembayaran --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-wallet2 text-primary me-1"></i> Pembayaran</div>
            <div class="card-body">
                @if ($order->payment)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Nomor Pembayaran</span>
                        <span class="small fw-semibold">{{ $order->payment->payment_number }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted">Status</span>
                        @include('partials.payment-status', ['status' => $order->payment->status])
                    </div>
                    @if ($order->payment->status === 'rejected' && $order->payment->rejection_reason)
                        <div class="alert alert-danger small py-2 mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>{{ $order->payment->rejection_reason }}
                        </div>
                    @endif
                    <a href="{{ route('payments.show', $order->payment) }}" class="btn btn-primary-atk w-100">
                        <i class="bi bi-wallet2 me-1"></i>
                        {{ $order->payment->status === 'pending' ? 'Unggah Bukti Pembayaran' : 'Lihat Pembayaran' }}
                    </a>
                @else
                    <p class="small text-muted mb-0">Belum ada tagihan pembayaran untuk pesanan ini.</p>
                @endif
            </div>
        </div>

        {{-- Pengiriman --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-truck text-primary me-1"></i> Pengiriman</div>
            <div class="card-body">
                @if ($order->shipment)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">No. Resi</span>
                        <span class="small fw-semibold">{{ $order->shipment->tracking_number }}</span>
                    </div>
                    @if ($order->shipment->courier)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">Kurir</span>
                            <span class="small fw-semibold">{{ $order->shipment->courier->name }}</span>
                        </div>
                    @endif
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Status Kirim</span>
                        @include('shipments.partials.status-badge', ['status' => $order->shipment->status])
                    </div>
                    @if ($order->shipment->delivered_at)
                        <div class="small text-success mb-2">
                            <i class="bi bi-check-circle me-1"></i>Diterima {{ $order->shipment->delivered_at->translatedFormat('d M Y H:i') }}
                        </div>
                    @endif
                @else
                    <p class="small text-muted mb-0">
                        Pengiriman belum dibuat. Kurir akan ditugaskan setelah pesanan disiapkan penjual.
                    </p>
                @endif
            </div>
        </div>

        {{-- Alamat tujuan --}}
        <div class="card card-custom mb-4">
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

        <a href="{{ route('products.index') }}" class="btn btn-outline-primary w-100">
            <i class="bi bi-bag me-1"></i> Belanja Lagi
        </a>
    </div>
</div>
@endsection
