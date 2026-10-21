{{-- Badge status pembayaran (konsisten untuk seluruh aplikasi).
     Pakai: @include('partials.payment-status', ['status' => $payment->status]) --}}
@php
    $paymentBadgeStyles = [
        'verified' => ['Terverifikasi', 'bg-success-subtle text-success border border-success-subtle'],
        'rejected' => ['Ditolak', 'bg-danger-subtle text-danger border border-danger-subtle'],
        'pending' => ['Menunggu Pembayaran', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'],
    ];
@endphp
<span class="badge badge-soft {{ $paymentBadgeStyles[$status][1] ?? 'bg-secondary-subtle text-secondary border border-secondary-subtle' }}">
    {{ $paymentBadgeStyles[$status][0] ?? strtoupper($status) }}
</span>
