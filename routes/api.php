<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\Admin\AccountingController;
use App\Http\Controllers\Api\Admin\ActivityLogController;
use App\Http\Controllers\Api\Admin\AlertController;
use App\Http\Controllers\Api\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\ContactMessageController;
use App\Http\Controllers\Api\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\Admin\CustomerMessageController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\ExportController;
use App\Http\Controllers\Api\Admin\FaqController as AdminFaqController;
use App\Http\Controllers\Api\Admin\FlashSaleController as AdminFlashSaleController;
use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\FlashSaleController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/flash-sales', [FlashSaleController::class, 'index']);
Route::get('/faqs', [FaqController::class, 'index']);
Route::get('/settings', [SettingsController::class, 'index']);
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1');
Route::post('/coupons/validate', [CouponController::class, 'validateCode'])->middleware('throttle:20,1');

Route::post('/auth/start', [AuthController::class, 'start'])->middleware('throttle:5,1');
Route::post('/auth/register/send', [AuthController::class, 'registerSendOtp'])->middleware('throttle:3,1');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:3,1');
Route::post('/auth/otp/confirm', [AuthController::class, 'confirmOtp'])->middleware('throttle:5,1');
Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/password/forgot', [AuthController::class, 'sendPasswordResetOtp'])->middleware('throttle:3,1');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::post('/auth/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:5,1');
Route::get('/payment/zarinpal/callback', [PaymentController::class, 'callback'])->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{product}', [WishlistController::class, 'destroy']);

    Route::get('/cart', [CartController::class, 'index']);
    Route::put('/cart', [CartController::class, 'sync']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay'])->middleware('throttle:10,1');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:10,1');

    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::post('/support/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:8,1');
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])->middleware('throttle:20,1');
    Route::patch('/support/tickets/{ticket}/close', [SupportTicketController::class, 'close']);
});
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/accounting/summary', [AccountingController::class, 'summary']);
    Route::get('/accounting/catalog', [AccountingController::class, 'catalog']);
    Route::get('/accounting/expense-categories', [AccountingController::class, 'expenseCategories']);
    Route::get('/accounting/purchases', [AccountingController::class, 'purchases']);
    Route::post('/accounting/purchases', [AccountingController::class, 'storePurchase']);
    Route::put('/accounting/purchases/{purchase}', [AccountingController::class, 'updatePurchase']);
    Route::delete('/accounting/purchases/{purchase}', [AccountingController::class, 'destroyPurchase']);
    Route::get('/accounting/expenses', [AccountingController::class, 'expenses']);
    Route::post('/accounting/expenses', [AccountingController::class, 'storeExpense']);
    Route::put('/accounting/expenses/{expense}', [AccountingController::class, 'updateExpense']);
    Route::delete('/accounting/expenses/{expense}', [AccountingController::class, 'destroyExpense']);
    Route::get('/export/orders', [ExportController::class, 'orders']);
    Route::get('/export/customers', [ExportController::class, 'customers']);
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('/alerts/low-stock', [AlertController::class, 'lowStock']);

    Route::get('/media', [MediaController::class, 'index']);
    Route::post('/media/upload', [MediaController::class, 'upload']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

    Route::get('/settings', [AdminSettingController::class, 'index']);
    Route::put('/settings', [AdminSettingController::class, 'update']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{user}', [CustomerController::class, 'show']);
    Route::get('/messages/audiences', [CustomerMessageController::class, 'audiences']);
    Route::get('/messages/recipients', [CustomerMessageController::class, 'recipients']);
    Route::post('/messages/send', [CustomerMessageController::class, 'send'])->middleware('throttle:8,1');
    Route::get('/messages/history', [CustomerMessageController::class, 'history']);

    Route::get('/coupons', [AdminCouponController::class, 'index']);
    Route::post('/coupons', [AdminCouponController::class, 'store']);
    Route::post('/coupons/generate', [AdminCouponController::class, 'generate']);
    Route::put('/coupons/{coupon}', [AdminCouponController::class, 'update']);
    Route::delete('/coupons/{coupon}', [AdminCouponController::class, 'destroy']);

    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

    Route::get('/products', [AdminProductController::class, 'index']);
    Route::get('/products/{product}', [AdminProductController::class, 'show']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::put('/products/{product}', [AdminProductController::class, 'update']);
    Route::delete('/products/{product}', [AdminProductController::class, 'destroy']);

    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
    Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
    Route::patch('/orders/{order}/notes', [AdminOrderController::class, 'updateNotes']);

    Route::get('/banners', [AdminBannerController::class, 'index']);
    Route::post('/banners', [AdminBannerController::class, 'store']);
    Route::put('/banners/{banner}', [AdminBannerController::class, 'update']);
    Route::delete('/banners/{banner}', [AdminBannerController::class, 'destroy']);

    Route::get('/flash-sales', [AdminFlashSaleController::class, 'index']);
    Route::post('/flash-sales', [AdminFlashSaleController::class, 'store']);
    Route::put('/flash-sales/{flashSale}', [AdminFlashSaleController::class, 'update']);
    Route::delete('/flash-sales/{flashSale}', [AdminFlashSaleController::class, 'destroy']);

    Route::get('/faqs', [AdminFaqController::class, 'index']);
    Route::post('/faqs', [AdminFaqController::class, 'store']);
    Route::put('/faqs/{faq}', [AdminFaqController::class, 'update']);
    Route::delete('/faqs/{faq}', [AdminFaqController::class, 'destroy']);

    Route::get('/contact-messages', [ContactMessageController::class, 'index']);
    Route::patch('/contact-messages/{contactMessage}/read', [ContactMessageController::class, 'markRead']);
    Route::post('/contact-messages/mark-all-read', [ContactMessageController::class, 'markAllRead']);
    Route::delete('/contact-messages/{contactMessage}', [ContactMessageController::class, 'destroy']);

    Route::get('/support/tickets', [AdminSupportTicketController::class, 'index']);
    Route::get('/support/tickets/{ticket}', [AdminSupportTicketController::class, 'show']);
    Route::post('/support/tickets/{ticket}/messages', [AdminSupportTicketController::class, 'reply'])->middleware('throttle:30,1');
    Route::patch('/support/tickets/{ticket}', [AdminSupportTicketController::class, 'update']);

    Route::put('/password', [AuthController::class, 'updateAdminPassword']);
});
