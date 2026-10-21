@extends('layouts.dashboard')

@section('title', 'Detail Pengiriman ' . $shipment->tracking_number)
@section('page-title', 'Detail Pengiriman')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('courier.shipments.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
    </a>
    @include('shipments.partials.status-badge', ['status' => $shipment->status])
</div>

@error('shipment')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror

{{-- Timeline Status --}}
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body py-3">
        @php
            $steps = ['waiting', 'picked_up', 'shipping', 'delivered'];
            $currentIdx = array_search($shipment->status, $steps, true);
        @endphp
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @foreach($steps as $step)
                @php
                    $done = $currentIdx !== false && array_search($step, $steps, true) <= $currentIdx;
                @endphp
                <span class="badge px-3 py-2 {{ $done ? 'bg-primary' : 'bg-light text-secondary border' }}">
                    @if($done)<i class="bi bi-check-circle me-1"></i>@endif
                    {{ $statusLabels[$step] ?? strtoupper($step) }}
                </span>
                @if(!$loop->last)
                    <i class="bi bi-arrow-right text-secondary"></i>
                @endif
            @endforeach
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Kolom Info --}}
    <div class="col-12 col-lg-7">
        {{-- Penerima & Alamat --}}
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-geo-alt-fill me-2 text-danger"></i>Penerima & Alamat</h6>
            </div>
            <div class="card-body p-4">
                <table class="table table-borderless mb-0 small">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width: 35%;">Penerima</td>
                            <td class="fw-semibold text-dark">
                                {{ $shipment->order?->address?->recipient_name ?? $shipment->recipient_name ?? '-' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Telepon</td>
                            <td>
                                @if($shipment->order?->address?->phone)
                                    <a href="tel:{{ $shipment->order->address->phone }}"
                                       class="fw-bold text-decoration-none d-inline-flex align-items-center gap-1">
                                        <i class="bi bi-telephone-fill text-success"></i>
                                        {{ $shipment->order->address->phone }}
                                    </a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted align-top">Alamat</td>
                            <td class="text-dark">
                                {{ $shipment->order?->address?->full_address ?? '-' }}
                                <div class="text-muted">
                                    {{ $shipment->order?->address?->district ?? '-' }},
                                    {{ $shipment->order?->address?->city ?? '-' }}
                                    {{ $shipment->order?->address?->postal_code ?? '' }}
                                </div>
                                @if($shipment->order?->address?->notes)
                                    <div class="text-muted mt-1"><em>Catatan: {{ $shipment->order->address->notes }}</em></div>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

                @if($shipment->order?->address)
                    <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode(trim($shipment->order->address->full_address . ', ' . $shipment->order->address->city)) }}"
                       target="_blank" rel="noopener noreferrer"
                       class="btn btn-outline-primary btn-sm w-100 mt-3 fw-semibold">
                        <i class="bi bi-map me-1"></i> Buka di Google Maps
                    </a>
                @endif
            </div>
        </div>

        {{-- Detail Pesanan (read-only) --}}
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-box-seam me-2 text-primary"></i>Detail Pesanan</h6>
            </div>
            <div class="card-body p-4 small">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width: 35%;">No. Pesanan</td>
                            <td class="fw-semibold text-dark"><code>{{ $shipment->order?->order_number ?? '-' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">No. Resi</td>
                            <td class="fw-semibold text-dark"><code>{{ $shipment->tracking_number }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tanggal Pesanan</td>
                            <td class="text-dark">{{ $shipment->order?->created_at?->format('d M Y H:i') ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted align-top">Item</td>
                            <td class="text-dark">
                                @forelse($shipment->order?->orderDetails ?? [] as $detail)
                                    <div>{{ $detail->quantity }} × {{ $detail->product_name }}</div>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total (dibayar)</td>
                            <td class="fw-bold text-dark">Rp {{ number_format((float) ($shipment->order?->grand_total ?? 0), 0, ',', '.') }}</td>
                        </tr>
                        @if($shipment->shipped_at)
                            <tr>
                                <td class="text-muted">Diambil Pada</td>
                                <td class="text-dark">{{ $shipment->shipped_at->format('d M Y H:i') }}</td>
                            </tr>
                        @endif
                        @if($shipment->delivered_at)
                            <tr>
                                <td class="text-muted">Terkirim Pada</td>
                                <td class="text-dark">{{ $shipment->delivered_at->format('d M Y H:i') }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Kolom Aksi --}}
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-lightning-charge-fill me-2 text-warning"></i>Perbarui Status</h6>
            </div>
            <div class="card-body p-4">
                @php
                    $nextStatus = \App\Support\ShipmentStatus::next($shipment->status);
                    $actionLabels = [
                        'picked_up' => ['Saya Sudah Mengambil Barang', 'btn-warning', 'bi-box-seam'],
                        'shipping' => ['Mulai Diantar', 'btn-primary', 'bi-send'],
                        'delivered' => ['Pesanan Diterima Penerima', 'btn-success', 'bi-check-circle'],
                    ];
                @endphp

                @if($nextStatus)
                    <p class="small text-muted mb-3">
                        Status saat ini: <strong class="text-dark">{{ $statusLabels[$shipment->status] ?? strtoupper($shipment->status) }}</strong>.
                        Lanjutkan ke
                        <strong class="text-dark">{{ $statusLabels[$nextStatus] ?? strtoupper($nextStatus) }}</strong>.
                    </p>

                    <form action="{{ route('courier.shipments.status', $shipment) }}" method="POST"
                          onsubmit="return confirm('Ubah status pengiriman {{ $shipment->tracking_number }} menjadi {{ strtoupper($nextStatus) }}?');">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ $nextStatus }}">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn {{ $actionLabels[$nextStatus][1] }} btn-lg fw-semibold">
                                <i class="bi {{ $actionLabels[$nextStatus][2] }} me-1"></i>
                                {{ $actionLabels[$nextStatus][0] }}
                            </button>
                        </div>
                    </form>
                @else
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Pengiriman <strong>{{ $shipment->tracking_number }}</strong> sudah selesai
                        ({{ $shipment->delivered_at?->format('d M Y H:i') }}). Tidak ada tindakan lanjutan.
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
