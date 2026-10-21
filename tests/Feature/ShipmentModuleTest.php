<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $penjual;

    private User $pelanggan;

    private User $kurir;

    private Address $address;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
        $this->product = Product::firstOrFail();
    }

    /**
     * Pesanan berstatus ready_to_ship + 1 rincian item.
     */
    private function createReadyOrder(string $status = 'ready_to_ship'): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-SHIP-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $this->address->id,
            'total_amount' => 50000,
            'shipping_cost' => 10000,
            'discount_amount' => 0,
            'grand_total' => 60000,
            'status' => $status,
        ]);

        OrderDetail::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
        ]);

        return $order;
    }

    /**
     * Shipment manual (bypass admin) dengan status tertentu.
     */
    private function createShipment(Order $order, User $courier, string $status = 'waiting'): Shipment
    {
        return Shipment::create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'tracking_number' => 'SHIP-TEST-'.uniqid(),
            'courier_name' => $courier->name,
            'status' => $status,
            'recipient_name' => 'Penerima Uji',
        ]);
    }

    private function createSecondCourier(): User
    {
        return User::create([
            'name' => 'Kurir Kedua',
            'email' => 'kurir2@agungatk.com',
            'password' => bcrypt('password'),
            'role_id' => Role::whereIn('name', ['courier', 'kurir'])->first()->id,
            'is_active' => true,
        ]);
    }

    /* =====================================================================
     | ADMIN — AUTENTIKASI & AUTHORIZATION
     * ===================================================================*/

    public function test_guest_is_redirected_to_login(): void
    {
        $order = $this->createReadyOrder();
        $shipment = $this->createShipment($order, $this->kurir);

        $this->get('/admin/shipments')->assertRedirect('/login');
        $this->post('/admin/shipments', ['order_id' => $order->id, 'courier_id' => $this->kurir->id])->assertRedirect('/login');
        $this->patch('/admin/shipments/'.$shipment->id.'/complete')->assertRedirect('/login');
    }

    public function test_non_admin_roles_are_forbidden(): void
    {
        $order = $this->createReadyOrder();
        $shipment = $this->createShipment($order, $this->kurir);

        foreach ([$this->penjual, $this->pelanggan, $this->kurir] as $user) {
            $this->actingAs($user)->get('/admin/shipments')->assertStatus(403);
            $this->actingAs($user)->post('/admin/shipments', ['order_id' => $order->id, 'courier_id' => $this->kurir->id])->assertStatus(403);
            $this->actingAs($user)->patch('/admin/shipments/'.$shipment->id.'/complete')->assertStatus(403);
        }

        $this->assertSame('waiting', $shipment->fresh()->status);
    }

    /* =====================================================================
     | ADMIN — HALAMAN & PEMBUATAN SHIPMENT
     * ===================================================================*/

    public function test_admin_index_shows_ready_orders_and_shipments(): void
    {
        $ready = $this->createReadyOrder();
        $withShipment = $this->createReadyOrder();
        $this->createShipment($withShipment, $this->kurir);

        $response = $this->actingAs($this->admin)
            ->get('/admin/shipments')
            ->assertStatus(200)
            ->assertSee('Pesanan Siap Dikirim')
            ->assertSee('Daftar Shipment')
            ->assertSee($ready->order_number);

        // Panel "Pesanan Siap Dikirim": hanya order tanpa resi yang boleh muncul
        $content = $response->getContent();
        $start = strpos($content, 'Pesanan Siap Dikirim');
        $end = strpos($content, 'Daftar Shipment');
        $panel = substr($content, $start, $end - $start);

        $this->assertStringContainsString($ready->order_number, $panel);
        $this->assertStringNotContainsString($withShipment->order_number, $panel);
    }

    public function test_admin_can_create_shipment_and_assign_courier(): void
    {
        $order = $this->createReadyOrder();

        $this->actingAs($this->admin)
            ->post('/admin/shipments', [
                'order_id' => $order->id,
                'courier_id' => $this->kurir->id,
            ])
            ->assertSessionHas('success');

        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('waiting', $shipment->status);
        $this->assertSame($this->kurir->id, (int) $shipment->courier_id);
        $this->assertSame($this->kurir->name, $shipment->courier_name);
        $this->assertSame($this->address->recipient_name, $shipment->recipient_name); // di-snapshot dari alamat
        $this->assertStringStartsWith('SHIP-KNG-', $shipment->tracking_number);
        $this->assertSame('ready_to_ship', $order->fresh()->status); // order tidak diubah saat create
    }

    public function test_create_requires_active_courier_role(): void
    {
        $order = $this->createReadyOrder();

        // courier_id menunjuk pelanggan (bukan role kurir) → ditolak
        $this->actingAs($this->admin)
            ->post('/admin/shipments', [
                'order_id' => $order->id,
                'courier_id' => $this->pelanggan->id,
            ])
            ->assertSessionHasErrors('courier_id');

        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }

    public function test_create_requires_courier_selection(): void
    {
        $order = $this->createReadyOrder();

        $this->actingAs($this->admin)
            ->post('/admin/shipments', ['order_id' => $order->id, 'courier_id' => ''])
            ->assertSessionHasErrors('courier_id');
    }

    public function test_create_requires_ready_to_ship_order(): void
    {
        $paid = $this->createReadyOrder('paid');
        $delivered = $this->createReadyOrder('delivered');

        foreach ([$paid, $delivered] as $order) {
            $this->actingAs($this->admin)
                ->post('/admin/shipments', [
                    'order_id' => $order->id,
                    'courier_id' => $this->kurir->id,
                ])
                ->assertSessionHasErrors('order_id');
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $paid->id]);
        $this->assertDatabaseMissing('shipments', ['order_id' => $delivered->id]);
    }

    public function test_order_with_existing_shipment_cannot_get_another(): void
    {
        $order = $this->createReadyOrder();
        $this->createShipment($order, $this->kurir);

        $this->actingAs($this->admin)
            ->post('/admin/shipments', [
                'order_id' => $order->id,
                'courier_id' => $this->kurir->id,
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
    }

    public function test_admin_can_filter_shipments_by_status(): void
    {
        $waitingOrder = $this->createReadyOrder();
        $waiting = $this->createShipment($waitingOrder, $this->kurir, 'waiting');
        $doneOrder = $this->createReadyOrder();
        $delivered = $this->createShipment($doneOrder, $this->kurir, 'delivered');

        $response = $this->actingAs($this->admin)->get('/admin/shipments?status=delivered')->assertStatus(200);
        $response->assertSee($delivered->tracking_number);
        $response->assertDontSee($waiting->tracking_number);
    }

    /* =====================================================================
     | ADMIN — PENYELESAIAN PESANAN (COMPLETED)
     * ===================================================================*/

    public function test_admin_can_complete_delivered_order(): void
    {
        $order = $this->createReadyOrder('delivered');
        $shipment = $this->createShipment($order, $this->kurir, 'delivered');

        $this->actingAs($this->admin)
            ->patch('/admin/shipments/'.$shipment->id.'/complete')
            ->assertSessionHas('success');

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_complete_is_rejected_until_shipment_delivered(): void
    {
        $order = $this->createReadyOrder('shipping');
        $shipment = $this->createShipment($order, $this->kurir, 'shipping');

        $this->actingAs($this->admin)
            ->patch('/admin/shipments/'.$shipment->id.'/complete')
            ->assertSessionHasErrors('shipment');

        $this->assertSame('shipping', $order->fresh()->status);
    }

    public function test_complete_is_rejected_when_order_not_delivered(): void
    {
        // Shipment delivered tetapi pesanan belum delivered → tetap ditolak
        $order = $this->createReadyOrder('shipping');
        $shipment = $this->createShipment($order, $this->kurir, 'delivered');

        $this->actingAs($this->admin)
            ->patch('/admin/shipments/'.$shipment->id.'/complete')
            ->assertSessionHasErrors('shipment');

        $this->assertSame('shipping', $order->fresh()->status);
    }

    /* =====================================================================
     | KURIR — AUTENTIKASI & SCOPE MILIK SENDIRI
     * ===================================================================*/

    public function test_guest_is_redirected_from_courier_area(): void
    {
        $order = $this->createReadyOrder();
        $shipment = $this->createShipment($order, $this->kurir);

        $this->get('/courier/shipments')->assertRedirect('/login');
        $this->get('/courier/shipments/'.$shipment->id)->assertRedirect('/login');
        $this->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'picked_up'])->assertRedirect('/login');
    }

    public function test_non_courier_roles_are_forbidden(): void
    {
        $order = $this->createReadyOrder();
        $shipment = $this->createShipment($order, $this->kurir);

        foreach ([$this->admin, $this->penjual, $this->pelanggan] as $user) {
            $this->actingAs($user)->get('/courier/shipments')->assertStatus(403);
            $this->actingAs($user)->get('/courier/shipments/'.$shipment->id)->assertStatus(403);
            $this->actingAs($user)->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'picked_up'])->assertStatus(403);
        }
    }

    public function test_courier_sees_only_own_shipments(): void
    {
        $kurir2 = $this->createSecondCourier();

        $mine = $this->createShipment($this->createReadyOrder(), $this->kurir, 'waiting');
        $others = $this->createShipment($this->createReadyOrder(), $kurir2, 'waiting');

        $response = $this->actingAs($this->kurir)->get('/courier/shipments')->assertStatus(200);

        $response->assertSee($mine->tracking_number);
        $response->assertDontSee($others->tracking_number);
    }

    public function test_courier_cannot_view_or_update_others_shipment(): void
    {
        $kurir2 = $this->createSecondCourier();
        $foreign = $this->createShipment($this->createReadyOrder(), $kurir2, 'waiting');

        $this->actingAs($this->kurir)->get('/courier/shipments/'.$foreign->id)->assertStatus(404);

        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$foreign->id.'/status', ['status' => 'picked_up'])
            ->assertStatus(404);

        $this->assertSame('waiting', $foreign->fresh()->status);
    }

    /* =====================================================================
     | KURIR — DETAIL (ALAMAT & TELEPON PENERIMA)
     * ===================================================================*/

    public function test_detail_shows_recipient_phone_and_address(): void
    {
        $order = $this->createReadyOrder();
        $shipment = $this->createShipment($order, $this->kurir, 'waiting');

        $response = $this->actingAs($this->kurir)
            ->get('/courier/shipments/'.$shipment->id)
            ->assertStatus(200)
            ->assertSee($shipment->tracking_number)
            ->assertSee($order->order_number)
            ->assertSee($this->address->recipient_name)
            ->assertSee($this->address->phone)
            ->assertSee($this->address->full_address);

        // Optimasi smartphone: nomor telepon dapat diklik (tel:) & link peta
        $response->assertSee('href="tel:'.$this->address->phone.'"', false);
        $response->assertSee('google.com/maps/search', false);
    }

    public function test_courier_can_filter_shipments_by_status(): void
    {
        $waiting = $this->createShipment($this->createReadyOrder(), $this->kurir, 'waiting');
        $shipped = $this->createShipment($this->createReadyOrder(), $this->kurir, 'shipping');

        $response = $this->actingAs($this->kurir)
            ->get('/courier/shipments?status=shipping')
            ->assertStatus(200);

        $response->assertSee($shipped->tracking_number);
        $response->assertDontSee($waiting->tracking_number);
    }

    /* =====================================================================
     | KURIR — FLOW STATUS: waiting → picked_up → shipping → delivered
     * ===================================================================*/

    public function test_full_flow_updates_shipment_and_order(): void
    {
        // Admin membuat shipment
        $order = $this->createReadyOrder();
        $this->actingAs($this->admin)->post('/admin/shipments', [
            'order_id' => $order->id,
            'courier_id' => $this->kurir->id,
        ]);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

        // 1. waiting → picked_up (order ready_to_ship → shipping)
        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'picked_up'])
            ->assertSessionHas('success');
        $this->assertSame('picked_up', $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->shipped_at);
        $this->assertSame('shipping', $order->fresh()->status);

        // 2. picked_up → shipping (order tetap shipping)
        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'shipping']);
        $this->assertSame('shipping', $shipment->fresh()->status);
        $this->assertSame('shipping', $order->fresh()->status);

        // 3. shipping → delivered (order → delivered)
        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'delivered'])
            ->assertSessionHas('success');
        $this->assertSame('delivered', $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->delivered_at);
        $this->assertSame('delivered', $order->fresh()->status);

        // 4. Admin menyelesaikan pesanan → completed
        $this->actingAs($this->admin)
            ->patch('/admin/shipments/'.$shipment->id.'/complete')
            ->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $shipment = $this->createShipment($this->createReadyOrder(), $this->kurir, 'waiting');

        // Melompat waiting → shipping
        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'shipping'])
            ->assertSessionHasErrors('shipment');
        $this->assertSame('waiting', $shipment->fresh()->status);

        // Melompat waiting → delivered
        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'delivered'])
            ->assertSessionHasErrors('shipment');
        $this->assertSame('waiting', $shipment->fresh()->status);
    }

    public function test_delivered_shipment_cannot_go_back(): void
    {
        $order = $this->createReadyOrder('delivered');
        $shipment = $this->createShipment($order, $this->kurir, 'delivered');

        $this->actingAs($this->kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'picked_up'])
            ->assertSessionHasErrors('shipment');

        $this->assertSame('delivered', $shipment->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_status_value_is_validated(): void
    {
        $shipment = $this->createShipment($this->createReadyOrder(), $this->kurir, 'waiting');

        foreach (['waiting', 'bogus', 'completed'] as $status) {
            $this->actingAs($this->kurir)
                ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => $status])
                ->assertSessionHasErrors('status');
        }

        $this->assertSame('waiting', $shipment->fresh()->status);
    }
}
