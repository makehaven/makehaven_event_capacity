<?php

namespace Drupal\Tests\makehaven_event_capacity\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makehaven_event_capacity\SeatFillService;
use Drupal\Tests\UnitTestCase;

/**
 * Which events the seat-fill rules may ever touch.
 *
 * @coversDefaultClass \Drupal\makehaven_event_capacity\SeatFillService
 * @group makehaven_event_capacity
 */
class SeatFillScopeTest extends UnitTestCase {

  /**
   * Builds the service with the given settings.
   */
  protected function service(array $settings): SeatFillService {
    return new SeatFillService(
      $this->createMock(Connection::class),
      $this->getConfigFactoryStub(['makehaven_event_capacity.settings' => $settings]),
      $this->createMock(StateInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      NULL,
    );
  }

  /**
   * Workshops and member-only workshops are in; programs are not.
   *
   * @covers ::inScope
   */
  public function testListedTypesOnly(): void {
    $s = $this->service(['seat_fill_event_types' => [6, 16], 'seat_fill_include_gems' => FALSE]);
    $this->assertTrue($s->inScope(6, 'Intro to Sewing'));
    $this->assertTrue($s->inScope(16, 'Members: Laser Night'));
    $this->assertFalse($s->inScope(7, 'GEMS Cohort 30'));
    $this->assertFalse($s->inScope(7, 'Foundations Pathway'));
    $this->assertFalse($s->inScope(8, 'Meetup'));
  }

  /**
   * The GEMS switch admits GEMS cohorts, and no other program.
   *
   * @covers ::inScope
   */
  public function testGemsSwitch(): void {
    $s = $this->service(['seat_fill_event_types' => [6, 16], 'seat_fill_include_gems' => TRUE]);
    $this->assertTrue($s->inScope(7, 'GEMS Cohort 30'));
    $this->assertFalse($s->inScope(7, 'Foundations Pathway'));
    $this->assertFalse($s->inScope(6 + 1, 'Art of Sewing'));
  }

  /**
   * Leave links are per contact and cannot be guessed from another's.
   *
   * @covers ::leaveToken
   */
  public function testLeaveTokenIsPerContact(): void {
    new \Drupal\Core\Site\Settings(['hash_salt' => 'test-salt']);
    $this->assertSame(SeatFillService::leaveToken(5), SeatFillService::leaveToken(5));
    $this->assertNotSame(SeatFillService::leaveToken(5), SeatFillService::leaveToken(6));
  }

}
