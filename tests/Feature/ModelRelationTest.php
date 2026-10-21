<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Uji relasi Eloquent antara Role dan User.
     */
    public function test_role_and_user_relationship(): void
    {
        $admin = User::where('email', 'admin@agungatk.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());
        $this->assertEquals('admin', $admin->role->name);

        $seller = User::where('email', 'penjual@agungatk.com')->first();
        $this->assertTrue($seller->isSeller());

        $courier = User::where('email', 'kurir@agungatk.com')->first();
        $this->assertTrue($courier->isCourier());

        $customer = User::where('email', 'pelanggan@agungatk.com')->first();
        $this->assertTrue($customer->isCustomer());
    }

    /**
     * Uji relasi Category dan Product.
     */
    public function test_category_and_product_relationship(): void
    {
        $product = Product::where('sku', 'ATK-KRT-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->category);
        $this->assertNotEmpty($product->images);
        $this->assertNotNull($product->primaryImage);
    }

    /**
     * Uji rantai transaksi pesanan (Order -> Details, Payment, Shipment, Review).
     */
    public function test_order_full_relationships(): void
    {
        $order = Order::where('order_number', 'ATK-20260909-0001')->first();
        $this->assertNotNull($order);
        $this->assertNotNull($order->user);
        $this->assertNotNull($order->address);
        $this->assertCount(2, $order->orderDetails);
        $this->assertNotNull($order->payment);
        $this->assertEquals('qris', $order->payment->method);
        $this->assertNotNull($order->shipment);
        $this->assertEquals('delivered', $order->shipment->status);
        $this->assertNotNull($order->reviews);
    }
}
