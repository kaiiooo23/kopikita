<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderPlaced;
use App\Events\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;
use Throwable;

class OrderApiController extends Controller
{
    /**
     * Handle customer order checkout.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => ['required', 'uuid', 'exists:tables,id'],
            'total_price' => ['required', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_id' => ['required', 'integer', 'exists:menus,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            // Ensure atomic execution across orders and order_items tables
            $order = DB::transaction(function () use ($validated) {
                $order = Order::create([
                    'table_id' => $validated['table_id'],
                    'total_price' => $validated['total_price'],
                    'status' => 'pending',
                ]);

                foreach ($validated['items'] as $item) {
                    $order->items()->create([
                        'menu_id' => $item['menu_id'],
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }

                return $order;
            });

            // Dispatch real-time WebSocket broadcast event
            OrderPlaced::dispatch($order);

            return response()->json([
                'status' => 'success',
                'message' => 'Pesanan berhasil dibuat dan diteruskan ke dapur.',
                'data' => $order->load(['items.menu', 'table']),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Checkout failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses pesanan. Silakan coba beberapa saat lagi.',
            ], 500);
        }
    }

    /**
     * Update order status (pending -> cooking -> completed).
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,cooking,completed'],
        ]);

        $order = Order::findOrFail($id);
        $order->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Status pesanan berhasil diperbarui menjadi '.$validated['status'].'.',
            'data' => $order->load(['items.menu', 'table']),
        ]);
    }

    public function generateSnapToken(string $orderId): JsonResponse
    {
        $order = Order::with('items.menu')->whereKey($orderId)->firstOrFail();

        if ($order->status === 'paid') {
            return response()->json(['message' => 'Order ini sudah dibayar.'], 422);
        }

        if (! config('services.midtrans.server_key')) {
            return response()->json(['message' => 'Midtrans belum dikonfigurasi.'], 503);
        }

        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;

        $payload = [
            'transaction_details' => [
                'order_id' => $order->id,
                'gross_amount' => (int) round((float) $order->total_price),
            ],
            'enabled_payments' => ['gopay', 'shopeepay', 'qris'],
        ];

        return response()->json([
            'snap_token' => Snap::getSnapToken($payload),
        ]);
    }

    public function markPaidCash(string $orderId): JsonResponse
    {
        $order = $this->markOrderAsPaid($orderId);

        return response()->json([
            'status' => 'success',
            'message' => 'Pembayaran cash berhasil dikonfirmasi.',
            'data' => $order,
        ]);
    }

    public function paymentCallback(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_id' => ['required', 'string'],
            'status_code' => ['required', 'string'],
            'gross_amount' => ['required', 'string'],
            'signature_key' => ['required', 'string'],
            'transaction_status' => ['required', 'string'],
        ]);

        $signature = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('services.midtrans.server_key')
        );

        abort_unless(hash_equals($signature, $payload['signature_key']), 403, 'Invalid Midtrans signature.');

        if (in_array($payload['transaction_status'], ['settlement', 'capture'], true)) {
            $this->markOrderAsPaid($payload['order_id']);
        }

        return response()->json(['status' => 'ok']);
    }

    private function markOrderAsPaid(string $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::with(['items.menu', 'table'])->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'paid') {
                $order->update(['status' => 'paid']);
                $order->table?->update(['current_token' => (string) Str::uuid()]);
                event(new OrderUpdated($order->fresh(['items.menu', 'table'])));
            }

            return $order->fresh(['items.menu', 'table']);
        });
    }
}
