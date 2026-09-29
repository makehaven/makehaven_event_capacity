<?php

namespace Drupal\makehaven_event_capacity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Lets a member join or leave the last-minute deal list at /member-deals.
 */
class MemberDealsOptInForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_event_capacity_member_deals_opt_in';
  }

  /**
   * The current user's CiviCRM contact id, or 0.
   */
  protected function contactId(): int {
    return (int) \Drupal::database()->select('civicrm_uf_match', 'uf')
      ->fields('uf', ['contact_id'])
      ->condition('uf.uf_id', $this->currentUser()->id())
      ->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $seat_fill = \Drupal::service('makehaven_event_capacity.seat_fill');
    $config = $this->config('makehaven_event_capacity.settings');
    $account = $this->currentUser();
    $is_member = in_array('member', $account->getRoles(), TRUE);
    $in = $is_member && $seat_fill->isOptedIn($this->contactId());

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Some workshops are going ahead but still have several empty seats the day before. Rather than leave them empty, we offer them to members at @d% off.', ['@d' => (int) ($config->get('member_deal_discount') ?? 50)]) . '</p>'
      . '<ul><li>' . $this->t('One email, about a day before the class, only when there are real seats to fill.') . '</li>'
      . '<li>' . $this->t('No schedule: some weeks there are none.') . '</li>'
      . '<li>' . $this->t('One discounted seat per person per class, first come first served.') . '</li>'
      . '<li>' . $this->t('Every email has a link to stop them.') . '</li></ul>',
    ];
    $form['#cache'] = ['max-age' => 0];
    if (!$is_member) {
      $form['not_member'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'text' => [
          '#markup' => $account->isAnonymous()
            ? $this->t('This is a member perk. <a href=":login">Log in</a> to sign up, or <a href=":join">become a member</a>.', [
              ':login' => Url::fromRoute('user.login', [], ['query' => ['destination' => '/member-deals']])->toString(),
              ':join' => '/join-makehaven',
            ])
            : $this->t('This is a member perk. <a href=":join">Become a member</a> to get these deals.', [
              ':join' => '/join-makehaven',
            ]),
        ],
      ];
      return $form;
    }
    $form['status'] = [
      '#markup' => '<p><strong>' . ($in
        ? $this->t('You are on the list.')
        : $this->t('You are not on the list.')) . '</strong></p>',
    ];
    $form['in'] = ['#type' => 'value', '#value' => $in];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $in ? $this->t('Stop sending me deals') : $this->t('Send me last-minute deals'),
      '#button_type' => $in ? 'secondary' : 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!in_array('member', $this->currentUser()->getRoles(), TRUE)) {
      return;
    }
    $cid = $this->contactId();
    if (!$cid) {
      $this->messenger()->addError($this->t('We could not find your contact record. Please let staff know.'));
      return;
    }
    $join = !$form_state->getValue('in');
    try {
      \Drupal::service('makehaven_event_capacity.seat_fill')->setOptIn($cid, $join);
    }
    catch (\Throwable $e) {
      \Drupal::logger('makehaven_event_capacity')->error('Member deals opt-in failed for contact @c: @m', [
        '@c' => $cid,
        '@m' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Something went wrong. Please try again later.'));
      return;
    }
    $this->messenger()->addStatus($join
      ? $this->t('You are on the list. Watch your email the day before a class.')
      : $this->t('Done. You will not get member deals any more.'));
  }

}
