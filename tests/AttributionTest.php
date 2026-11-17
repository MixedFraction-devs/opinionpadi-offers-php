<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Tests;

use OpinionPadi\Offers\Attribution;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase
{
    public function test_from_url(): void
    {
        $this->assertSame(
            ['click_id' => '01JBZ7Q4M2X8RC5T9VK3NHW6AE', 'ref' => 'TOLU7X'],
            Attribution::fromUrl('https://shop.example.com/landing?utm_source=x&op_click=01JBZ7Q4M2X8RC5T9VK3NHW6AE&op_ref=TOLU7X#top'),
        );
    }

    public function test_from_url_without_values(): void
    {
        $this->assertSame(['click_id' => null, 'ref' => null], Attribution::fromUrl('https://shop.example.com/'));
        $this->assertSame(['click_id' => null, 'ref' => null], Attribution::fromUrl('https://shop.example.com/?op_click=&op_ref=%20'));
    }

    public function test_from_url_decodes_and_accepts_a_bare_query(): void
    {
        $this->assertSame(['click_id' => 'a b', 'ref' => 'R1'], Attribution::fromUrl('?op_click=a%20b&op_ref=R1'));
        $this->assertSame(['click_id' => 'only', 'ref' => null], Attribution::fromUrl('op_click=only'));
    }

    public function test_from_url_keeps_the_first_value(): void
    {
        $this->assertSame(['click_id' => 'first', 'ref' => null], Attribution::fromUrl('https://x.test/?op_click=first&op_click=second'));
    }

    public function test_from_cookie_array(): void
    {
        $this->assertSame(['click_id' => 'c1', 'ref' => 'R1'], Attribution::fromCookies(['op_click' => 'c1', 'op_ref' => 'R1', 'other' => 'x']));
        $this->assertSame(['click_id' => null, 'ref' => null], Attribution::fromCookies([]));
        $this->assertSame(['click_id' => null, 'ref' => 'R1'], Attribution::fromCookies(['op_click' => '', 'op_ref' => 'R1']));
    }

    public function test_from_cookie_header(): void
    {
        $this->assertSame(
            ['click_id' => 'c%1', 'ref' => 'R1'],
            Attribution::fromCookies('session=abc; op_click=c%251; op_ref="R1"'),
        );
        $this->assertSame(['click_id' => null, 'ref' => null], Attribution::fromCookies(''));
    }
}
