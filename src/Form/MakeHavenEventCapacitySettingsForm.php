<?php

namespace Drupal\makehaven_event_capacity\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure MakeHaven Event Capacity settings for this site.
 */
class MakeHavenEventCapacitySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makehaven_event_capacity_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['makehaven_event_capacity.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('makehaven_event_capacity.settings');

    $form['setup_instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('How to show capacity and marketing notices'),
      '#open' => TRUE,
    ];

    $form['setup_instructions']['intro'] = [
      '#markup' => $this->t('These settings control when statuses are calculated. To display notices on event pages, configure the field formatters:'),
    ];

    $form['setup_instructions']['steps'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Go to Structure > Content types > Event > Manage display (repeat for each view mode you use).'),
        $this->t('For the Remaining Slots field, choose the "Smart Capacity Message" formatter and set the Full/Low/Open messages.'),
        $this->t('For the Marketing Status field, choose the "Marketing Message" formatter and set the Early Bird/Flash Sale copy.'),
        $this->t('Use @count in capacity messages and @discount in marketing messages to insert live values.'),
        $this->t('Place the fields where you want the notices to appear and save.'),
        $this->t('The Marketing Status and Discount fields are auto-calculated by this module; you do not need to edit them manually.'),
        $this->t('Staff cancellation warnings are only sent when Notification Email(s) is set below.'),
      ],
    ];

    $form['early_bird'] = [
      '#type' => 'details',
      '#title' => $this->t('Early Bird / Capacity Discount'),
      '#open' => TRUE,
    ];

    $form['early_bird']['marketing_early_bird_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity Threshold (%)'),
      '#description' => $this->t('Trigger discount if capacity is LESS than this percentage.'),
      '#default_value' => $config->get('marketing_early_bird_threshold') ?? 80,
      '#min' => 0,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['early_bird']['marketing_early_bird_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days in Advance'),
      '#description' => $this->t('Trigger discount if event starts at least this many days in the future.'),
      '#default_value' => $config->get('marketing_early_bird_days') ?? 7,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['early_bird']['marketing_early_bird_discount'] = [
      '#type' => 'number',
      '#title' => $this->t('Discount Amount (%)'),
      '#default_value' => $config->get('marketing_early_bird_discount') ?? 10,
      '#min' => 0,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['flash_sale'] = [
      '#type' => 'details',
      '#title' => $this->t('Urgent / Flash Sale'),
      '#open' => TRUE,
    ];

    $form['flash_sale']['marketing_flash_sale_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity Threshold (%)'),
      '#description' => $this->t('Trigger flash sale if capacity is LESS than this percentage. This overrides Early Bird if met.'),
      '#default_value' => $config->get('marketing_flash_sale_threshold') ?? 50,
      '#min' => 0,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['flash_sale']['marketing_flash_sale_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days in Advance'),
      '#description' => $this->t('Trigger flash sale if event starts within this many days.'),
      '#default_value' => $config->get('marketing_flash_sale_days') ?? 2,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['flash_sale']['marketing_flash_sale_discount'] = [
      '#type' => 'number',
      '#title' => $this->t('Discount Amount (%)'),
      '#default_value' => $config->get('marketing_flash_sale_discount') ?? 25,
      '#min' => 0,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Staff Notifications (Cancellation Warning)'),
      '#open' => TRUE,
    ];

    $form['notifications']['marketing_notification_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours Before Start'),
      '#description' => $this->t('Send warning if capacity is low this many hours before event (e.g., 48).'),
      '#default_value' => $config->get('marketing_notification_hours') ?? 48,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['notifications']['marketing_notification_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Notification Email(s)'),
      '#description' => $this->t('Comma-separated list of emails to notify when an event is < 50% full 48 hours before start.'),
      '#default_value' => $config->get('marketing_notification_email'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('makehaven_event_capacity.settings')
      ->set('marketing_early_bird_threshold', $form_state->getValue('marketing_early_bird_threshold'))
      ->set('marketing_early_bird_days', $form_state->getValue('marketing_early_bird_days'))
      ->set('marketing_early_bird_discount', $form_state->getValue('marketing_early_bird_discount'))
      ->set('marketing_flash_sale_threshold', $form_state->getValue('marketing_flash_sale_threshold'))
      ->set('marketing_flash_sale_days', $form_state->getValue('marketing_flash_sale_days'))
      ->set('marketing_flash_sale_discount', $form_state->getValue('marketing_flash_sale_discount'))
      ->set('marketing_notification_email', $form_state->getValue('marketing_notification_email'))
      ->set('marketing_notification_hours', $form_state->getValue('marketing_notification_hours'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
