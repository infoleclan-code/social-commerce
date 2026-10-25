<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Compare\FakeOfferProvider;
use App\Services\Compare\OfferProvider;
use App\Services\Compare\SerpApiShoppingProvider;
use App\Services\Compare\UnavailableOfferProvider;
use App\Services\Media\Contracts\VideoProcessor;
use App\Services\Media\FakeImageFetcher;
use App\Services\Media\FfmpegVideoProcessor;
use App\Services\Media\ImageFetcher;
use App\Services\Media\PassthroughVideoProcessor;
use App\Services\Media\SafeImageFetcher;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\FakeSellerPayouts;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SellerPayouts;
use App\Services\Payments\StripePaymentGateway;
use App\Services\Payments\StripeSellerPayouts;
use App\Services\Vision\LocalVisionDescriber;
use App\Services\Vision\RemoteVisionDescriber;
use App\Services\Vision\VisionDescriber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tests never call a vision model (deterministic, and no test photo leaves the machine).
        $this->app->singleton(VisionDescriber::class, function (Application $app): VisionDescriber {
            if ($app->environment('testing')) {
                return new LocalVisionDescriber;
            }
            $timeout = (int) config('services.vision.timeout_seconds', 8);
            $connect = (int) config('services.vision.connect_timeout_seconds', 3);
            $openai = (string) config('services.openai.key');
            if ($openai !== '') {
                return new RemoteVisionDescriber('openai', $openai, (string) config('services.openai.vision_model'), $timeout, $connect);
            }
            $anthropic = (string) config('services.anthropic.key');
            if ($anthropic !== '') {
                return new RemoteVisionDescriber('anthropic', $anthropic, (string) config('services.anthropic.vision_model'), $timeout, $connect);
            }

            return new LocalVisionDescriber;
        });
        $this->app->singleton(PaymentGateway::class, function (Application $app): PaymentGateway {
            if ($app->environment('testing') || (! $app->environment('production') && (string) config('services.stripe.secret') === '')) {
                return new FakePaymentGateway;
            }

            return new StripePaymentGateway(
                (string) config('services.stripe.secret'),
                (int) config('services.stripe.timeout_seconds', 20),
                (int) config('services.stripe.connect_timeout_seconds', 5),
                (int) config('services.stripe.max_network_retries', 2),
                config('services.stripe.api_version'),
            );
        });
        $this->app->singleton(SellerPayouts::class, function (Application $app): SellerPayouts {
            if ($app->environment('testing') || (! $app->environment('production') && (string) config('services.stripe.secret') === '')) {
                return new FakeSellerPayouts;
            }

            return new StripeSellerPayouts((string) config('services.stripe.secret'));
        });
        $this->app->singleton(ImageFetcher::class, fn (Application $app): ImageFetcher => $app->environment('testing')
            ? new FakeImageFetcher
            : new SafeImageFetcher);
        $this->app->singleton(OfferProvider::class, function (): OfferProvider {
            if ((string) config('compare.provider') === 'serpapi') {
                $key = (string) config('compare.serpapi_key');

                return $key === '' ? new UnavailableOfferProvider : new SerpApiShoppingProvider($key);
            }

            return new FakeOfferProvider;
        });
        $this->app->bind(VideoProcessor::class, fn (Application $app) => config('feed.video.processor') === 'passthrough'
            ? new PassthroughVideoProcessor
            : new FfmpegVideoProcessor(
                $app->make(Factory::class),
                (string) config('feed.video.ffmpeg_binary'),
                (string) config('feed.video.ffprobe_binary'),
                (int) config('feed.video.process_timeout_seconds'),
            ));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->configureRateLimiting();
    }

    /** Per-user limits; the response follows the API error contract. */
    private function configureRateLimiting(): void
    {
        $limited = static fn (Request $request, array $headers): JsonResponse => new JsonResponse(
            ['message' => 'Too many requests. Slow down and try again shortly.', 'code' => 'RATE_LIMITED'],
            429,
            $headers,
        );
        $key = static fn (Request $request): string => (string) ($request->user()?->getKey() ?? self::hmac((string) $request->ip()));
        $emailKey = static fn (Request $request): string => self::hmac(strtolower(trim((string) $request->input('email', ''))));

        RateLimiter::for('feed-read', fn (Request $request) => Limit::perMinute(240)->by($key($request))->response($limited));
        RateLimiter::for('feed-post', fn (Request $request) => Limit::perHour((int) config('feed.limits.posts_per_hour'))->by($key($request))->response($limited));
        RateLimiter::for('feed-like', fn (Request $request) => Limit::perMinute((int) config('feed.limits.likes_per_minute'))->by($key($request))->response($limited));
        RateLimiter::for('feed-comment', fn (Request $request) => Limit::perMinute((int) config('feed.limits.comments_per_minute'))->by($key($request))->response($limited));
        RateLimiter::for('feed-share', fn (Request $request) => Limit::perMinute((int) config('feed.limits.shares_per_minute'))->by($key($request))->response($limited));
        RateLimiter::for('hashtag-read', fn (Request $request) => Limit::perMinute(60)->by($key($request))->response($limited));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($key($request))->response($limited));
        RateLimiter::for('squad', fn (Request $request) => Limit::perMinute(20)->by($key($request))->response($limited));
        RateLimiter::for('loot', fn (Request $request) => Limit::perMinute(6)->by($key($request))->response($limited));
        RateLimiter::for('watch', fn (Request $request) => Limit::perMinute(30)->by($key($request))->response($limited));
        RateLimiter::for('scan', fn (Request $request) => Limit::perMinute(20)->by($key($request))->response($limited));
        RateLimiter::for('payment-sync', fn (Request $request) => Limit::perMinute(30)->by($key($request))->response($limited));
        // Stripe retries with backoff; this only blunts a flood of forged requests from one address.
        RateLimiter::for('stripe-webhook', fn (Request $request) => Limit::perMinute(600)->by(self::hmac((string) $request->ip()))->response($limited));
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.self::hmac((string) $request->ip()))->response($limited),
            Limit::perMinute(10)->by('email:'.$emailKey($request))->response($limited),
        ]);
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by('admin:'.self::hmac((string) $request->ip()).':'.$emailKey($request))->response($limited));
        RateLimiter::for('devices', fn (Request $request) => Limit::perMinute(10)->by('device:'.$key($request))->response($limited));
        RateLimiter::for('export', fn (Request $request) => Limit::perHour(3)->by('export:'.$key($request))->response($limited));
        RateLimiter::for('delete-account', fn (Request $request) => Limit::perHour(3)->by('delete:'.$key($request))->response($limited));
        RateLimiter::for('compare', fn (Request $request) => Limit::perMinute(30)->by('compare:'.$key($request))->response($limited));
        RateLimiter::for('social', fn (Request $request) => Limit::perMinute(30)->by($key($request))->response($limited));
    }

    /** Cache keys are HMACs so a raw IP address is never the limiter key. */
    private static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
