<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PaymentController extends Controller
{
    /**
     * Detail pembayaran untuk pelanggan pemilik pesanan.
     */
    public function show(Payment $payment): View
    {
        abort_unless($payment->order->user_id === auth()->id(), 404);

        $payment->load(['order.address', 'order.orderDetails', 'verifier']);

        return view('payments.show', [
            'payment' => $payment,
        ]);
    }

    /**
     * Upload bukti pembayaran (khusus metode transfer/QRIS, status pending).
     *
     * File disimpan pada disk PRIVATE (storage/app/private) sehingga tidak
     * dapat diakses langsung melalui URL publik /storage.
     */
    public function uploadProof(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->order->user_id === auth()->id(), 404);

        if (! in_array($payment->method, ['transfer', 'qris'], true)) {
            return back()->withErrors([
                'proof' => 'Metode '.strtoupper($payment->method).' tidak memerlukan unggahan bukti pembayaran.',
            ]);
        }

        if ($payment->status !== 'pending') {
            return back()->withErrors([
                'proof' => 'Bukti hanya dapat diunggah selama pembayaran berstatus pending. Status saat ini: '.$payment->status.'.',
            ]);
        }

        $data = $request->validate([
            'proof' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ], [
            'proof.required' => 'Pilih berkas bukti pembayaran.',
            'proof.image' => 'Berkas harus berupa gambar.',
            'proof.mimes' => 'Format bukti pembayaran harus jpeg, png, jpg, atau webp.',
            'proof.max' => 'Ukuran bukti pembayaran maksimal 2MB.',
        ]);

        // Ganti berkas lama (jangan tinggalkan file yatim)
        if ($payment->proof_image) {
            Storage::disk('local')->delete($payment->proof_image);
        }

        $path = $request->file('proof')->store('payment-proofs', 'local');

        $payment->update(['proof_image' => $path]);

        return back()->with('success', 'Bukti pembayaran berhasil diunggah. Menunggu verifikasi oleh admin.');
    }

    /**
     * Streaming bukti pembayaran secara aman.
     *
     * Hanya pelanggan pemilik pesanan dan admin yang boleh mengakses.
     * File tidak diekspos lewat direktori publik.
     */
    public function proof(Payment $payment)
    {
        $user = auth()->user();

        $isOwner = $payment->order->user_id === $user->id;
        $isAdmin = $user->isAdmin();

        abort_unless($isOwner || $isAdmin, 403);
        abort_unless($payment->proof_image, 404);

        abort_unless(Storage::disk('local')->exists($payment->proof_image), 404);

        return Storage::disk('local')->response(
            $payment->proof_image,
            'bukti-pembayaran-'.$payment->payment_number
        );
    }
}
