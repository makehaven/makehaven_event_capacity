<?php

namespace Drupal\makehaven_event_capacity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makehaven_event_capacity\SeatFillService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The signed "stop these" link from a deal email. No login needed.
 *
 * A button, not a one-click GET: mail scanners follow links, and a scanner
 * must not take someone off the list.
 */
class MemberDealsLeaveForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_event_capacity_member_deals_leave';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $contact_id = 0, string $token = ''): array {
    if (!$contact_id || !hash_equals(SeatFillService::leaveToken($contact_id), $token)) {
      throw new AccessDeniedHttpException();
    }
    $form_state->set('contact_id', $contact_id);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Stop the last-minute member deal emails?') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Stop sending me deals'),
    ];
    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('makehaven_event_capacity.seat_fill')->setOptIn((int) $form_state->get('contact_id'), FALSE);
    $this->messenger()->addStatus($this->t('Done. You will not get member deals any more. You can join again at /member-deals.'));
  }

}
