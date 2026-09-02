<?php

use App\Models\Menu;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Http\Request;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

// Sisi Pelanggan (E-Menu & Self-Order)
Route::get('/meja/{id}', function (string $id, Request $request) {
    $table = Table::findOrFail($id);
    $menus = Menu::orderBy('id', 'asc')->get();

    return Inertia::render('Pelanggan/Menu', [
        'table' => [
            'id' => $table->id,
            'table_number' => $table->table_number,
            'current_token' => $table->current_token,
        ],
        'menus' => $menus,
    ]);
})->name('pelanggan.menu');

// Redirect helper untuk akses cepat /menu
Route::get('/menu', function () {
    $firstTable = Table::first();
    if ($firstTable) {
        return redirect()->route('pelanggan.menu', ['id' => $firstTable->id]);
    }

    abort(404, 'Belum ada data meja yang tersedia.');
})->name('menu.redirect');

// Sisi Dapur / Barista Monitor (Kitchen Display System)
Route::get('/admin/dapur', function () {
    $orders = Order::with(['table', 'items.menu'])
        ->whereIn('status', ['pending', 'cooking'])
        ->orWhere(function ($query) {
            $query->where('status', 'completed')
                ->whereDate('created_at', today());
        })
        ->latest()
        ->get();

    return Inertia::render('Admin/DashboardDapur', [
        'initialOrders' => $orders,
    ]);
})->name('admin.dapur');

Route::get('/dapur', fn () => redirect()->route('admin.dapur'))->name('dapur');

Route::get('/admin/kasir', function () {
    $tables = Table::with(['orders' => function ($query) {
        $query->with(['items.menu'])->where('status', '!=', 'paid')->latest();
    }])->orderBy('table_number')->get()->map(fn (Table $table) => [
        'id' => $table->id,
        'table_number' => $table->table_number,
        'current_token' => $table->current_token,
        'active_order' => $table->orders->first(),
    ]);

    return Inertia::render('Admin/DashboardKasir', [
        'tables' => $tables,
        'menus' => Menu::orderBy('name')->get(),
    ]);
})->name('admin.kasir');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
