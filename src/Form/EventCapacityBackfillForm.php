<?php

namespace Drupal\makehaven_event_capacity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makehaven_event_capacity\EventCapacityUpdater;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an administrative form to rebuild event capacity stats.
 */
class EventCapacityBackfillForm extends FormBase {

  /**
   * Updater service.
   *
   * @var \Drupal\makehaven_event_capacity\EventCapacityUpdater
   */
  protected $updater;

  /**
   * Constructs the form.
   */
  public function __construct(EventCapacityUpdater $updater) {
    $this->updater = $updater;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makehaven_event_capacity.updater')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makehaven_event_capacity_backfill_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('Recalculate capacity, registration, and remaining slot values for every CiviCRM event and store the results in Drupal fields. Use this after enabling the module or when data has fallen behind.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild event capacity data'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $event_ids = $this->updater->getEventIds();
    if (empty($event_ids)) {
      $this->messenger()->addWarning($this->t('No CiviCRM events were found to process.'));
      return;
    }

    $operations = [];
    foreach (array_chunk($event_ids, 20) as $chunk) {
      $operations[] = [
        [static::class, 'processBatch'],
        [$chunk],
      ];
    }

    $batch = [
      'title' => $this->t('Updating event capacity data'),
      'operations' => $operations,
      'finished' => [static::class, 'finishedBatch'],
    ];

    batch_set($batch);
  }

  /**
   * Batch operation callback.
   */
  public static function processBatch(array $event_ids, array &$context) {
    $updater = \Drupal::service('makehaven_event_capacity.updater');
    $processed = $updater->updateEvents($event_ids);
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = 0;
    }
    $context['results']['processed'] += $processed;
  }

  /**
   * Batch finished callback.
   */
  public static function finishedBatch($success, array $results, array $operations) {
    if ($success) {
      $processed = $results['processed'] ?? 0;
      \Drupal::messenger()->addStatus(t('Updated @count events.', ['@count' => $processed]));
    }
    else {
      \Drupal::messenger()->addError(t('Event capacity update finished with errors.'));
    }
  }

}
