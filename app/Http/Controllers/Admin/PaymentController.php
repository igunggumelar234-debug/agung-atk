<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentController extends Controller
{
    /**
     * Daftar semua pembayaran dengan filter status, pencarian, dan pagination.
     */
    public function index(Request $request): View
    {
        $query = Payment::with(['order.user']);

        if ($request->filled('q')) {
            $search = $request->string('q')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('order_number', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($u) use ($search) {
                                $u->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
            });
        }

        if ($request->filled('status') && in_array($request->status, ['pending', 'verified', 'rejected'], true)) {
            $query->where('status', $request->status);
        }

        $payments = $query->latest()->paginate(10)->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'stats' => [
                'total' => Payment::count(),
                'pending' => Payment::where('status', 'pending')->count(),
                'verified' => Payment::where('status', 'verified')->count(),
                'rejected' => Payment::where('status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * Detail pembayaran + bukti untuk verifikasi admin.
     */
    public function show(Payment $payment): View
    {
        $payment->load(['order.user', 'order.address', 'order.orderDetails', 'verifier']);

        return view('admin.payments.show', [
            'payment' => $payment,
        ]);
    }

    /**
     * Verifikasi pembayaran: payment pending → verified,
     * order pending → paid.
     *
     * Baris pembayaran dikunci (lockForUpdate) dan status pending DIPERIKSA
     * ULANG di dalam transaksi — verifikasi tidak pernah menimpa pembayaran
     * yang sudah ditolak/dibatalkan bersamaan (TOCTOU).
     */
    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        $verified = DB::transaction(function () use ($payment, $request) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'pending') {
                return null;
            }

            $locked->update([
                'status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $request->user()->id,
                'rejection_reason' => null,
            ]);

            // Kunci & baca pesanan segar: pembatalan yang berjalan bersamaan
            // (yang mengunci baris pesanan lebih dulu) disegarkan statusnya di sini.
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->first();

            if ($order !== null && $order->status === 'pending') {
                $order->update(['status' => 'paid']);
            }

            return $locked;
        });

        if ($verified === null) {
            $current = $payment->fresh()->status ?? $payment->status;

            return back()->withErrors([
                'payment' => 'Hanya pembayaran berstatus pending yang dapat diverifikasi. Status saat ini: '.$current.'.',
            ]);
        }

        $payment = $verified->fresh(['order']);

        ActivityLog::record(
            'payment.verified',
            $payment,
            'Pembayaran #'.$payment->payment_number.' diverifikasi untuk pesanan #'.$payment->order->order_number
        );

        return back()->with('success', 'Pembayaran #'.$payment->payment_number.' diverifikasi. Pesanan #'.$payment->order->order_number.' berubah menjadi paid.');
    }

    /**
     * Tolak pembayaran: payment pending → rejected (+ alasan).
     * Status pesanan tidak diubah.
     */
    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ], [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi.',
        ]);

        $rejected = DB::transaction(function () use ($payment, $data, $request) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'pending') {
                return null;
            }

            $locked->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'],
                'verified_by' => $request->user()->id,
                'verified_at' => null,
            ]);

            return $locked;
        });

        if ($rejected === null) {
            $current = $payment->fresh()->status ?? $payment->status;

            return back()->withErrors([
                'payment' => 'Hanya pembayaran berstatus pending yang dapat ditolak. Status saat ini: '.$current.'.',
            ]);
        }

        $payment = $rejected;

        ActivityLog::record(
            'payment.rejected',
            $payment,
            'Pembayaran #'.$payment->payment_number.' ditolak — '.$data['rejection_reason']
        );

        return back()->with('success', 'Pembayaran #'.$payment->payment_number.' ditolak. Alasan telah dikirim ke pelanggan.');
    }
}
