<?php

namespace Drupal\makehaven_event_capacity;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Provides helpers for recalculating event capacity stats.
 */
class EventCapacityUpdater {

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The CiviCRM service.
   *
   * @var mixed
   */
  protected $civicrm;

  /**
   * Track active updates to prevent recursion.
   *
   * @var array
   */
  protected $activeUpdates = [];

  /**
   * Constructs the updater.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param mixed $civicrm
   *   The CiviCRM service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager, $civicrm) {
    $this->logger = $logger_factory->get('makehaven_event_capacity');
    $this->entityTypeManager = $entity_type_manager;
    $this->civicrm = $civicrm;
  }

  /**
   * Check if an event is currently being updated.
   *
   * @param int $event_id
   *   The event ID.
   *
   * @return bool
   *   TRUE if updating, FALSE otherwise.
   */
  public function isEventUpdating($event_id) {
    return !empty($this->activeUpdates[$event_id]);
  }

  /**
   * Get all CiviCRM event IDs.
   *
   * @return int[]
   *   A list of event IDs.
   */
  public function getEventIds(): array {
    $this->civicrm->initialize();
    try {
      $result = civicrm_api3('Event', 'get', [
        'return' => ['id'],
        'options' => ['limit' => 0],
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Unable to load CiviCRM events: @message', ['@message' => $e->getMessage()]);
      return [];
    }

    if (empty($result['values'])) {
      return [];
    }

    return array_map('intval', array_column($result['values'], 'id'));
  }

  /**
   * Recalculate capacity stats for the provided events.
   *
   * @param int[] $event_ids
   *   IDs to process.
   * @param callable|null $progress_callback
   *   Optional callback receiving the processed count and total.
   *
   * @return int
   *   Number of events processed.
   */
  public function updateEvents(array $event_ids, ?callable $progress_callback = NULL): int {
    $count = 0;
    $total = count($event_ids);

    foreach ($event_ids as $event_id) {
      $this->updateEvent($event_id);
      $count++;
      if ($progress_callback) {
        $progress_callback($count, $total);
      }
    }

    return $count;
  }

  /**
   * Helper function to calculate and update event capacity stats.
   *
   * @param int $event_id
   *   The CiviCRM Event ID.
   */
  public function updateEvent($event_id) {
    if (!$event_id) {
      return;
    }

    if ($this->isEventUpdating($event_id)) {
      return;
    }

    $this->activeUpdates[$event_id] = TRUE;

    $this->civicrm->initialize();

    try {
      // 1. Get Event Details (Capacity).
      $event = civicrm_api3('Event', 'getsingle', ['id' => $event_id]);
      $max_participants = isset($event['max_participants']) ? (int) $event['max_participants'] : NULL;

      // 2. Get Participant Count (Registered/Counted).
      // Get statuses that count as "registered".
      $counted_statuses = \CRM_Event_PseudoConstant::participantStatus(NULL, "is_counted = 1");
      $status_ids = array_keys($counted_statuses);

      $count_params = [
        'event_id' => $event_id,
        'status_id' => ['IN' => $status_ids],
        'is_test' => 0,
      ];
      $registered_count = civicrm_api3('Participant', 'getcount', $count_params);

      // 3. Calculate Stats.
      $remaining = NULL;
      $percent = NULL;

      if ($max_participants !== NULL) {
        $remaining = $max_participants - $registered_count;
        
        if ($max_participants > 0) {
          $percent = ($registered_count / $max_participants) * 100;
        } else {
          $percent = 100;
        }
      }

      // 4. Update Drupal Entity.
      $storage = $this->entityTypeManager->getStorage('civicrm_event');
      $storage->resetCache([$event_id]);
      $entity = $storage->load($event_id);

      if ($entity) {
        $entity->set('field_civi_event_capacity', $max_participants);
        $entity->set('field_civi_event_registered', $registered_count);
        $entity->set('field_civi_event_remaining', $remaining);
        $entity->set('field_civi_event_full_pct', $percent);
        $entity->save();
      }

    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to update stats for event @id: @message', ['@id' => $event_id, '@message' => $e->getMessage()]);
    }
    finally {
      unset($this->activeUpdates[$event_id]);
    }
  }

}