<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\DifferentiatorController;
use App\Http\Controllers\FeedCommentController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\GrowthController;
use App\Http\Controllers\GrowthEngineController;
use App\Http\Controllers\HabitScaleController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\Phase1Controller;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (prefix /api from bootstrap/app.php)
|--------------------------------------------------------------------------
| Mobile app: Sanctum bearer tokens. Rate limiters are defined in
| AppServiceProvider::configureRateLimiting(). Every route that moves money or reserves stock is
| 'idempotent': it requires an Idempotency-Key header and replays the first response for that key
| (App\Http\Middleware\EnforceIdempotency). Route parameters are plain ids:
| services resolve them through the viewer (visibility, ownership), never by
| implicit model binding, so a hidden post and a missing post look the same.
*/

Route::prefix('v1')->group(function (): void {
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])->middleware('throttle:stripe-webhook');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('admin/login', [AdminController::class, 'login'])->middleware('throttle:auth');
    Route::get('cards/{kind}/{id}', [GrowthController::class, 'card'])->middleware('throttle:feed-read');

    Route::middleware(['auth:sanctum', 'banned'])->group(function (): void {
        Route::get('feed', [FeedController::class, 'index'])->middleware('throttle:feed-read');
        Route::post('feed', [FeedController::class, 'store'])->middleware('throttle:feed-post');
        Route::delete('feed/{post}', [FeedController::class, 'destroy']);
        Route::post('feed/{post}/like', [FeedController::class, 'like'])->middleware('throttle:feed-like');
        Route::delete('feed/{post}/like', [FeedController::class, 'unlike'])->middleware('throttle:feed-like');
        Route::post('feed/{post}/share', [FeedController::class, 'share'])->middleware('throttle:feed-share');
        Route::get('feed/{post}/engagement', [FeedController::class, 'engagement'])->middleware('throttle:feed-read');
        Route::get('feed/{post}/comments', [FeedCommentController::class, 'index'])->middleware('throttle:feed-read');
        Route::post('feed/{post}/comments', [FeedCommentController::class, 'store'])->middleware('throttle:feed-comment');
        Route::delete('comments/{comment}', [FeedCommentController::class, 'destroy']);

        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

        Route::get('me', [ProfileController::class, 'me']);
        Route::post('users/{user}/friend-request', [SocialController::class, 'request'])->middleware('throttle:social');
        Route::post('users/{user}/friend-accept', [SocialController::class, 'accept'])->middleware('throttle:social');
        Route::post('users/{user}/friend-decline', [SocialController::class, 'decline'])->middleware('throttle:social');
        Route::post('users/{user}/follow', [SocialController::class, 'follow'])->middleware('throttle:social');
        Route::delete('users/{user}/follow', [SocialController::class, 'unfollow'])->middleware('throttle:social');
        Route::post('users/{user}/block', [SocialController::class, 'block'])->middleware('throttle:social');
        Route::delete('users/{user}/block', [SocialController::class, 'unblock'])->middleware('throttle:social');
        Route::get('users/{user}', [ProfileController::class, 'show']);
        Route::get('users/{user}/posts', [ProfileController::class, 'posts'])->middleware('throttle:feed-read');

        Route::get('hashtags', [HashtagController::class, 'index'])->middleware('throttle:hashtag-read');
        Route::get('hashtags/trending', [HashtagController::class, 'trending'])->middleware('throttle:hashtag-read');
        Route::get('hashtags/posts', [HashtagController::class, 'posts'])->middleware('throttle:hashtag-read');

        Route::get('cart', [CartController::class, 'index']);
        Route::put('cart/{product}', [CartController::class, 'put']);
        Route::delete('cart/{product}', [CartController::class, 'destroy']);
        Route::post('cart/checkout', [CartController::class, 'checkout'])->middleware('throttle:checkout');
        Route::get('addresses', [CartController::class, 'addresses']);
        Route::post('addresses', [CartController::class, 'storeAddress']);
        Route::delete('addresses/{address}', [CartController::class, 'destroyAddress']);

        Route::post('checkout', [CommerceController::class, 'checkout'])->middleware(['throttle:checkout', 'idempotent']);
        Route::post('squads', [CommerceController::class, 'openSquad'])->middleware('throttle:squad');
        Route::post('squads/{squad}/join', [CommerceController::class, 'joinSquad'])->middleware('throttle:squad');
        Route::get('me/rewards', [CommerceController::class, 'rewards']);
        Route::post('rewards/loot-box', [CommerceController::class, 'loot'])->middleware(['throttle:loot', 'idempotent']);
        Route::get('catalog/feed', [GrowthController::class, 'catalog'])->middleware('throttle:feed-read');
        Route::post('feed/{post}/watch', [CommerceController::class, 'watch'])->middleware('throttle:watch');
        Route::get('activity', [CommerceController::class, 'activity'])->middleware('throttle:feed-read');
        Route::get('products/{product}/try-on', [CommerceController::class, 'tryOn']);
        Route::get('users/{user}/store', [CommerceController::class, 'store'])->middleware('throttle:feed-read');
        Route::post('drops/live', [CommerceController::class, 'ensureDrop'])->middleware('throttle:feed-read');
        Route::get('drops/live', [CommerceController::class, 'ensureDrop'])->middleware('throttle:feed-read');

        Route::post('squads/authorize', [Phase1Controller::class, 'authorizeSquad'])->middleware(['throttle:squad', 'idempotent']);
        Route::post('squads/{squad}/authorize-join', [Phase1Controller::class, 'joinSquad'])->middleware(['throttle:squad', 'idempotent']);
        Route::post('squads/{squad}/settle', [Phase1Controller::class, 'settleSquad'])->middleware('throttle:squad');
        Route::get('s/{token}', [Phase1Controller::class, 'openReferral'])->middleware('throttle:feed-read');
        Route::get('me/ledger', [Phase1Controller::class, 'ledger']);
        Route::get('drop-hour', [Phase1Controller::class, 'dropHour'])->middleware('throttle:feed-read');
        Route::post('drop-hour/{drop}/reserve', [Phase1Controller::class, 'reserveDrop'])->middleware('throttle:checkout');
        Route::post('visual-search', [Phase1Controller::class, 'visualSearch'])->middleware('throttle:scan');
        Route::post('checkout/one-tap', [Phase1Controller::class, 'oneTap'])->middleware(['throttle:checkout', 'idempotent']);
        Route::get('orders', [Phase1Controller::class, 'orders']);
        Route::get('orders/{order}', [Phase1Controller::class, 'showOrder']);
        Route::post('orders/{order}/cancel', [Phase1Controller::class, 'cancelOrder'])->middleware(['throttle:checkout', 'idempotent']);
        Route::post('orders/{order}/sync', [Phase1Controller::class, 'syncOrder'])->middleware('throttle:payment-sync');
        Route::get('payment-methods/default', [Phase1Controller::class, 'paymentMethod']);

        Route::post('gifts', [HabitScaleController::class, 'createGift'])->middleware(['throttle:checkout', 'idempotent']);
        Route::get('gifts/{token}', [HabitScaleController::class, 'showGift'])->middleware('throttle:feed-read');
        Route::post('gifts/{token}/claim', [HabitScaleController::class, 'claimGift'])->middleware('throttle:checkout');
        Route::get('ritual', [HabitScaleController::class, 'ritual']);
        Route::post('ritual/check-in', [HabitScaleController::class, 'checkIn'])->middleware('throttle:loot');
        Route::post('ritual/missions/{kind}', [HabitScaleController::class, 'mission'])->middleware('throttle:loot');
        Route::post('ritual/spin', [HabitScaleController::class, 'spin'])->middleware('throttle:loot');
        Route::post('try-on', [HabitScaleController::class, 'renderTryOn'])->middleware('throttle:scan');
        Route::post('try-on/{render}/poll', [HabitScaleController::class, 'openPoll'])->middleware('throttle:squad');
        Route::get('try-on/polls/{token}', [HabitScaleController::class, 'showPoll'])->middleware('throttle:feed-read');
        Route::post('try-on/polls/{token}/vote', [HabitScaleController::class, 'vote'])->middleware('throttle:squad');
        Route::get('closet', [HabitScaleController::class, 'closet'])->middleware('throttle:feed-read');
        Route::post('closet/{item}/list', [HabitScaleController::class, 'listCloset'])->middleware('throttle:checkout');
        Route::get('auctions/live', [HabitScaleController::class, 'auction'])->middleware('throttle:feed-read');
        Route::post('auctions/{auction}/bids', [HabitScaleController::class, 'bid'])->middleware('throttle:checkout');
        Route::get('blind-boxes', [HabitScaleController::class, 'blindBox'])->middleware('throttle:feed-read');
        Route::post('blind-boxes/{box}/pull', [HabitScaleController::class, 'pullBox'])->middleware(['throttle:loot', 'idempotent']);

        Route::get('live', [GrowthController::class, 'activities']);
        Route::post('rooms', [GrowthController::class, 'openRoom'])->middleware('throttle:squad');
        Route::post('rooms/{token}/join', [GrowthController::class, 'joinRoom'])->middleware('throttle:squad');
        Route::post('rooms/{token}/vote', [GrowthController::class, 'voteRoom'])->middleware('throttle:squad');
        Route::get('agent', [GrowthController::class, 'brief']);
        Route::put('agent', [GrowthController::class, 'saveBrief']);
        Route::post('agent/propose', [GrowthController::class, 'propose'])->middleware('throttle:loot');
        Route::post('agent/alerts/{alert}/undo', [GrowthController::class, 'undoAlert']);
        Route::get('shows', [GrowthController::class, 'shows']);
        Route::post('shows/{show}/remind', [GrowthController::class, 'remind'])->middleware('throttle:squad');
        Route::get('places', [GrowthController::class, 'places']);
        Route::post('places/{place}/join', [GrowthController::class, 'joinPlace'])->middleware('throttle:squad');
        Route::get('reputation', [GrowthController::class, 'reputation']);
        Route::get('season', [GrowthController::class, 'season']);
        Route::post('season/quests/{quest}', [GrowthController::class, 'advanceQuest']);
        Route::get('products/{product}/proof', [GrowthController::class, 'proof']);
        Route::post('hauls', [GrowthController::class, 'haul'])->middleware('throttle:feed-post');
        Route::get('trust', [GrowthController::class, 'trust']);
        Route::put('trust/limit', [GrowthController::class, 'setLimit']);
        Route::put('trust/streaks', [GrowthController::class, 'streaks']);
        Route::post('trust/mute', [GrowthController::class, 'mute']);
        Route::post('trust/pause', [GrowthController::class, 'pause']);
        Route::post('trust/unpause', [GrowthController::class, 'unpause']);
        Route::post('trust/delete', [GrowthController::class, 'deleteAccount']);
        Route::post('trust/export', [GrowthController::class, 'export']);
        Route::post('trust/age', [GrowthController::class, 'age']);
        Route::post('orders/{order}/withdraw', [GrowthController::class, 'withdraw'])->middleware(['throttle:checkout', 'idempotent']);

        Route::get('taste', [GrowthEngineController::class, 'taste']);
        Route::put('taste', [GrowthEngineController::class, 'saveTaste']);
        Route::post('feed/{post}/tap', [GrowthEngineController::class, 'tap'])->middleware('throttle:feed-like');
        Route::post('products/{product}/save', [GrowthEngineController::class, 'saveProduct'])->middleware('throttle:feed-like');
        Route::get('products/{product}/offers', [GrowthEngineController::class, 'offers'])->middleware('throttle:feed-read');
        Route::get('products/{product}/related', [GrowthEngineController::class, 'related'])->middleware('throttle:feed-read');
        Route::get('seller', [GrowthEngineController::class, 'seller']);
        Route::post('seller', [GrowthEngineController::class, 'registerSeller'])->middleware('throttle:checkout');
        Route::post('seller/refresh', [GrowthEngineController::class, 'refreshSeller']);
        Route::post('seller/orders/{order}/ship', [GrowthEngineController::class, 'ship'])->middleware('throttle:checkout');
        Route::post('seller/catalog', [GrowthEngineController::class, 'syncCatalog'])->middleware('throttle:feed-post');
        Route::post('feed/{post}/auto-tag', [DifferentiatorController::class, 'autoTag'])->middleware('throttle:scan');
        Route::post('feed/{post}/synthetic', [DifferentiatorController::class, 'synthetic'])->middleware('throttle:feed-post');
        Route::get('orders/{order}/price-protection', [DifferentiatorController::class, 'priceProtection']);
        Route::get('products/{product}/reviews', [DifferentiatorController::class, 'reviews'])->middleware('throttle:feed-read');
        Route::post('products/{product}/reviews', [DifferentiatorController::class, 'review'])->middleware('throttle:feed-comment');
        Route::post('products/{product}/questions', [DifferentiatorController::class, 'ask'])->middleware('throttle:feed-comment');
        Route::post('questions/{question}/answer', [DifferentiatorController::class, 'answer'])->middleware('throttle:feed-comment');
        Route::get('payment-methods', [Phase1Controller::class, 'paymentMethods']);
        Route::delete('payment-methods/{method}', [Phase1Controller::class, 'destroyPaymentMethod']);
        Route::post('payment-methods/setup', [Phase1Controller::class, 'beginSetup'])->middleware(['throttle:checkout', 'idempotent']);
        Route::post('payment-methods/setup/test-card', [Phase1Controller::class, 'saveTestCard'])->middleware(['throttle:checkout', 'idempotent']);
        Route::post('payment-methods/confirm', [Phase1Controller::class, 'confirmSetup'])->middleware(['throttle:checkout', 'idempotent']);
    });

    Route::prefix('admin')->middleware(['auth:admin', 'admin', 'banned'])->group(function (): void {
        Route::get('auctions', [AdminController::class, 'auctions']);
        Route::post('auctions', [AdminController::class, 'createAuction']);
        Route::post('auctions/{auction}/schedule', [AdminController::class, 'scheduleAuction']);
        Route::post('auctions/{auction}/end', [AdminController::class, 'endAuction']);
        Route::get('products', [AdminController::class, 'products']);
        Route::post('products', [AdminController::class, 'createProduct']);
        Route::patch('products/{product}', [AdminController::class, 'updateProduct']);
        Route::get('sellers', [AdminController::class, 'sellers']);
        Route::post('sellers/{user}/verify', [AdminController::class, 'verifySeller']);
        Route::post('sellers/{user}/reject', [AdminController::class, 'rejectSeller']);
        Route::get('posts', [AdminController::class, 'posts']);
        Route::post('posts/{post}/hide', [AdminController::class, 'hidePost']);
        Route::post('posts/{post}/unhide', [AdminController::class, 'unhidePost']);
        Route::delete('posts/{post}', [AdminController::class, 'removePost']);
        Route::get('comments', [AdminController::class, 'comments']);
        Route::post('comments/{comment}/hide', [AdminController::class, 'hideComment']);
        Route::post('comments/{comment}/unhide', [AdminController::class, 'unhideComment']);
        Route::delete('comments/{comment}', [AdminController::class, 'removeComment']);
        Route::get('users', [AdminController::class, 'users']);
        Route::post('users/{user}/ban', [AdminController::class, 'ban']);
        Route::post('users/{user}/unban', [AdminController::class, 'unban']);
    });
});
