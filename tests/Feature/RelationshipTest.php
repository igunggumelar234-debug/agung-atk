<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Review;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Relasi Role terhadap User.
     */
    public function test_role_has_many_users(): void
    {
        $role = Role::where('name', 'admin')->first();

        $this->assertNotNull($role);
        $this->assertInstanceOf(User::class, $role->users->first());
    }

    /**
     * Relasi User terhadap Role, Address, Order, Review, dan AIConversation.
     */
    public function test_user_relationships(): void
    {
        $customer = User::where('email', 'pelanggan@agungatk.com')->first();

        $this->assertNotNull($customer);
        $this->assertInstanceOf(Role::class, $customer->role);
        $this->assertInstanceOf(Address::class, $customer->addresses->first());
        $this->assertInstanceOf(Order::class, $customer->orders->first());
        $this->assertNotNull($customer->reviews);

        // Relasi AIConversation (dibuat pada saat diuji karena belum ada seedernya)
        AiConversation::create(['user_id' => $customer->id, 'title' => 'Tes Relasi']);
        $this->assertInstanceOf(AiConversation::class, $customer->aiConversations->first());
    }

    /**
     * Relasi Category terhadap Product.
     */
    public function test_category_has_many_products(): void
    {
        $category = Category::where('slug', 'kertas-dan-karton')->first();

        $this->assertNotNull($category);
        $this->assertInstanceOf(Product::class, $category->products->first());
    }

    /**
     * Relasi Product terhadap Category, ProductImage, OrderDetail, dan Review.
     */
    public function test_product_relationships(): void
    {
        $product = Product::where('sku', 'ATK-KRT-001')->first();

        $this->assertNotNull($product);
        $this->assertInstanceOf(Category::class, $product->category);
        $this->assertInstanceOf(ProductImage::class, $product->productImages->first());
        $this->assertInstanceOf(OrderDetail::class, $product->orderDetails->first());
        $this->assertInstanceOf(Review::class, $product->reviews->first());
    }

    /**
     * Relasi Order terhadap User, Address, OrderDetails, Payment, dan Shipment.
     */
    public function test_order_relationships(): void
    {
        $order = Order::where('order_number', 'ATK-20260909-0001')->first();

        $this->assertNotNull($order);
        $this->assertInstanceOf(User::class, $order->user);
        $this->assertInstanceOf(Address::class, $order->address);
        $this->assertInstanceOf(OrderDetail::class, $order->orderDetails->first());
        $this->assertInstanceOf(Payment::class, $order->payment);
        $this->assertInstanceOf(Shipment::class, $order->shipment);
    }

    /**
     * Relasi OrderDetail terhadap Order dan Product.
     */
    public function test_order_detail_belongs_to_order_and_product(): void
    {
        $detail = OrderDetail::first();

        $this->assertNotNull($detail);
        $this->assertInstanceOf(Order::class, $detail->order);
        $this->assertInstanceOf(Product::class, $detail->product);
    }

    /**
     * Relasi Payment terhadap Order.
     */
    public function test_payment_belongs_to_order(): void
    {
        $payment = Payment::first();

        $this->assertNotNull($payment);
        $this->assertInstanceOf(Order::class, $payment->order);
    }

    /**
     * Relasi Shipment terhadap Order dan User (sebagai courier).
     */
    public function test_shipment_belongs_to_order_and_courier(): void
    {
        $shipment = Shipment::first();

        $this->assertNotNull($shipment);
        $this->assertInstanceOf(Order::class, $shipment->order);
        $this->assertInstanceOf(User::class, $shipment->courier);
        $this->assertTrue($shipment->courier->isCourier());
    }

    /**
     * Relasi Review terhadap Product, User, dan Order.
     */
    public function test_review_belongs_to_product_user_and_order(): void
    {
        $review = Review::first();

        $this->assertNotNull($review);
        $this->assertInstanceOf(Product::class, $review->product);
        $this->assertInstanceOf(User::class, $review->user);
        $this->assertInstanceOf(Order::class, $review->order);
    }

    /**
     * Relasi AIConversation terhadap User dan AIMessages, serta AIMessage ke induknya.
     */
    public function test_ai_conversation_and_message_relationships(): void
    {
        $customer = User::where('email', 'pelanggan@agungatk.com')->first();

        $conversation = AiConversation::create([
            'user_id' => $customer->id,
            'title' => 'Konsultasi Kebutuhan ATK',
        ]);

        $message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Butuh 50 buku tulis untuk sekolah.',
        ]);

        $this->assertInstanceOf(User::class, $conversation->user);
        $this->assertCount(1, $conversation->messages);
        $this->assertInstanceOf(AiConversation::class, $message->conversation);
        $this->assertEquals($conversation->id, $message->conversation->id);
    }

    /**
     * Memastikan seluruh $casts yang disyaratkan spesifikasi bekerja.
     */
    public function test_model_casts_are_applied(): void
    {
        $product = Product::where('sku', 'ATK-KRT-001')->first();
        $order = Order::where('order_number', 'ATK-20260909-0001')->first();
        $review = Review::first();
        $payment = Payment::first();
        $user = User::where('email', 'pelanggan@agungatk.com')->first();

        // price / subtotal / total / discount / shipping_fee / amount
        $this->assertTrue(is_string($product->price));
        $this->assertTrue(is_string($order->total_amount));
        $this->assertTrue(is_string($order->grand_total));
        $this->assertTrue(is_string($order->discount_amount));
        $this->assertTrue(is_string($order->shipping_cost));
        $this->assertTrue(is_string($order->orderDetails->first()->subtotal));
        $this->assertTrue(is_string($payment->amount));

        // rating
        $this->assertTrue(is_int($review->rating));

        // boolean fields
        $this->assertTrue(is_bool($product->is_active));
        $this->assertTrue(is_bool($user->is_active));
        $this->assertTrue(is_bool($review->is_approved));
        $this->assertTrue(is_bool($order->address->is_primary));
        $this->assertTrue(is_bool($product->productImages->first()->is_primary));

        // timestamp fields
        $this->assertNotNull($payment->verified_at);
        $this->assertNotNull($order->completed_at);
        $this->assertNotNull($order->shipment->shipped_at);
        $this->assertNotNull($order->shipment->delivered_at);
        $this->assertInstanceOf(Carbon::class, $order->completed_at);
        $this->assertInstanceOf(Carbon::class, $order->shipment->delivered_at);

        // password hashing untuk authentication Laravel
        $this->assertStringStartsWith('$2y$', $user->getAuthPassword());
        $this->assertNotNull($user->getAuthIdentifier());
    }
}
