<?php

namespace Drupal\makehaven_event_capacity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    $civicrm,
    ConfigFactoryInterface $config_factory,
    MailManagerInterface $mail_manager,
    TimeInterface $time
  ) {
    $this->logger = $logger_factory->get('makehaven_event_capacity');
    $this->entityTypeManager = $entity_type_manager;
    $this->civicrm = $civicrm;
    $this->configFactory = $config_factory;
    $this->mailManager = $mail_manager;
    $this->time = $time;
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
      
      // If online registration is disabled, treat capacity as null (unlimited/hidden).
      if (empty($event['is_online_registration'])) {
        $max_participants = NULL;
      }
      else {
        $max_participants = isset($event['max_participants']) ? (int) $event['max_participants'] : NULL;
      }

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
        
        // Also update marketing status since we have the entity loaded and stats calculated.
        $this->updateMarketingStatus($entity);
        
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

  /**
   * Update marketing status for multiple events.
   * 
   * @param array $event_ids
   *   Array of entity IDs (not civi IDs, though they are usually same).
   */
  public function updateMarketingStatusMultiple(array $event_ids) {
    if (empty($event_ids)) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('civicrm_event');
    $entities = $storage->loadMultiple($event_ids);

    foreach ($entities as $entity) {
      $this->updateMarketingStatus($entity);
      $entity->save();
    }
  }

  /**
   * Update the marketing status fields on the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The civicrm_event entity.
   */
  public function updateMarketingStatus(EntityInterface $entity) {
    // Ensure we have necessary fields.
    if (!$entity->hasField('field_me_marketing_status') || !$entity->hasField('field_civi_event_full_pct') || !$entity->hasField('start_date')) {
      return;
    }

    $config = $this->configFactory->get('makehaven_event_capacity.settings');
    
    // Get Stats.
    $pct_full = $entity->get('field_civi_event_full_pct')->value;
    // If percent is null (unlimited), assume 0 for logic? Or 100?
    // Unlimited usually means no marketing needed?
    if ($pct_full === NULL) {
      $pct_full = 0; 
    }

    // Get Start Date.
    $start_date_str = $entity->get('start_date')->value;
    if (empty($start_date_str)) {
      return;
    }
    $start_timestamp = strtotime($start_date_str);
    $now = $this->time->getRequestTime();
    $seconds_until_start = $start_timestamp - $now;
    $days_until_start = $seconds_until_start / 86400;
    $hours_until_start = $seconds_until_start / 3600;

    // Config Values.
    $eb_threshold = $config->get('marketing_early_bird_threshold') ?? 80;
    $eb_days = $config->get('marketing_early_bird_days') ?? 7;
    $eb_discount = $config->get('marketing_early_bird_discount') ?? 10;
    
    $fs_threshold = $config->get('marketing_flash_sale_threshold') ?? 50;
    $fs_days = $config->get('marketing_flash_sale_days') ?? 2;
    $fs_discount = $config->get('marketing_flash_sale_discount') ?? 25;
    
    $notif_hours = $config->get('marketing_notification_hours') ?? 48;

    $status = 'normal';
    $discount = 0;

    // Logic:
    // Early Bird: If > EB_Days out AND < EB_Threshold.
    // Flash Sale: If <= FS_Days out AND < FS_Threshold.
    
    // Check Flash Sale first (priority logic, though time windows usually separate them)
    // Actually, if fs_days is 2 and eb_days is 7.
    // Days > 7: Early Bird Check.
    // Days <= 2: Flash Sale Check.
    // Days 3-7: Normal?
    
    // Let's support overlapping logic if users set it weirdly, but usually:
    
    if ($days_until_start > $eb_days) {
      if ($pct_full < $eb_threshold) {
        $status = 'early_bird';
        $discount = $eb_discount;
      }
    } 
    elseif ($days_until_start <= $fs_days && $days_until_start > 0) {
       if ($pct_full < $fs_threshold) {
         $status = 'flash_sale';
         $discount = $fs_discount;
       }
    }

    $entity->set('field_me_marketing_status', $status);
    $entity->set('field_me_marketing_discount', $discount);

    // Notifications Check.
    // Condition: 0 < hours <= NOTIF_HOURS AND pct < 50.
    // Note: The "50%" for notification was hardcoded in request: "under 50%... 48 hours before".
    // I will keep 50% hardcoded unless asked, but use the configurable hours.
    if ($hours_until_start > 0 && $hours_until_start <= $notif_hours && $pct_full < 50) {
      $this->checkLowCapacityWarning($entity, $pct_full, $start_date_str);
    }
  }

  /**
   * Send low capacity warning if not already sent.
   */
  protected function checkLowCapacityWarning(EntityInterface $entity, $pct_full, $start_date_str) {
    if ($entity->get('field_me_low_cap_notified')->value) {
      return;
    }

    $config = $this->configFactory->get('makehaven_event_capacity.settings');
    $to = $config->get('marketing_notification_email');
    if (empty($to)) {
      return;
    }

    $params = [
      'event_title' => $entity->label(),
      'registered' => $entity->get('field_civi_event_registered')->value,
      'capacity' => $entity->get('field_civi_event_capacity')->value,
      'percent' => $pct_full,
      'start_date' => $start_date_str,
      'link' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    $this->mailManager->mail('makehaven_event_capacity', 'low_capacity_warning', $to, 'en', $params);

    // Mark as notified.
    $entity->set('field_me_low_cap_notified', TRUE);
  }

}
