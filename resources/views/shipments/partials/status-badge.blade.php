@php
    $badgeStyles = [
        'waiting' => ['Menunggu Diambil', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'],
        'picked_up' => ['Diambil Kurir', 'bg-info-subtle text-info-emphasis border border-info-subtle'],
        'shipping' => ['Dalam Perjalanan', 'bg-primary-subtle text-primary border border-primary-subtle'],
        'delivered' => ['Terkirim', 'bg-success-subtle text-success border border-success-subtle'],
    ];
@endphp
<span class="badge {{ $badgeStyles[$status][1] ?? 'bg-secondary' }} px-2 py-1">
    {{ $badgeStyles[$status][0] ?? strtoupper($status) }}
</span>
