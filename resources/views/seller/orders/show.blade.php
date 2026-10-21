@extends('layouts.dashboard')

@section('title', 'Detail Pesanan ' . $order->order_number)
@section('page-title', 'Detail Pesanan: ' . $order->order_number)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('seller.orders.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Pesanan
    </a>
    @include('seller.orders.partials.status-badge', ['status' => $order->status])
</div>

@error('order')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror

{{-- Alur Status --}}
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body py-3">
        @php
            $steps = [
                'paid' => 'Sudah Dibayar',
                'processing' => 'Diproses',
                'ready_to_ship' => 'Siap Kirim',
            ];
            $currentIdx = array_search($order->status, array_keys($steps), true);
            $futureIdx = $currentIdx === false ? null : $currentIdx;
        @endphp
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @foreach($steps as $key => $label)
                @php
                    $idx = array_search($key, array_keys($steps), true);
                    $done = $futureIdx !== null && $idx <= $futureIdx;
                @endphp
                <span class="badge px-3 py-2 {{ $done ? 'bg-primary' : 'bg-light text-secondary border' }}">
                    @if($done)<i class="bi bi-check-circle me-1"></i>@endif{{ $label }}
                </span>
                @if(!$loop->last)
                    <i class="bi bi-arrow-right text-secondary"></i>
                @endif
            @endforeach
            @if($futureIdx === null)
                <span class="text-secondary small ms-2">
                    (di luar alur pemrosesan — tahap pengiriman)
                </span>
            @endif
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        {{-- Rincian Item (read-only) --}}
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-box-seam me-2 text-primary"></i>Rincian Item</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Produk</th>
                            <th class="text-end">Harga Satuan</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($order->orderDetails as $detail)
                            <tr>
                                <td class="fw-semibold text-dark">{{ $detail->product_name }}</td>
                                <td class="text-end">Rp {{ number_format((float) $detail->price, 0, ',', '.') }}</td>
                                <td class="text-center">{{ $detail->quantity }}</td>
                                <td class="text-end fw-bold">Rp {{ number_format((float) $detail->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="3" class="text-end text-secondary">Subtotal Produk</td>
                            <td class="text-end fw-semibold">Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end text-secondary">Ongkos Kirim</td>
                            <td class="text-end">Rp {{ number_format((float) $order->shipping_cost, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end text-secondary">Diskon</td>
                            <td class="text-end">Rp {{ number_format((float) $order->discount_amount, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end fw-bold text-dark">Grand Total</td>
                            <td class="text-end fw-bold text-primary fs-6">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Pelanggan & Alamat Pengiriman --}}
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-geo-alt me-2 text-danger"></i>Pelanggan & Alamat Pengiriman</h6>
            </div>
            <div class="card-body p-4 small">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width: 35%;">Nama Pelanggan</td>
                            <td class="fw-semibold text-dark">{{ $order->user?->name ?? '-' }}</td>
                        </tr>
                        @if($order->address)
                            <tr>
                                <td class="text-secondary">Penerima</td>
                                <td class="text-dark">{{ $order->address->recipient_name }}</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Telepon</td>
                                <td class="text-dark">{{ $order->address->phone }}</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Alamat</td>
                                <td class="text-dark">
                                    {{ $order->address->full_address }}
                                    <div class="text-muted">
                                        {{ $order->address->district }}, {{ $order->address->city }} {{ $order->address->postal_code }}
                                    </div>
                                </td>
                            </tr>
                            @if($order->address->notes)
                                <tr>
                                    <td class="text-secondary align-top">Catatan Alamat</td>
                                    <td class="text-dark">{{ $order->address->notes }}</td>
                                </tr>
                            @endif
                        @else
                            <tr>
                                <td colspan="2" class="text-secondary">Alamat tidak tersedia.</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        {{-- Info Pesanan --}}
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-receipt me-2 text-primary"></i>Info Pesanan</h6>
            </div>
            <div class="card-body p-4 small">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width: 40%;">No. Pesanan</td>
                            <td class="fw-semibold text-dark"><code>{{ $order->order_number }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Tanggal</td>
                            <td class="text-dark">{{ $order->created_at->format('d M Y H:i') }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Status</td>
                            <td>@include('seller.orders.partials.status-badge', ['status' => $order->status])</td>
                        </tr>
                        @if($order->notes)
                            <tr>
                                <td class="text-secondary align-top">Catatan</td>
                                <td class="text-dark">{{ $order->notes }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pembayaran (read-only) --}}
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-wallet2 me-2 text-success"></i>Pembayaran</h6>
            </div>
            <div class="card-body p-4 small">
                @if($order->payment)
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td class="text-secondary" style="width: 40%;">No. Pembayaran</td>
                                <td class="fw-semibold text-dark"><code>{{ $order->payment->payment_number }}</code></td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Metode</td>
                                <td class="text-dark">{{ strtoupper($order->payment->method) }}</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Jumlah</td>
                                <td class="fw-semibold text-dark">Rp {{ number_format((float) $order->payment->amount, 0, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Status</td>
                                <td>
                                    @include('partials.payment-status', ['status' => $order->payment->status])
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="text-muted mt-2" style="font-size: 0.75rem;">
                        <i class="bi bi-lock me-1"></i> Informasi pembayaran hanya dapat dilihat — diverifikasi oleh admin.
                    </div>
                @else
                    <div class="text-secondary">Tidak ada data pembayaran.</div>
                @endif
            </div>
        </div>

        {{-- Aksi Proses Pesanan --}}
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-lightning me-2 text-warning"></i>Aksi Pemrosesan</h6>
            </div>
            <div class="card-body p-4">
                @if($order->status === 'paid')
                    <p class="small text-muted mb-3">
                        Pesanan sudah dibayar. Mulai penyiapan barang dengan mengubah status menjadi <strong>processing</strong>.
                    </p>
                    <form action="{{ route('seller.orders.processing', $order) }}" method="POST"
                          onsubmit="return confirm('Proses pesanan {{ $order->order_number }} ke status processing?');">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-success w-100 fw-semibold">
                            <i class="bi bi-gear me-1"></i> Proses Pesanan (→ Processing)
                        </button>
                    </form>
                @elseif($order->status === 'processing')
                    <p class="small text-muted mb-3">
                        Penyiapan barang selesai. Tandai pesanan <strong>ready_to_ship</strong> agar siap diambil kurir.
                    </p>
                    <form action="{{ route('seller.orders.ready-to-ship', $order) }}" method="POST"
                          onsubmit="return confirm('Tandai pesanan {{ $order->order_number }} siap dikirim?');">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="bi bi-truck me-1"></i> Tandai Siap Kirim (→ Ready to Ship)
                        </button>
                    </form>
                @elseif($order->status === 'ready_to_ship')
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Pesanan siap diambil kurir. Menunggu tahap pengiriman.
                    </div>
                @else
                    <div class="alert alert-secondary small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Pesanan berada di tahap pengiriman ({{ strtoupper($order->status) }}) — tidak ada aksi pemrosesan.
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
