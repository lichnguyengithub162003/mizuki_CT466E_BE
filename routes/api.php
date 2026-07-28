<?php

use App\Http\Controllers\Api\V1\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\V1\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Cashier\PosController;
use App\Http\Controllers\Api\V1\Catalog\BrandController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\ClinicController;
use App\Http\Controllers\Api\V1\Customer\AppointmentController;
use App\Http\Controllers\Api\V1\Customer\CartController;
use App\Http\Controllers\Api\V1\Customer\CartPromotionController;
use App\Http\Controllers\Api\V1\Customer\FavoriteController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\OrderPaymentController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\WalletController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\Payment\VnPayController;
use App\Http\Controllers\Api\V1\Technician\AppointmentController as TechnicianAppointmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    // Auth routes
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('register', [CustomerAuthController::class, 'register'])
            ->middleware('throttle:auth.register')
            ->name('register');
        Route::post('login', [CustomerAuthController::class, 'login'])
            ->middleware('throttle:auth.login')
            ->name('login');
        Route::post('staff-login', [CustomerAuthController::class, 'staffLogin'])
            ->middleware('throttle:auth.login')
            ->name('staff-login');
        Route::get('me', [CustomerAuthController::class, 'me'])
            ->middleware('auth:sanctum')
            ->name('me');
        Route::post('logout', [CustomerAuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('logout');
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])
            ->middleware('throttle:auth.register')
            ->name('forgot-password');
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])
            ->middleware('throttle:auth.register')
            ->name('reset-password');
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])
            ->middleware('throttle:auth.login')
            ->name('google.redirect');
        Route::get('google/callback', [GoogleAuthController::class, 'callback'])
            ->middleware('throttle:auth.login')
            ->name('google.callback');
    });

    // Customer routes
    Route::prefix('customer')->name('customer.')->middleware('auth:sanctum')->group(function (): void {
        // Profile
        Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar');
        Route::patch('profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');

        // Wallet
        Route::prefix('wallet')->name('wallet.')->middleware('role:customer')->group(function (): void {
            Route::get('/', [WalletController::class, 'show'])->name('show');
            Route::get('transactions', [WalletController::class, 'transactions'])->name('transactions');
        });

        // Appointments
        Route::prefix('appointments')->name('appointments.')->middleware('role:customer')->group(function (): void {
            Route::get('/', [AppointmentController::class, 'index'])->name('index');
            Route::post('/', [AppointmentController::class, 'store'])
                ->middleware('throttle:appointments.create')
                ->name('store');
            Route::post('{id}/cancel', [AppointmentController::class, 'cancel'])->name('cancel');
            Route::get('{id}', [AppointmentController::class, 'show'])->name('show');
        });

        // Addresses
        Route::get('addresses', [ProfileController::class, 'indexAddress'])->name('addresses.index');
        Route::post('addresses', [ProfileController::class, 'storeAddress'])->name('addresses.store');
        Route::patch('addresses/{id}', [ProfileController::class, 'updateAddress'])->name('addresses.update');
        Route::delete('addresses/{id}', [ProfileController::class, 'destroyAddress'])->name('addresses.destroy');
        Route::patch('addresses/{id}/default', [ProfileController::class, 'setDefaultAddress'])->name('addresses.set-default');

        // Product favorites
        Route::prefix('favorites')->name('favorites.')->middleware('role:customer')->group(function (): void {
            Route::get('/', [FavoriteController::class, 'index'])->name('index');
            Route::post('/', [FavoriteController::class, 'store'])->name('store');
            Route::delete('{product_id}', [FavoriteController::class, 'destroy'])->name('destroy');
        });

        // Shopping cart
        Route::prefix('cart')->name('cart.')->middleware('role:customer')->group(function (): void {
            Route::get('/', [CartController::class, 'index'])->name('index');
            Route::post('items', [CartController::class, 'store'])->name('items.store');
            Route::patch('items/{id}', [CartController::class, 'update'])->name('items.update');
            Route::delete('items/{id}', [CartController::class, 'destroy'])->name('items.destroy');
            Route::patch('branch', [CartController::class, 'selectBranch'])->name('branch.update');
            Route::get('promotions', [CartPromotionController::class, 'index'])->name('promotions.index');
            Route::post('promotion', [CartPromotionController::class, 'store'])->name('promotion.store');
            Route::delete('promotion', [CartPromotionController::class, 'destroy'])->name('promotion.destroy');
        });

        // Orders
        Route::prefix('orders')->name('orders.')->middleware('role:customer')->group(function (): void {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::post('/', [OrderController::class, 'store'])->name('store');
            Route::post('{id}/cancel', [OrderController::class, 'cancel'])->name('cancel');
            Route::post('{id}/refund', [OrderController::class, 'requestRefund'])->name('refund');
            Route::get('{id}/payment', [OrderPaymentController::class, 'show'])->name('payment.show');
            Route::post('{id}/payment/vnpay', [VnPayController::class, 'create'])
                ->middleware('throttle:10,1')
                ->name('payment.vnpay.create');
            Route::get('{id}', [OrderController::class, 'show'])->name('show');
        });
    });

    // VNPay callbacks are public and authenticated by the gateway signature.
    Route::prefix('payments/vnpay')->name('payments.vnpay.')->group(function (): void {
        Route::get('return', [VnPayController::class, 'handleReturn'])
            ->middleware('throttle:60,1')
            ->name('return');
        Route::get('ipn', [VnPayController::class, 'ipn'])->name('ipn');
    });

    // Admin routes
    Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function (): void {
        Route::prefix('promotions')
            ->name('promotions.')
            ->middleware('role:branch_manager,super_admin')
            ->group(function (): void {
                Route::get('/', [AdminPromotionController::class, 'index'])->name('index');
                Route::post('/', [AdminPromotionController::class, 'store'])->name('store');
                Route::patch('{id}', [AdminPromotionController::class, 'update'])->name('update');
                Route::delete('{id}', [AdminPromotionController::class, 'destroy'])->name('destroy');
                Route::get('{id}/usage-stats', [AdminPromotionController::class, 'usageStats'])->name('usage-stats');
            });

        Route::prefix('orders')
            ->name('orders.')
            ->middleware('role:branch_manager,super_admin')
            ->group(function (): void {
                Route::get('/', [AdminOrderController::class, 'index'])->name('index');
                Route::post('{id}/confirm', [AdminOrderController::class, 'confirm'])->name('confirm');
                Route::get('{id}', [AdminOrderController::class, 'show'])->name('show');
            });

        Route::prefix('refunds')
            ->name('refunds.')
            ->middleware('role:branch_manager,super_admin')
            ->group(function (): void {
                Route::get('/', [AdminRefundController::class, 'index'])->name('index');
                Route::post('{id}/approve', [AdminRefundController::class, 'approve'])->name('approve');
                Route::post('{id}/reject', [AdminRefundController::class, 'reject'])->name('reject');
                Route::post('{id}/wallet-payout', [AdminRefundController::class, 'walletPayout'])->name('wallet-payout');
                Route::get('{id}', [AdminRefundController::class, 'show'])->name('show');
            });

        Route::prefix('appointments')
            ->name('appointments.')
            ->middleware('role:branch_manager,super_admin')
            ->group(function (): void {
                Route::get('/', [AdminAppointmentController::class, 'index'])->name('index');
                Route::post('walk-in', [AdminAppointmentController::class, 'walkIn'])->name('walk-in');
                Route::post('{id}/confirm', [AdminAppointmentController::class, 'confirm'])->name('confirm');
                Route::post('{id}/assign-technician', [AdminAppointmentController::class, 'assignTechnician'])->name('assign-technician');
                Route::post('{id}/start', [AdminAppointmentController::class, 'start'])->name('start');
                Route::post('{id}/complete', [AdminAppointmentController::class, 'complete'])->name('complete');
                Route::post('{id}/cancel', [AdminAppointmentController::class, 'cancel'])->name('cancel');
                Route::get('{id}', [AdminAppointmentController::class, 'show'])->name('show');
            });
    });

    Route::prefix('technician/appointments')
        ->name('technician.appointments.')
        ->middleware(['auth:sanctum', 'role:technician'])
        ->group(function (): void {
            Route::get('/', [TechnicianAppointmentController::class, 'index'])->name('index');
            Route::post('{id}/start', [TechnicianAppointmentController::class, 'start'])->name('start');
            Route::post('{id}/complete', [TechnicianAppointmentController::class, 'complete'])->name('complete');
            Route::get('{id}', [TechnicianAppointmentController::class, 'show'])->name('show');
        });

    // Cashier POS routes
    Route::prefix('cashier/pos')
        ->name('cashier.pos.')
        ->middleware(['auth:sanctum', 'role:cashier'])
        ->group(function (): void {
            Route::get('products', [PosController::class, 'products'])->name('products.index');
            Route::get('products/barcode/{barcode}', [PosController::class, 'barcode'])->name('products.barcode');
            Route::post('sessions', [PosController::class, 'storeSession'])->name('sessions.store');
            Route::get('sessions/{code}', [PosController::class, 'showSession'])->name('sessions.show');
            Route::post('sessions/{code}/items', [PosController::class, 'storeItem'])->name('sessions.items.store');
            Route::patch('sessions/{code}/items/{variantId}', [PosController::class, 'updateItem'])
                ->name('sessions.items.update');
            Route::delete('sessions/{code}/items/{variantId}', [PosController::class, 'destroyItem'])
                ->name('sessions.items.destroy');
            Route::patch('sessions/{code}/customer', [PosController::class, 'updateCustomer'])
                ->name('sessions.customer.update');
            Route::patch('sessions/{code}/payment-method', [PosController::class, 'updatePaymentMethod'])
                ->name('sessions.payment-method.update');
            Route::post('sessions/{code}/confirm', [PosController::class, 'confirm'])
                ->name('sessions.confirm');
        });

    Route::get('pos/display/{code}', [PosController::class, 'display'])->name('pos.display');

    // Location routes
    Route::prefix('locations')->name('locations.')->group(function (): void {
        Route::get('provinces', [LocationController::class, 'provinces'])->name('provinces');
        Route::get('provinces/{provinceId}/districts', [LocationController::class, 'districts'])->name('districts');
        Route::get('districts/{districtId}/wards', [LocationController::class, 'wards'])->name('wards');
    });

    // Public clinic routes
    Route::prefix('clinics')->name('clinics.')->group(function (): void {
        Route::get('/', [ClinicController::class, 'index'])->name('index');
        Route::get('{branchId}/services', [ClinicController::class, 'services'])->name('services.index');
        Route::get('{branchId}/services/{serviceId}/slots', [ClinicController::class, 'slots'])->name('services.slots');
    });

    // Public catalog routes
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('brands/{slug}', [BrandController::class, 'show'])->name('brands.show');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/search', [ProductController::class, 'search'])->name('products.search');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
});
