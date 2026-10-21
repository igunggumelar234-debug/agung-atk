<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AddressController extends Controller
{
    /**
     * Daftar alamat milik pelanggan yang login.
     */
    public function index(Request $request): View
    {
        $addresses = $request->user()
            ->addresses()
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get();

        return view('addresses.index', [
            'addresses' => $addresses,
        ]);
    }

    /**
     * Tambah alamat baru untuk pelanggan yang login (dipanggil dari checkout
     * atau halaman kelola alamat).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAddress($request);

        $isFirst = ! Address::where('user_id', auth()->id())->exists();

        Address::create([
            ...$data,
            'user_id' => auth()->id(),
            'is_primary' => $isFirst,
        ]);

        $newAddress = Address::where('user_id', auth()->id())->latest('id')->first();

        // Dari halaman kelola alamat: kembali ke daftar alamat.
        if ($request->input('back') === 'addresses') {
            return redirect()
                ->route('addresses.index')
                ->with('success', 'Alamat baru berhasil ditambahkan.');
        }

        // Dari checkout: langsung pilih alamat baru sebagai tujuan pengiriman.
        return redirect()
            ->route('checkout.index', ['address_id' => $newAddress?->id])
            ->with('success', 'Alamat baru berhasil ditambahkan dan dipilih sebagai alamat pengiriman.');
    }

    /**
     * Form ubah alamat.
     */
    public function edit(Request $request, Address $address): View
    {
        $this->authorizeOwner($address);

        return view('addresses.edit', [
            'address' => $address,
        ]);
    }

    /**
     * Simpan perubahan alamat.
     */
    public function update(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeOwner($address);

        $data = $this->validateAddress($request);

        $address->update($data);

        return redirect()
            ->route('addresses.index')
            ->with('success', 'Alamat berhasil diperbarui.');
    }

    /**
     * Tandai satu alamat sebagai alamat utama.
     */
    public function setPrimary(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeOwner($address);

        DB::transaction(function () use ($address) {
            Address::where('user_id', $address->user_id)
                ->where('id', '!=', $address->id)
                ->update(['is_primary' => false]);

            $address->update(['is_primary' => true]);
        });

        return back()->with('success', 'Alamat utama berhasil diperbarui.');
    }

    /**
     * Hapus alamat.
     *
     * Alamat yang sudah dipakai pada pesanan TIDAK boleh dihapus agar
     * riwayat pesanan tetap utuh (relasi orders.address_id).
     */
    public function destroy(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeOwner($address);

        if ($address->orders()->exists()) {
            return back()->with(
                'error',
                'Alamat "'.$address->label.'" tidak dapat dihapus karena sudah digunakan pada pesanan.'
            );
        }

        DB::transaction(function () use ($address) {
            $wasPrimary = $address->is_primary;
            $address->delete();

            if ($wasPrimary) {
                $next = Address::where('user_id', $address->user_id)->orderBy('id')->first();
                $next?->update(['is_primary' => true]);
            }
        });

        return redirect()
            ->route('addresses.index')
            ->with('success', 'Alamat berhasil dihapus.');
    }

    /**
     * Pastikan alamat benar-benar milik pelanggan yang login.
     */
    private function authorizeOwner(Address $address): void
    {
        abort_unless($address->user_id === auth()->id(), 404);
    }

    /**
     * Aturan validasi bersama untuk tambah/ubah alamat.
     */
    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'full_address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'label.required' => 'Label alamat wajib diisi (contoh: Rumah, Kantor).',
            'recipient_name.required' => 'Nama penerima wajib diisi.',
            'phone.required' => 'Nomor telepon wajib diisi.',
            'full_address.required' => 'Alamat lengkap wajib diisi.',
            'city.required' => 'Kota/kabupaten wajib diisi.',
            'district.required' => 'Kecamatan wajib diisi.',
        ]);
    }
}
