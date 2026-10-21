<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    /**
     * Tampilkan daftar kategori ATK dengan pencarian, filter, dan pagination.
     */
    public function index(Request $request): View
    {
        $query = Category::withCount('products');

        if ($request->filled('q')) {
            $search = $request->string('q')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $categories = $query->latest()->paginate(10)->withQueryString();

        $stats = [
            'total' => Category::count(),
            'active' => Category::where('is_active', true)->count(),
            'inactive' => Category::where('is_active', false)->count(),
        ];

        return view('admin.categories.index', compact('categories', 'stats'));
    }

    /**
     * Formulir tambah kategori baru.
     */
    public function create(): View
    {
        return view('admin.categories.create');
    }

    /**
     * Simpan kategori baru ke database.
     */
    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Buat slug otomatis jika tidak diisi manual
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug(Str::slug($data['name']));
        } else {
            // Slug manual tetap dinormalisasi DAN dicek uniknya: validasi unique
            // hanya memeriksa input mentah — hasil Str::slug bisa bertabrakan
            // dengan slug yang sudah ada (sebelumnya -> QueryException 500).
            $data['slug'] = $this->uniqueSlug(Str::slug($data['slug']));
        }

        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('categories', 'public');
        }

        Category::create($data);

        return redirect()->route('admin.categories.index')
            ->with('success', 'Kategori baru berhasil ditambahkan.');
    }

    /**
     * Formulir edit kategori.
     */
    public function edit(Category $category): View
    {
        return view('admin.categories.edit', compact('category'));
    }

    /**
     * Perbarui data kategori.
     */
    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug(Str::slug($data['name']), $category->id);
        } else {
            // Normalisasi + jamin unik terhadap kategori lain (cabang diabaikan
            // oleh validasi unique yang hanya memeriksa input mentah).
            $data['slug'] = $this->uniqueSlug(Str::slug($data['slug']), $category->id);
        }

        $data['is_active'] = $request->boolean('is_active', false);

        if ($request->hasFile('image')) {
            if ($category->image && Storage::disk('public')->exists($category->image)) {
                Storage::disk('public')->delete($category->image);
            }
            $data['image'] = $request->file('image')->store('categories', 'public');
        }

        $category->update($data);

        return redirect()->route('admin.categories.index')
            ->with('success', 'Kategori "'.$category->name.'" berhasil diperbarui.');
    }

    /**
     * Hapus kategori dari database (dicegah jika memiliki produk).
     */
    public function destroy(Category $category): RedirectResponse
    {
        $productCount = $category->products()->count();

        if ($productCount > 0) {
            return back()->withErrors([
                'error' => 'Kategori "'.$category->name.'" tidak dapat dihapus karena masih memuat '.$productCount.' produk. Pindahkan atau hapus produk terlebih dahulu.',
            ]);
        }

        if ($category->image && Storage::disk('public')->exists($category->image)) {
            Storage::disk('public')->delete($category->image);
        }

        $categoryName = $category->name;
        $category->delete();

        return redirect()->route('admin.categories.index')
            ->with('success', 'Kategori "'.$categoryName.'" berhasil dihapus.');
    }

    /**
     * Slug unik: menormalkan hasil slug lalu menambahkan sufiks -1, -2, ...
     * bila sudah dipakai kategori lain (kecuali $ignoreId untuk mode edit).
     */
    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        $counter = 1;

        while (Category::where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
