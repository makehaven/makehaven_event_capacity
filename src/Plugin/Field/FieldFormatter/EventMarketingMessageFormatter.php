<?php

namespace Drupal\makehaven_event_capacity\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'makehaven_event_marketing_message' formatter.
 *
 * @FieldFormatter(
 *   id = "makehaven_event_marketing_message",
 *   label = @Translation("Marketing Message"),
 *   field_types = {
 *     "list_string"
 *   }
 * )
 */
class EventMarketingMessageFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'message_early_bird' => 'Early Bird Special! Save @discount%!',
      'message_flash_sale' => 'Flash Sale! Save @discount% now!',
      'message_at_risk' => 'This class needs a few more people to run. Register now to make sure it happens.',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['message_early_bird'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Early Bird Message'),
      '#description' => $this->t('Use @discount for the discount percentage.'),
      '#default_value' => $this->getSetting('message_early_bird'),
    ];

    $elements['message_flash_sale'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Flash Sale Message'),
      '#description' => $this->t('Use @discount for the discount percentage.'),
      '#default_value' => $this->getSetting('message_flash_sale'),
    ];

    $elements['message_at_risk'] = [
      '#type' => 'textfield',
      '#title' => $this->t('At risk message'),
      '#description' => $this->t('Shown on in-scope classes that are under the run threshold and starting soon. No discount.'),
      '#default_value' => $this->getSetting('message_at_risk'),
      '#maxlength' => 255,
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Early Bird: "@text"', ['@text' => $this->getSetting('message_early_bird')]);
    $summary[] = $this->t('Flash Sale: "@text"', ['@text' => $this->getSetting('message_flash_sale')]);
    $summary[] = $this->t('At risk: "@text"', ['@text' => $this->getSetting('message_at_risk')]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $msg_early = $this->getSetting('message_early_bird');
    $msg_flash = $this->getSetting('message_flash_sale');
    
    $entity = $items->getEntity();
    $discount = 0;
    if ($entity->hasField('field_me_marketing_discount') && !$entity->get('field_me_marketing_discount')->isEmpty()) {
      $discount = $entity->get('field_me_marketing_discount')->value;
    }

    foreach ($items as $delta => $item) {
      $status = $item->value;
      $output = '';

      if ($status === 'early_bird') {
        $output = str_replace('@discount', $discount, $msg_early);
      }
      elseif ($status === 'flash_sale') {
        $output = str_replace('@discount', $discount, $msg_flash);
      }
      elseif ($status === 'at_risk') {
        $output = $this->getSetting('message_at_risk');
      }

      if (!empty($output)) {
        // Add a class wrapper for easier styling.
        $class = 'event-marketing-message event-marketing-' . str_replace('_', '-', $status);
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $output,
          '#attributes' => ['class' => [$class]],
        ];
      }
    }

    return $elements;
  }

}
