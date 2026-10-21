<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    use EnsuresSeedFiles;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customer = User::where('email', 'pelanggan@agungatk.com')->first();
        $courier = User::where('email', 'kurir@agungatk.com')->first();
        $admin = User::where('email', 'admin@agungatk.com')->first();
        $address = Address::where('user_id', $customer?->id)->first();

        $product1 = Product::where('sku', 'ATK-KRT-001')->first(); // PaperOne
        $product2 = Product::where('sku', 'ATK-PLP-001')->first(); // Pilot G2

        if ($customer && $address && $product1 && $product2) {
            $qty1 = 2;
            $qty2 = 3;
            $subtotal1 = $product1->price * $qty1;
            $subtotal2 = $product2->price * $qty2;
            $totalAmount = $subtotal1 + $subtotal2;
            $shippingCost = 10000; // Tarif kurir lokal Kuningan
            $grandTotal = $totalAmount + $shippingCost;

            // 1. Buat Order
            $order = Order::firstOrCreate(
                ['order_number' => 'ATK-20260909-0001'],
                [
                    'user_id' => $customer->id,
                    'address_id' => $address->id,
                    'total_amount' => $totalAmount,
                    'shipping_cost' => $shippingCost,
                    'discount_amount' => 0,
                    'grand_total' => $grandTotal,
                    'status' => 'completed',
                    'notes' => 'Tolong kirimkan sebelum jam 15.00 sore.',
                    'completed_at' => now(),
                ]
            );

            // 2. Buat Order Details
            OrderDetail::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $product1->id],
                [
                    'product_name' => $product1->name,
                    'price' => $product1->price,
                    'quantity' => $qty1,
                    'subtotal' => $subtotal1,
                ]
            );

            OrderDetail::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $product2->id],
                [
                    'product_name' => $product2->name,
                    'price' => $product2->price,
                    'quantity' => $qty2,
                    'subtotal' => $subtotal2,
                ]
            );

            // 3. Buat Payment (QRIS terverifikasi)
            Payment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_number' => 'PAY-20260909-0001',
                    'method' => 'qris',
                    'amount' => $grandTotal,
                    'proof_image' => 'payments/sample-qris.jpg',
                    'status' => 'verified',
                    'verified_at' => now()->subHours(4),
                    'verified_by' => $admin?->id,
                    'notes' => 'Pembayaran QRIS telah tervalidasi otomatis.',
                ]
            );

            $this->ensureSeedFile('local', 'payments/sample-qris.jpg');

            // 4. Buat Shipment (Delivered oleh Kurir)
            Shipment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'courier_id' => $courier?->id,
                    'tracking_number' => 'SHIP-KNG-20260909-001',
                    'courier_name' => $courier?->name,
                    'status' => 'delivered',
                    'shipped_at' => now()->subHours(3),
                    'delivered_at' => now()->subHour(),
                    'recipient_name' => 'Budi Santoso',
                    'proof_image' => 'shipments/sample-delivered.jpg',
                    'notes' => 'Paket diterima langsung oleh pemilik toko dalam kondisi baik.',
                ]
            );

            $this->ensureSeedFile('local', 'shipments/sample-delivered.jpg');

            // 5. Buat Review Pelanggan
            Review::firstOrCreate(
                [
                    'order_id' => $order->id,
                    'product_id' => $product1->id,
                    'user_id' => $customer->id,
                ],
                [
                    'rating' => 5,
                    'comment' => 'Kertas PaperOne original, packing rapi, dan kurir Kuningan mengantar sangat cepat. Mantap!',
                    'is_approved' => true,
                ]
            );
        }
    }
}
