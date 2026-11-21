<?php

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\CustomerStatusController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\StaffOrderController;
use App\Http\Middleware\EnsureCustomerIsActive;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/sales/create')->name('home');
Route::match(['get', 'post'], '/login', [PosController::class, 'login'])->middleware('guest:web')->name('login');
Route::middleware('auth:web')->group(function () {
    Route::get('/orders', [StaffOrderController::class, 'index'])->name('orders.index');
    Route::post('/orders/{order}/cancel', [StaffOrderController::class, 'cancel'])->whereNumber('order')->block(10, 10)->name('orders.cancel');
    Route::get('/orders/{order}', [StaffOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('/orders/{order}/complete', [StaffOrderController::class, 'complete'])->whereNumber('order')->block(10, 10)->name('orders.complete');
    Route::get('/receipts/{sale}', [ReceiptController::class, 'staff'])->whereNumber('sale')->name('receipts.show');
    Route::post('/customers/{customer}/access', [CustomerStatusController::class, 'update'])->whereNumber('customer')->name('customers.access');
    Route::post('/customers/{id}/restore', [CustomerStatusController::class, 'restore'])->whereNumber('id')->name('customers.restore');
    Route::post('/logout', [PosController::class, 'logout'])->name('logout');
    Route::get('/sales/create', [PosController::class, 'saleForm'])->name('sales.create');
    Route::post('/sales', [PosController::class, 'recordSale'])->block(10, 10)->name('sales.store');
    Route::get('/sales', [PosController::class, 'history'])->name('sales.index');
    Route::get('/{kind}', [PosController::class, 'index'])->whereIn('kind', ['products', 'customers', 'staff'])->name('records.index');
    Route::get('/{kind}/create', [PosController::class, 'form'])->whereIn('kind', ['products', 'customers', 'staff'])->name('records.create');
    Route::get('/{kind}/{id}/edit', [PosController::class, 'form'])->whereIn('kind', ['products', 'customers', 'staff'])->whereNumber('id')->name('records.edit');
    Route::post('/{kind}', [PosController::class, 'save'])->whereIn('kind', ['products', 'customers', 'staff'])->name('records.store');
    Route::put('/{kind}/{id}', [PosController::class, 'save'])->whereIn('kind', ['products', 'customers', 'staff'])->whereNumber('id')->name('records.update');
    Route::delete('/{kind}/{id}', [PosController::class, 'destroy'])->whereIn('kind', ['products', 'customers', 'staff'])->whereNumber('id')->name('records.destroy');
});

Route::prefix('customer')->name('customer.')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Route::get('/register', [CustomerAuthController::class, 'registration'])->name('register');
        Route::post('/register', [CustomerAuthController::class, 'register'])->middleware('throttle:10,1')->block(10, 10)->name('register.store');
        Route::get('/login', [CustomerAuthController::class, 'loginForm'])->name('login');
        Route::post('/login', [CustomerAuthController::class, 'login'])->block(10, 10)->name('login.store');
    });
    Route::middleware(['auth:customer', EnsureCustomerIsActive::class])->group(function () {
        Route::get('/shop', [CustomerOrderController::class, 'catalog'])->name('shop');
        Route::get('/cart', [CustomerOrderController::class, 'cart'])->name('cart');
        Route::put('/cart/{product}', [CustomerOrderController::class, 'updateCart'])->whereNumber('product')->withTrashed()->block(10, 10)->name('cart.update');
        Route::post('/orders', [CustomerOrderController::class, 'store'])->block(10, 10)->name('orders.store');
        Route::get('/orders', [CustomerOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{id}', [CustomerOrderController::class, 'show'])->whereNumber('id')->name('orders.show');
        Route::post('/orders/{id}/cancel', [CustomerOrderController::class, 'cancel'])->whereNumber('id')->block(10, 10)->name('orders.cancel');
        Route::get('/account', [CustomerAuthController::class, 'account'])->name('account');
        Route::put('/account', [CustomerPortalController::class, 'update'])->name('profile.update');
        Route::put('/password', [CustomerPortalController::class, 'password'])->middleware('throttle:10,1')->name('password.update');
        Route::get('/purchases', [CustomerPortalController::class, 'purchases'])->name('purchases');
        Route::get('/receipts/{id}', [ReceiptController::class, 'customer'])->whereNumber('id')->name('receipts.show');
        Route::post('/logout', [CustomerAuthController::class, 'logout'])->name('logout');
    });
});
