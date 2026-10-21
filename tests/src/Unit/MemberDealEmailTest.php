<?php

namespace Drupal\Tests\makehaven_event_capacity\Unit;

use Drupal\makehaven_event_capacity\MemberDealEmail;
use Drupal\Tests\UnitTestCase;

/**
 * The Last-Minute Class Seats email.
 *
 * @coversDefaultClass \Drupal\makehaven_event_capacity\MemberDealEmail
 * @group makehaven_event_capacity
 */
class MemberDealEmailTest extends UnitTestCase {

  /**
   * A class as SeatFillService::dealParams() describes it.
   */
  protected function params(array $over = []): array {
    return $over + [
      'first_name' => 'Josh',
      'event_title' => 'Build & Badge: Laser Cutters',
      'event_day' => 'Sunday, October 25',
      'event_time' => '10:00am–4:30pm',
      'seats' => 3,
      'discount' => 50,
      'code' => 'LASTSEATABCDE',
      'register_url' => 'https://www.makehaven.org/civicrm/event/register?reset=1&id=783&discountcode=LASTSEATABCDE',
      'event_url' => 'https://www.makehaven.org/civicrm/event/info?reset=1&id=783',
      'leave_url' => 'https://www.makehaven.org/last-minute-seats/leave/1/tok',
      'price_before' => 134.0,
      'price_after' => 67.0,
    ];
  }

  /**
   * @covers ::html
   */
  public function testShowsPriceAndKeepsUrlsOutOfTheText(): void {
    $html = MemberDealEmail::html($this->params());
    $this->assertStringContainsString('Claim your seat for $67', $html);
    $this->assertStringContainsString('$134', $html);
    $this->assertStringContainsString('Build &amp; Badge', $html);
    $this->assertStringContainsString('3 seats left', $html);
    // Every URL is an attribute, never visible text.
    $text = strip_tags(preg_replace('~<div style="display:none.*?</div>~s', '', $html));
    $this->assertStringNotContainsString('http', $text);
    $this->assertStringContainsString('href="https://www.makehaven.org/last-minute-seats/leave/1/tok"', $html);
  }

  /**
   * @covers ::html
   */
  public function testWithoutAPriceItStillOffersTheDiscount(): void {
    $html = MemberDealEmail::html($this->params(['price_before' => NULL, 'price_after' => NULL, 'seats' => 1]));
    $this->assertStringContainsString('50% off', $html);
    $this->assertStringContainsString('>Claim your seat</a>', $html);
    $this->assertStringContainsString('1 seat left', $html);
  }

  /**
   * @covers ::money
   */
  public function testMoney(): void {
    $this->assertSame('$67', MemberDealEmail::money(67.0));
    $this->assertSame('$78.50', MemberDealEmail::money(78.5));
  }

}
