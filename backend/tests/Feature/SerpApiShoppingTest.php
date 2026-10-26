<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Services\Compare\CompareService;
use App\Services\Compare\OfferProvider;
use App\Services\Compare\SerpApiShoppingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SerpApiShoppingTest extends TestCase
{
    public function test_immersive_sellers_supply_the_merchant_url_brand_and_size(): void
    {
        $this->fakeSerp('shopping-nike.json', 'immersive-nike.json');

        $offers = (new SerpApiShoppingProvider('test-key'))->search('nike sneakers');
        $byStore = [];
        foreach ($offers as $offer) {
            $byStore[$offer['store']] = $offer;
        }

        $this->assertSame('Nike', $offers[0]['brand']);
        $this->assertSame('10', $offers[0]['size']);
        $this->assertSame('Nike Dunk Low', $offers[0]['model']);
        $this->assertSame('https://www.finishline.com/nike-dunk-low', $byStore['Finish Line']['url']);
        $this->assertTrue($byStore['Finish Line']['shipping_known']);
        $this->assertSame(0, $byStore['Finish Line']['shipping_minor']);
        $this->assertSame(1200, $byStore["Dick's"]['shipping_minor']);
        $this->assertFalse($byStore['Foot Locker']['shipping_known']);
        $this->assertNull($byStore['Foot Locker']['shipping_minor']);
        $this->assertFalse($byStore['Zappos']['shipping_known']);
        $this->assertSame(1700, $byStore['Nike']['shipping_minor']);
        $this->assertFalse($byStore['JD Sports']['shipping_known']);
        $this->assertSame('out_of_stock', $byStore['JD Sports']['availability']);
        $this->assertArrayNotHasKey('Google', $byStore);
        $this->assertSame(
            ['Finish Line', 'Nike', "Dick's", 'JD Sports', 'Zappos', 'Foot Locker'],
            array_column($offers, 'store'),
        );
        $this->assertSame('USD', $byStore['Finish Line']['currency']);
    }

    public function test_a_japan_storefront_keeps_yen_without_adding_cents(): void
    {
        $this->fakeSerp('shopping-jpy.json', 'immersive-jpy.json');
        $offers = (new SerpApiShoppingProvider('test-key'))->search('headphones', ['gl' => 'jp', 'hl' => 'ja']);
        $this->assertSame('JPY', $offers[0]['currency']);
        $this->assertSame(15100, $offers[0]['price_minor']);
        $this->assertSame(0, $offers[0]['shipping_minor']);
    }

    public function test_an_empty_mapping_of_a_successful_response_is_not_cached(): void
    {
        $this->fakeSerp('shopping-nike.json', 'immersive-empty.json');
        $this->app->instance(OfferProvider::class, new SerpApiShoppingProvider('test-key'));
        $service = $this->app->make(CompareService::class);

        try {
            $service->search('nike sneakers', $this->filters());
            $this->fail('A product list with no merchant URLs must not be served.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('COMPARE_UNAVAILABLE', $exception->errorCode);
        }
        $this->assertNull(Cache::get($service->cacheKey('nike sneakers')));

        try {
            $service->search('nike sneakers', $this->filters());
        } catch (ApiException) {
            // Still a live failure, not a cached empty list.
        }
        Http::assertSentCount(4);
    }

    public function test_a_shopping_response_with_no_products_can_be_cached(): void
    {
        Http::fake([
            'serpapi.com/*' => Http::response(['shopping_results' => []]),
        ]);
        $this->app->instance(OfferProvider::class, new SerpApiShoppingProvider('test-key'));
        $service = $this->app->make(CompareService::class);

        $first = $service->search('no such product', $this->filters());
        $second = $service->search('no such product', $this->filters());

        $this->assertSame([], $first['products']);
        $this->assertSame([], $second['products']);
        Http::assertSentCount(1);
    }

    private function fakeSerp(string $shopping, string $immersive): void
    {
        Http::fake(function ($request) use ($shopping, $immersive) {
            $engine = $request->data()['engine'] ?? '';
            $file = match ($engine) {
                'google_shopping' => $shopping,
                'google_immersive_product' => $immersive,
                default => null,
            };
            if ($file === null) {
                return Http::response(['error' => 'unexpected'], 500);
            }

            return Http::response(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/serpapi/'.$file), true));
        });
    }

    /** @return array{brand: null, size: null, min_minor: null, max_minor: null, in_stock: bool, sort: string, currency: string} */
    private function filters(): array
    {
        return [
            'brand' => null,
            'size' => null,
            'min_minor' => null,
            'max_minor' => null,
            'in_stock' => true,
            'sort' => 'total_asc',
            'currency' => 'USD',
        ];
    }
}
