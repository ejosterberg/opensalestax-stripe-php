<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Tests\Unit;

use OpenSalesTax\Stripe\TaxCodeMap;
use PHPUnit\Framework\TestCase;

final class TaxCodeMapTest extends TestCase
{
    public function testSaasBusinessMapsToDigitalGoods(): void
    {
        $map = new TaxCodeMap();
        self::assertSame('digital_goods', $map->resolve('txcd_10103001'));
    }

    public function testGeneralTangibleGoodsMapsToGeneral(): void
    {
        $map = new TaxCodeMap();
        self::assertSame('general', $map->resolve('txcd_99999999'));
    }

    public function testGeneralServicesMapsToGeneral(): void
    {
        $map = new TaxCodeMap();
        self::assertSame('general', $map->resolve('txcd_20030000'));
    }

    public function testIaasMapsToDigitalGoods(): void
    {
        $map = new TaxCodeMap();
        self::assertSame('digital_goods', $map->resolve('txcd_10101000'));
    }

    public function testEmptyCodeFallsBackToGeneral(): void
    {
        $map = new TaxCodeMap();
        self::assertSame('general', $map->resolve(''));
    }

    public function testUnknownCodeFallsBackToGeneral(): void
    {
        $map = new TaxCodeMap();
        self::assertSame('general', $map->resolve('txcd_99999998')); // intentionally close to general but not mapped
    }

    public function testNontaxableCodeIsRecognized(): void
    {
        $map = new TaxCodeMap();
        self::assertTrue($map->isNontaxable('txcd_00000000'));
        self::assertFalse($map->isNontaxable('txcd_99999999'));
        self::assertFalse($map->isNontaxable(''));
    }

    public function testNontaxableResolvesToGeneralAsLastResort(): void
    {
        // Callers SHOULD short-circuit via isNontaxable() before calling resolve(),
        // but if they don't, resolve() must not crash — fall back to general.
        $map = new TaxCodeMap();
        self::assertSame('general', $map->resolve(TaxCodeMap::NONTAXABLE_CODE));
    }

    public function testCustomOverridesMergeWithDefaults(): void
    {
        $map = new TaxCodeMap([
            'txcd_30060006' => 'clothing',          // custom
            'txcd_99999999' => 'digital_goods',     // override default
        ]);

        self::assertSame('clothing', $map->resolve('txcd_30060006'));
        self::assertSame('digital_goods', $map->resolve('txcd_99999999'));
        // Defaults still present
        self::assertSame('digital_goods', $map->resolve('txcd_10103001'));
    }

    public function testWarningHandlerFiresOnFallback(): void
    {
        $captured = [];
        $map = new TaxCodeMap();
        $map->setWarningHandler(static function (string $code) use (&$captured): void {
            $captured[] = $code;
        });

        $map->resolve('txcd_unknown_xyz');

        self::assertSame(['txcd_unknown_xyz'], $captured);
    }

    public function testWarningHandlerDoesNotFireForKnownCodes(): void
    {
        $captured = [];
        $map = new TaxCodeMap();
        $map->setWarningHandler(static function (string $code) use (&$captured): void {
            $captured[] = $code;
        });

        $map->resolve('txcd_99999999');
        $map->resolve('txcd_10103001');

        self::assertSame([], $captured);
    }
}
