<?php

namespace Drupal\makehaven_event_capacity\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'makehaven_event_capacity_message' formatter.
 *
 * @FieldFormatter(
 *   id = "makehaven_event_capacity_message",
 *   label = @Translation("Smart Capacity Message"),
 *   field_types = {
 *     "integer"
 *   }
 * )
 */
class EventCapacityFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'threshold' => 5,
      'message_full' => 'Full',
      'message_low' => 'Only @count spots left!',
      'message_open' => 'Open',
      'show_open' => FALSE,
      'hide_past_events' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Low Availability Threshold'),
      '#description' => $this->t('If remaining slots are less than or equal to this number, the "Low Availability" message will be shown.'),
      '#default_value' => $this->getSetting('threshold'),
      '#min' => 1,
    ];

    $elements['message_full'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message: Full'),
      '#default_value' => $this->getSetting('message_full'),
    ];

    $elements['message_low'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message: Low Availability'),
      '#description' => $this->t('Use @count for the number of remaining slots.'),
      '#default_value' => $this->getSetting('message_low'),
    ];

    $elements['message_open'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message: Open'),
      '#default_value' => $this->getSetting('message_open'),
    ];

    $elements['show_open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show "Open" message'),
      '#description' => $this->t('If unchecked, nothing will be displayed when there are plenty of slots.'),
      '#default_value' => $this->getSetting('show_open'),
    ];
    $elements['hide_past_events'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide for past events'),
      '#description' => $this->t('When enabled, no message is shown once the event end date has passed.'),
      '#default_value' => $this->getSetting('hide_past_events'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Threshold: @count', ['@count' => $this->getSetting('threshold')]);
    $summary[] = $this->t('Full: "@text"', ['@text' => $this->getSetting('message_full')]);
    $summary[] = $this->t('Low: "@text"', ['@text' => $this->getSetting('message_low')]);
    if ($this->getSetting('show_open')) {
      $summary[] = $this->t('Open: "@text"', ['@text' => $this->getSetting('message_open')]);
    }
    else {
      $summary[] = $this->t('Open: (Hidden)');
    }
    if ($this->getSetting('hide_past_events')) {
      $summary[] = $this->t('Hidden for past events');
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // This formatter only makes sense for the Remaining Slots field.
    if ($items->getName() !== 'field_civi_event_remaining') {
      return [];
    }

    $entity = $items->getEntity();
    if ($entity && $this->getSetting('hide_past_events') && $this->isPastEvent($entity)) {
      return [];
    }

    $elements = [];
    $threshold = $this->getSetting('threshold');
    $show_open = $this->getSetting('show_open');
    $msg_full = $this->getSetting('message_full');
    $msg_low = $this->getSetting('message_low');
    $msg_open = $this->getSetting('message_open');

    foreach ($items as $delta => $item) {
      $remaining = $item->value;
      $output = '';

      // Handle NULL (Unlimited capacity usually implies NULL remaining in our logic, or huge number).
      // Our module sets remaining = NULL if max_participants is NULL.
      if ($remaining === NULL) {
        if ($show_open) {
          $output = $msg_open;
        }
      }
      elseif ($remaining <= 0) {
        $output = $msg_full;
      }
      elseif ($remaining <= $threshold) {
        $output = str_replace('@count', $remaining, $msg_low);
      }
      else {
        if ($show_open) {
          $output = $msg_open;
        }
      }

      if (!empty($output)) {
        $elements[$delta] = ['#markup' => $output];
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return $field_definition->getName() === 'field_civi_event_remaining';
  }

  /**
   * Determine whether the event has already ended.
   */
  protected function isPastEvent(?EntityInterface $entity): bool {
    if (!$entity) {
      return FALSE;
    }

    $date = NULL;
    if ($entity->hasField('end_date') && !$entity->get('end_date')->isEmpty()) {
      $date = $entity->get('end_date')->first();
    }
    elseif ($entity->hasField('start_date') && !$entity->get('start_date')->isEmpty()) {
      $date = $entity->get('start_date')->first();
    }

    if (!$date) {
      return FALSE;
    }

    $timestamp = NULL;
    if (isset($date->date) && $date->date instanceof \Drupal\Core\Datetime\DrupalDateTime) {
      $timestamp = $date->date->getTimestamp();
    }
    elseif (isset($date->value)) {
      $timestamp = strtotime($date->value);
    }

    if ($timestamp === NULL) {
      return FALSE;
    }

    return $timestamp < \Drupal::time()->getRequestTime();
  }

}
