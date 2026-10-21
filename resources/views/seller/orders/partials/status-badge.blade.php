@php
    $badgeStyles = [
        'paid' => ['Sudah Dibayar', 'bg-success-subtle text-success'],
        'processing' => ['Diproses', 'bg-primary-subtle text-primary'],
        'ready_to_ship' => ['Siap Kirim', 'bg-info-subtle text-info'],
        'shipping' => ['Dikirim', 'bg-warning-subtle text-warning-emphasis'],
        'delivered' => ['Terkirim', 'bg-secondary-subtle text-secondary'],
        'completed' => ['Selesai', 'bg-dark text-white'],
        'pending' => ['Belum Dibayar', 'bg-warning-subtle text-warning-emphasis'],
        'cancelled' => ['Dibatalkan', 'bg-danger-subtle text-danger'],
    ];
@endphp
<span class="badge {{ $badgeStyles[$status][1] ?? 'bg-secondary' }} px-2 py-1">
    {{ $badgeStyles[$status][0] ?? strtoupper($status) }}
</span>
