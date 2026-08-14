<?php

use App\Http\Controllers\Api\V1\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\V1\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\V1\Admin\SkinProfileController as AdminSkinProfileController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\Cashier\PosController;
use App\Http\Controllers\Api\V1\Catalog\BrandController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\ClinicController;
use App\Http\Controllers\Api\V1\Customer\AppointmentController;
use App\Http\Controllers\Api\V1\Customer\BrandController as CustomerBrandController;
use App\Http\Controllers\Api\V1\Customer\CartController;
use App\Http\Controllers\Api\V1\Customer\CartPromotionController;
use App\Http\Controllers\Api\V1\Customer\FavoriteController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\OrderPaymentController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\ShippingController;
use App\Http\Controllers\Api\V1\Customer\SkinProfileController as CustomerSkinProfileController;
use App\Http\Controllers\Api\V1\Customer\WalletController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\Payment\VnPayController;
use App\Http\Controllers\Api\V1\Technician\AppointmentController as TechnicianAppointmentController;
use App\Http\Controllers\Api\V1\Technician\SkinProfileController as TechnicianSkinProfileController;
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
            ->block(10, 10)
            ->name('me');
        Route::post('logout', [CustomerAuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->block(10, 10)
            ->name('logout');
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])
            ->middleware('throttle:password.recovery.request')
            ->name('forgot-password');
        Route::post('forgot-password/verify', [CustomerAuthController::class, 'verifyPasswordRecoveryOtp'])
            ->middleware('throttle:password.recovery.verify')
            ->name('forgot-password.verify');
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])
            ->middleware('throttle:password.recovery.reset')
            ->name('reset-password');

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

        Route::middleware('role:customer')->group(function (): void {
            Route::get('skin-profile', [CustomerSkinProfileController::class, 'show'])->name('skin-profile.show');
            Route::put('skin-profile', [CustomerSkinProfileController::class, 'update'])->name('skin-profile.update');
            Route::post('brands/{brand}/follow', [CustomerBrandController::class, 'follow'])->name('brands.follow');
            Route::delete('brands/{brand}/follow', [CustomerBrandController::class, 'unfollow'])->name('brands.unfollow');
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

        // Shipping quote
        Route::post('shipping/quote', [ShippingController::class, 'quote'])
            ->middleware('role:customer')
            ->name('shipping.quote');
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

    Route::post('shipping/ghn/webhook', [ShippingController::class, 'webhook'])
        ->name('shipping.ghn.webhook');

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
                Route::post('{id}/shipment', [AdminOrderController::class, 'createShipment'])->name('shipment.create');
                Route::post('{id}/shipment/cancel', [AdminOrderController::class, 'cancelShipment'])
                    ->name('shipment.cancel');
                Route::post('{id}/shipment/label', [AdminOrderController::class, 'shipmentLabel'])
                    ->name('shipment.label');
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

        Route::get('customers/{customer}/skin-profile', [AdminSkinProfileController::class, 'show'])
            ->middleware('role:branch_manager,super_admin')
            ->name('customers.skin-profile.show');
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

    Route::get('technician/customers/{customer}/skin-profile', [TechnicianSkinProfileController::class, 'show'])
        ->middleware(['auth:sanctum', 'role:technician'])
        ->name('technician.customers.skin-profile.show');
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

    // Public branch routes

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');

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
    Route::get('products/{slug}/reviews', [ProductController::class, 'reviews'])->name('products.reviews');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
});
