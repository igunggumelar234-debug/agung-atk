{{-- Badge status pesanan (konsisten untuk seluruh aplikasi).
     Pakai: @include('partials.order-status', ['status' => $order->status]) --}}
@php
    $orderBadgeStyles = [
        'paid' => ['Sudah Dibayar', 'bg-success-subtle text-success border border-success-subtle'],
        'processing' => ['Diproses', 'bg-primary-subtle text-primary border border-primary-subtle'],
        'ready_to_ship' => ['Siap Kirim', 'bg-info-subtle text-info border border-info-subtle'],
        'shipping' => ['Dikirim', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'],
        'delivered' => ['Terkirim', 'bg-secondary-subtle text-secondary border border-secondary-subtle'],
        'completed' => ['Selesai', 'bg-dark text-white border border-dark'],
        'pending' => ['Belum Dibayar', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'],
        'cancelled' => ['Dibatalkan', 'bg-danger-subtle text-danger border border-danger-subtle'],
    ];
@endphp
<span class="badge badge-soft {{ $orderBadgeStyles[$status][1] ?? 'bg-secondary-subtle text-secondary border border-secondary-subtle' }}">
    {{ $orderBadgeStyles[$status][0] ?? strtoupper($status) }}
</span>
