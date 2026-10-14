<?php

namespace Drupal\Tests\makehaven_event_capacity\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\makehaven_event_capacity\EventCapacityUpdater;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The 48-hour low-enrolment warning to education staff.
 *
 * @coversDefaultClass \Drupal\makehaven_event_capacity\EventCapacityUpdater
 * @group makehaven_event_capacity
 */
class LowCapacityWarningTest extends UnitTestCase {

  /**
   * Decision cases: [capacity, pct, hours until start, notified, expected].
   */
  public static function decisionProvider(): array {
    return [
      'capped, 20% full, 24h out' => [10, 20.0, 24.0, FALSE, TRUE],
      'capped, stored as strings' => ['10', '20', 24.0, FALSE, TRUE],
      'capped, 0 registered' => [10, 0.0, 10.0, FALSE, TRUE],
      // The live failure: unlimited events (meetups, open houses) have a NULL
      // capacity and percent, which used to be read as 0% full.
      'unlimited: NULL capacity and percent' => [NULL, NULL, 24.0, FALSE, FALSE],
      'unlimited: NULL capacity only' => [NULL, 0.0, 24.0, FALSE, FALSE],
      'zero capacity' => [0, 100.0, 24.0, FALSE, FALSE],
      'already warned once' => [10, 20.0, 24.0, TRUE, FALSE],
      'exactly half full' => [10, 50.0, 24.0, FALSE, FALSE],
      'outside the window' => [10, 20.0, 49.0, FALSE, FALSE],
      // Past events must never fire, so the backlog of failed retries cannot
      // turn into a burst of stale warnings once the fix deploys.
      'already started' => [10, 20.0, -1.0, FALSE, FALSE],
      'long past' => [10, 20.0, -500.0, FALSE, FALSE],
    ];
  }

  /**
   * @covers ::shouldSendLowCapacityWarning
   * @dataProvider decisionProvider
   */
  public function testShouldSend($capacity, $pct, float $hours, bool $notified, bool $expected): void {
    $this->assertSame($expected, EventCapacityUpdater::shouldSendLowCapacityWarning($capacity, $pct, $hours, 48.0, $notified));
  }

  /**
   * Builds an updater whose mail manager returns the given result.
   */
  protected function updater(array $mail_result, ?array &$sent_params, LoggerChannelInterface $logger): EventCapacityUpdater {
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(function ($module, $key, $to, $langcode, $params) use (&$sent_params, $mail_result) {
      $sent_params = $params;
      return $mail_result;
    });

    return new EventCapacityUpdater(
      $logger_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      NULL,
      $this->getConfigFactoryStub([
        'makehaven_event_capacity.settings' => [
          'marketing_notification_email' => 'education@example.org',
          'marketing_notification_hours' => 48,
          'site_base_url' => 'https://www.example.org',
        ],
      ]),
      $mail,
      $this->createMock(TimeInterface::class),
      new RequestStack(),
    );
  }

  /**
   * Builds an event entity mock; records what is set on it.
   */
  protected function event(array $values, array &$set): ContentEntityInterface {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('id')->willReturn(42);
    $entity->method('label')->willReturn(NULL);
    $entity->method('get')->willReturnCallback(function ($name) use ($values) {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('__get')->with('value')->willReturn($values[$name] ?? NULL);
      return $list;
    });
    $entity->method('set')->willReturnCallback(function ($name, $value) use (&$set, $entity) {
      $set[$name] = $value;
      return $entity;
    });
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/civicrm-event/42');
    $entity->method('toUrl')->willReturn($url);
    return $entity;
  }

  /**
   * Calls the protected sender.
   */
  protected function send(EventCapacityUpdater $updater, ContentEntityInterface $entity): void {
    $method = new \ReflectionMethod($updater, 'checkLowCapacityWarning');
    $method->invoke($updater, $entity, 20.0, '2026-10-15 18:00:00');
  }

  /**
   * Every mail placeholder is a string, and a sent warning sets the flag.
   *
   * @covers ::checkLowCapacityWarning
   */
  public function testSendSetsFlagAndPassesStrings(): void {
    $set = [];
    $sent = NULL;
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('error');
    $updater = $this->updater(['result' => TRUE], $sent, $logger);
    $this->send($updater, $this->event([
      'field_civi_event_registered' => NULL,
      'field_civi_event_capacity' => '10',
    ], $set));

    $this->assertNotNull($sent);
    foreach (['event_title', 'registered', 'capacity', 'hours', 'start_date', 'link'] as $key) {
      $this->assertIsString($sent[$key], $key);
    }
    $this->assertSame('0', $sent['registered']);
    $this->assertSame('10', $sent['capacity']);
    $this->assertSame('https://www.example.org/civicrm-event/42', $sent['link']);
    $this->assertSame(['field_me_low_cap_notified' => TRUE], $set);
  }

  /**
   * A mail-system failure is an error and leaves the event unflagged.
   *
   * @covers ::checkLowCapacityWarning
   */
  public function testFailedSendIsErrorAndRetries(): void {
    $set = [];
    $sent = NULL;
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $updater = $this->updater(['result' => FALSE], $sent, $logger);
    $this->send($updater, $this->event(['field_civi_event_capacity' => 10], $set));
    $this->assertSame([], $set);
  }

}
