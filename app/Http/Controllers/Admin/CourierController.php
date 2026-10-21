<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourierController extends Controller
{
    /**
     * Daftar kurir (role kurir) beserta jumlah tugas pengiriman.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $active = $request->query('aktif');

        $couriers = User::query()
            ->whereHas('role', function ($query) {
                $query->where('name', 'kurir');
            })
            ->withCount('courierShipments')
            ->withCount(['courierShipments as pending_shipments_count' => function ($query) {
                $query->whereIn('status', ['waiting', 'picked_up', 'shipping']);
            }])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(in_array($active, ['1', '0'], true), function ($query) use ($active) {
                $query->where('is_active', $active === '1');
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.couriers.index', [
            'couriers' => $couriers,
            'search' => $search,
            'active' => in_array($active, ['1', '0'], true) ? $active : null,
        ]);
    }

    /**
     * Aktif / nonaktifkan kurir (hanya akun lain, bukan diri sendiri).
     */
    public function toggleStatus(Request $request, User $courier): RedirectResponse
    {
        if ($courier->id === $request->user()->id) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun sendiri.');
        }

        $courier->update([
            'is_active' => ! $courier->is_active,
        ]);

        return back()->with(
            'success',
            $courier->is_active
                ? 'Kurir '.$courier->name.' berhasil diaktifkan.'
                : 'Kurir '.$courier->name.' berhasil dinonaktifkan.'
        );
    }
}
