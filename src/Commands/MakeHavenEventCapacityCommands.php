<?php

namespace Drupal\makehaven_event_capacity\Commands;

use Drupal\makehaven_event_capacity\EventCapacityUpdater;
use Drupal\makehaven_event_capacity\SeatFillService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for MakeHaven Event Capacity.
 */
class MakeHavenEventCapacityCommands extends DrushCommands {

  /**
   * Updater service.
   *
   * @var \Drupal\makehaven_event_capacity\EventCapacityUpdater
   */
  protected $updater;

  /**
   * Seat-fill rules.
   *
   * @var \Drupal\makehaven_event_capacity\SeatFillService
   */
  protected $seatFill;

  /**
   * Constructs the command class.
   */
  public function __construct(EventCapacityUpdater $updater, SeatFillService $seat_fill) {
    parent::__construct();
    $this->updater = $updater;
    $this->seatFill = $seat_fill;
  }

  /**
   * Updates capacity stats for all CiviCRM events.
   *
   * @command makehaven:update-event-capacity
   * @aliases mh-uec
   * @usage makehaven:update-event-capacity
   *   Recalculates and updates registered, remaining, and percent full stats for all events.
   */
  public function updateEventCapacity() {
    $event_ids = $this->updater->getEventIds();
    if (empty($event_ids)) {
      $this->output()->writeln('No events found.');
      return;
    }

    $this->output()->writeln(sprintf('Updating %d events...', count($event_ids)));
    $last_output = 0;
    $this->updater->updateEvents($event_ids, function ($processed, $total) use (&$last_output) {
      if ($processed - $last_output >= 50 || $processed === $total) {
        $this->output()->writeln(sprintf('Updated %d / %d events...', $processed, $total));
        $last_output = $processed;
      }
    });
    $this->output()->writeln('Event capacity update complete.');
  }

  /**
   * What the seat-fill rules make of upcoming classes. Changes nothing.
   *
   * @command makehaven:seat-fill
   * @aliases mh-seat-fill
   * @option days How far ahead to look.
   * @usage makehaven:seat-fill --days=21
   *   Lists in-scope classes, attendees/seats, whether they are at risk, and
   *   whether the member deal would go out for them ~24 h before start.
   */
  public function seatFill(array $options = ['days' => 14]) {
    $threshold = $this->seatFill->runThreshold();
    $this->output()->writeln(sprintf('Run threshold %d%% · opted-in members: %d · deal sending: %s',
      $threshold, $this->seatFill->optInCount(),
      \Drupal::config('makehaven_event_capacity.settings')->get('member_deal_enabled') ? 'ON' : 'off'));
    foreach ($this->seatFill->upcomingEvents((int) $options['days']) as $event) {
      $v = $this->seatFill->evaluate($event, TRUE);
      $this->output()->writeln(sprintf('%s  #%d  %-48s  %2d/%-2d sold (%3d%%)  %2d left  %-8s  deal: %s',
        substr($event->start_date, 0, 16),
        $event->id,
        mb_substr($event->title, 0, 48),
        $v['attendees'], $v['max'], $v['pct'], $v['seats_left'],
        ($v['max'] && $v['pct'] < $threshold) ? 'AT RISK' : '',
        $v['reason']));
    }
    foreach ($this->seatFill->offerReport() as $o) {
      $this->output()->writeln(sprintf('Offered %s  #%d %s  code %s  %d seat(s)  %d emailed  %d redeemed',
        date('Y-m-d H:i', $o['sent']), $o['event_id'], $o['title'] ?? '', $o['code'], $o['seats'], $o['recipients'], $o['used']));
    }
  }

  /**
   * Sends the member deal for one class now, if it qualifies (timing aside).
   *
   * For a supervised first run. The class must still pass every rule except
   * "is it ~24 h out"; it is never sent twice.
   *
   * @command makehaven:member-deal-offer
   * @aliases mh-deal-offer
   * @param int $event_id The CiviCRM event id.
   */
  public function memberDealOffer(int $event_id) {
    $event = NULL;
    foreach ($this->seatFill->upcomingEvents(30) as $row) {
      if ((int) $row->id === $event_id) {
        $event = $row;
      }
    }
    if (!$event) {
      $this->logger()->error('Event not found, not upcoming within 30 days, or not in scope.');
      return;
    }
    $v = $this->seatFill->evaluate($event, TRUE);
    if (!$v['eligible']) {
      $this->logger()->error('Not eligible: ' . $v['reason']);
      return;
    }
    $n = count($this->seatFill->recipients($event_id));
    if (!$this->io()->confirm(sprintf('Create a %d-seat code for "%s" and email %d opted-in member(s)?', $v['seats_left'], $event->title, $n), FALSE)) {
      return;
    }
    if (!$this->seatFill->offer($event, $v)) {
      $this->logger()->error('No offer made: the discount code could not be created. See the watchdog entry.');
      return;
    }
    $this->logger()->success('Offer made. See the watchdog entry for the code and send count.');
  }

}
