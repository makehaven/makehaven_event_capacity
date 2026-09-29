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
      '#title' => $this->t('Early Bird (retired)'),
      '#open' => FALSE,
      '#description' => $this->t('No longer used since 2026-09-29: it advertised a discount no price set gave. See Seat fill below.'),
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
      '#title' => $this->t('Flash Sale (retired)'),
      '#open' => FALSE,
      '#description' => $this->t('No longer used since 2026-09-29: it advertised a discount on under-filled classes that no code gave. See Seat fill below.'),
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

    $form['notifications']['site_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Site base URL'),
      '#description' => $this->t('Base URL used to build event links in notification emails when they are generated outside a web request (e.g. CiviCRM cron). No trailing slash. Defaults to https://www.makehaven.org.'),
      '#default_value' => $config->get('site_base_url') ?: 'https://www.makehaven.org',
    ];

    $form['seat_fill'] = [
      '#type' => 'details',
      '#title' => $this->t('Seat fill: at-risk notice and last-minute member deal'),
      '#open' => TRUE,
      '#description' => $this->t('A class is "running" once this share of its seats is sold to attendees (instructors do not count). Under it, and within the at-risk window, the event page asks people to register so it runs; no discount. At or over it with seats to spare, members who opted in at /member-deals get one email about a day before, with a CiviDiscount code limited to the seats left. <code>drush mh-seat-fill</code> shows what the rules make of the next two weeks without changing anything.'),
    ];
    $types = [];
    if (\Drupal::database()->schema()->tableExists('civicrm_option_value')) {
      $q = \Drupal::database()->select('civicrm_option_value', 'ov');
      $q->join('civicrm_option_group', 'og', "og.id = ov.option_group_id AND og.name = 'event_type'");
      $types = $q->fields('ov', ['value', 'label'])->condition('ov.is_active', 1)->orderBy('ov.label')->execute()->fetchAllKeyed();
    }
    $form['seat_fill']['seat_fill_event_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Event types'),
      '#options' => $types,
      '#default_value' => array_map('strval', (array) ($config->get('seat_fill_event_types') ?? [6, 16])),
      '#description' => $this->t('Only these types are ever considered. Leave bigger-ticket programs out.'),
    ];
    $form['seat_fill']['seat_fill_include_gems'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also GEMS cohorts (Program events with GEMS in the title)'),
      '#default_value' => (bool) $config->get('seat_fill_include_gems'),
    ];
    $form['seat_fill']['seat_fill_run_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Running threshold (% of seats sold)'),
      '#default_value' => $config->get('seat_fill_run_threshold') ?? 50,
      '#min' => 1,
      '#max' => 100,
    ];
    $form['seat_fill']['seat_fill_at_risk_days'] = [
      '#type' => 'number',
      '#title' => $this->t('At-risk notice: days before start'),
      '#default_value' => $config->get('seat_fill_at_risk_days') ?? 10,
      '#min' => 0,
    ];
    $form['seat_fill']['member_deal_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the last-minute member deal'),
      '#default_value' => (bool) $config->get('member_deal_enabled'),
    ];
    $form['seat_fill']['member_deal_lead_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Send this many hours before start'),
      '#default_value' => $config->get('member_deal_lead_hours') ?? 24,
      '#min' => 1,
    ];
    $form['seat_fill']['member_deal_min_seats'] = [
      '#type' => 'number',
      '#title' => $this->t('Only when at least this many seats are left'),
      '#description' => $this->t('One or two seats usually sell at full price.'),
      '#default_value' => $config->get('member_deal_min_seats') ?? 3,
      '#min' => 1,
    ];
    $form['seat_fill']['member_deal_discount'] = [
      '#type' => 'number',
      '#title' => $this->t('Discount (%)'),
      '#default_value' => $config->get('member_deal_discount') ?? 50,
      '#min' => 1,
      '#max' => 100,
    ];
    $form['seat_fill']['member_deal_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email subject'),
      '#default_value' => $config->get('member_deal_subject') ?: _makehaven_event_capacity_member_deal_default('subject'),
      '#maxlength' => 200,
    ];
    $form['seat_fill']['member_deal_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email body'),
      '#rows' => 14,
      '#default_value' => $config->get('member_deal_body') ?: _makehaven_event_capacity_member_deal_default('body'),
      '#description' => $this->t('Plain text. Tokens: [first_name], [event_title], [event_date], [discount], [seats], [code], [register_url], [leave_url] (keep it: it is how people stop these).'),
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
      ->set('site_base_url', rtrim((string) $form_state->getValue('site_base_url'), '/'))
      ->set('seat_fill_event_types', array_values(array_map('intval', array_filter((array) $form_state->getValue('seat_fill_event_types')))))
      ->set('seat_fill_include_gems', (bool) $form_state->getValue('seat_fill_include_gems'))
      ->set('seat_fill_run_threshold', (int) $form_state->getValue('seat_fill_run_threshold'))
      ->set('seat_fill_at_risk_days', (int) $form_state->getValue('seat_fill_at_risk_days'))
      ->set('member_deal_enabled', (bool) $form_state->getValue('member_deal_enabled'))
      ->set('member_deal_lead_hours', (int) $form_state->getValue('member_deal_lead_hours'))
      ->set('member_deal_min_seats', (int) $form_state->getValue('member_deal_min_seats'))
      ->set('member_deal_discount', (int) $form_state->getValue('member_deal_discount'))
      ->set('member_deal_subject', (string) $form_state->getValue('member_deal_subject'))
      ->set('member_deal_body', (string) $form_state->getValue('member_deal_body'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
