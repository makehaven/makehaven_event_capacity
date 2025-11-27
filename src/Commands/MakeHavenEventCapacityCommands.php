<?php

namespace Drupal\makehaven_event_capacity\Commands;

use Drupal\makehaven_event_capacity\EventCapacityUpdater;
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
   * Constructs the command class.
   */
  public function __construct(EventCapacityUpdater $updater) {
    parent::__construct();
    $this->updater = $updater;
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

}
