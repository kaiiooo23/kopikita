<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Models\Menu;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_checkout_successfully_creates_order_and_dispatches_event(): void
    {
        Event::fake([OrderPlaced::class]);

        $table = Table::create([
            'table_number' => 'Meja 01',
            'current_token' => (string) Str::uuid(),
        ]);

        $menu1 = Menu::create([
            'name' => 'Kopi Susu Aren',
            'price' => 20000,
            'is_available' => true,
        ]);

        $menu2 = Menu::create([
            'name' => 'Croissant',
            'price' => 25000,
            'is_available' => true,
        ]);

        $payload = [
            'table_id' => $table->id,
            'total_price' => 65000,
            'items' => [
                [
                    'menu_id' => $menu1->id,
                    'quantity' => 2,
                    'notes' => 'Less ice, normal sugar',
                ],
                [
                    'menu_id' => $menu2->id,
                    'quantity' => 1,
                    'notes' => 'Hangatkan sebentar',
                ],
            ],
        ];

        $response = $this->postJson('/api/orders/checkout', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Pesanan berhasil dibuat dan diteruskan ke dapur.',
            ])
            ->assertJsonPath('data.table_id', $table->id)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('orders', [
            'table_id' => $table->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('order_items', [
            'menu_id' => $menu1->id,
            'quantity' => 2,
            'notes' => 'Less ice, normal sugar',
        ]);

        $this->assertDatabaseHas('order_items', [
            'menu_id' => $menu2->id,
            'quantity' => 1,
            'notes' => 'Hangatkan sebentar',
        ]);

        Event::assertDispatched(OrderPlaced::class, function (OrderPlaced $event) use ($table) {
            $this->assertEquals($table->id, $event->order->table_id);
            $this->assertTrue($event->order->relationLoaded('items'));
            $this->assertEquals('NewOrder', $event->broadcastAs());
            $this->assertEquals('dapur-channel', $event->broadcastOn()[0]->name);

            return true;
        });
    }

    public function test_checkout_validation_fails_with_invalid_data(): void
    {
        $response = $this->postJson('/api/orders/checkout', [
            'table_id' => 'not-a-uuid',
            'total_price' => 'not-numeric',
            'items' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['table_id', 'total_price', 'items']);
    }
}
