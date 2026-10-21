<?php

namespace App\Http\Controllers\Anggota;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pencatatan stok oleh Anggota / Pegawai Toko (kewenangan operasional).
 * Setiap perubahan tercatat di audit log beserta alasannya.
 */
class StockController extends Controller
{
    /**
     * Daftar barang & ketersediaan stok + peringatan stok rendah.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $lowOnly = $request->boolean('low');

        $products = Product::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($lowOnly, fn ($query) => $query->lowStock())
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('anggota.stock.index', [
            'products' => $products,
            'search' => $search,
            'lowOnly' => $lowOnly,
            'lowStockCount' => Product::lowStock()->count(),
        ]);
    }

    /**
     * Catat perubahan stok (wajib beralasan) + jejak audit.
     * Stok tidak boleh negatif.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'stock.min' => 'Stok tidak boleh negatif.',
            'reason.required' => 'Alasan perubahan stok wajib diisi untuk audit.',
        ]);

        $newStock = (int) $data['stock'];

        // Baris dikunci & stok lama dibaca di dalam transaksi: delta yang
        // dicatat di audit selalu dihitung dari nilai terkini, bukan bacaan basi.
        $oldStock = (int) DB::transaction(function () use ($product, $newStock) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();
            $old = (int) $locked->stock;
            $locked->update(['stock' => $newStock]);

            return $old;
        });

        $product->refresh();

        $delta = $newStock - $oldStock;

        ActivityLog::record('stock.adjusted', $product, sprintf(
            'Stok "%s": %d -> %d (%s%d) - %s',
            $product->name,
            $oldStock,
            $newStock,
            $delta >= 0 ? '+' : '',
            $delta,
            $data['reason'],
        ));

        return back()->with(
            'success',
            'Stok "'.$product->name.'" dicatat: '.$oldStock.' -> '.$newStock.'.'
        );
    }
}
