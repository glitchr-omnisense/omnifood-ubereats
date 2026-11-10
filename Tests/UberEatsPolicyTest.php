<?php

namespace Omnifood\UberEats\Tests;

use Omnifood\Exception\RateLimitedException;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\UberEats\UberEatsPlatform;
use Omnifood\UberEats\UberEatsPlatformFactory;
use Omnifood\Registry;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Uber Eats's usage policy, declared as data (contract-only, the token endpoint's 100 an hour), and a 429 to
 * an order's acceptance: sent once, surfaced, the next calls held unsent for the wait.
 */
final class UberEatsPolicyTest extends TestCase
{
    /** @var list<array{string, string}> method and URL of each call but the token's */
    private array $calls = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnifood/ubereats', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('ubereats', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('contract', $policy->rights->commercial->value, 'contract-only');
        self::assertSame(100, $policy->limitsFor('refresh')[0]->max, 'the token endpoint\'s');
        self::assertSame([], $policy->limitsFor('accept'), 'no published limit on the orders');
        self::assertSame('forbidden', $policy->rights->redistribution->value);
        $registry = new Registry([new UberEatsPlatformFactory($this->http())], ['ubereats' => ['factory' => 'ubereats', 'options' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'store_id' => 'store-1']]]);
        self::assertEquals($policy, $registry->policy('ubereats'));
        self::assertSame(100, $registry->guard('ubereats')->remaining('refresh'));
    }

    public function testA429ToAWriteIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $this->answers = [new MockResponse('{"code": "rate_limited", "message": "Too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']])];
        $platform = (new UberEatsPlatformFactory($this->http()))->create(['client_id' => 'cid', 'client_secret' => 'csecret', 'store_id' => 'store-1']);
        self::assertInstanceOf(UberEatsPlatform::class, $platform);

        try {
            $platform->accept('o-1');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
            self::assertSame(429, $e->status);
            self::assertSame('ubereats', $e->platform);
        }
        self::assertCount(1, $this->calls, 'sent once, not retried');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $platform->accept('o-1');
        } finally {
            self::assertCount(1, $this->calls, 'held, not sent');
        }
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/oauth/v2/token')) {
                return new MockResponse('{"access_token": "recorded", "expires_in": 86400, "token_type": "Bearer"}');
            }
            $this->calls[] = [$method, $url];

            return array_shift($this->answers) ?? new MockResponse('{}');
        });
    }
}
