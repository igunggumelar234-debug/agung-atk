<?php

namespace App\Http\Controllers;

use App\Services\ATKImageRecognitionService;
use App\Services\ImageSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint Pencarian Produk ATK Berbasis Gambar (Visual Search).
 *
 * Menerima unggahan foto (kamera atau galeri), mengenali jenis barang ATK
 * melalui ATKImageRecognitionService, lalu mengembalikan produk katalog yang
 * benar-benar ada di database (ImageSearchService). Tidak ada data produk
 * yang dikarang.
 */
class ImageSearchController extends Controller
{
    public function __construct(
        private readonly ATKImageRecognitionService $recognition,
        private readonly ImageSearchService $search,
    ) {}

    /**
     * Analisis gambar & kembalikan rekomendasi produk ATK (JSON).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $maxKb = (int) config('services.image_search.max_upload_kb', 2048);

        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
            'context' => ['nullable', 'in:catalog,chatbot'],
        ], [
            'image.required' => 'Gambar wajib diunggah. Silakan ambil foto atau pilih gambar dari perangkat Anda.',
            'image.file' => 'File gambar tidak valid.',
            'image.mimes' => 'Format gambar harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar maksimal '.$maxKb.' KB.',
        ]);

        $binary = (string) $request->file('image')->get();
        $result = $this->recognition->recognize($binary);

        if ($result['status'] === 'error') {
            return response()->json([
                'status' => 'error',
                'message' => 'Gambar tidak dapat diproses. Silakan unggah gambar JPG, PNG, atau WebP yang valid, atau coba foto ulang.',
                'label' => null,
                'products' => [],
            ]);
        }

        if ($result['status'] === 'recognized') {
            $products = $this->search->searchByLabel($result['label']);
            $labelName = mb_strtolower((string) $result['label_name']);

            if ($products === []) {
                $message = 'Saya mengenali gambar ini sebagai '.$labelName.', tetapi produk tersebut belum tersedia dalam katalog Agung ATK. '
                    .'Anda bisa mencoba foto barang lain, mengunggah gambar yang lebih jelas, mengetik nama barang secara manual, '
                    .'atau melihat kategori terkait di katalog.';
            } else {
                $message = 'Saya mengenali gambar ini sebagai '.$labelName.'. Berikut beberapa produk '.$labelName
                    .' yang tersedia di katalog Agung ATK.';
            }

            return response()->json([
                'status' => 'recognized',
                'label' => $result['label'],
                'label_name' => $result['label_name'],
                'confidence' => $result['confidence'],
                'engine' => $result['engine'] ?? null,
                'message' => $message,
                'products' => $products,
            ]);
        }

        if ($result['status'] === 'not_atk') {
            return response()->json([
                'status' => 'not_atk',
                'label' => $result['label'],
                'label_name' => $result['label_name'],
                'confidence' => $result['confidence'],
                'engine' => $result['engine'] ?? null,
                'message' => ATKImageRecognitionService::MESSAGE_NOT_RECOGNIZED,
                'products' => [],
            ]);
        }

        // uncertain
        return response()->json([
            'status' => 'uncertain',
            'label' => null,
            'label_name' => null,
            'confidence' => $result['confidence'],
            'engine' => $result['engine'] ?? null,
            'message' => ATKImageRecognitionService::MESSAGE_NOT_RECOGNIZED,
            'products' => [],
        ]);
    }
}
