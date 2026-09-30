<?php

namespace Drupal\makehaven_event_capacity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Lets a member join or leave Last-Minute Class Seats at /last-minute-seats.
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
    $discount = (int) ($config->get('member_deal_discount') ?? 50);

    $form['#cache'] = ['max-age' => 0];
    $form['#attributes']['class'][] = 'mh-lms';
    $form['#attached']['library'][] = 'makehaven_event_capacity/last_minute_seats';

    $form['intro'] = [
      '#markup' => '<p class="mh-lms__eyebrow">' . $this->t('Member perk') . '</p>'
      . '<p class="mh-lms__lede">' . $this->t('Half-price seats in classes that run tomorrow and still have room.') . '</p>'
      . '<p>' . $this->t('Some workshops are going ahead but still have empty seats the day before. Rather than leave them empty, we offer them to members at @d% off.', ['@d' => $discount]) . '</p>',
    ];

    // The one thing to do on this page, in a card.
    $form['card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mh-lms__card', $in ? 'is-in' : 'is-out']],
    ];
    if (!$is_member) {
      $form['card']['text'] = [
        '#markup' => '<p class="mh-lms__status">' . $this->t('This is a member perk.') . '</p>',
      ];
      $form['card']['links'] = [
        '#markup' => $account->isAnonymous()
          ? '<p class="mh-lms__cta"><a class="btn btn-primary" href="' . Url::fromRoute('user.login', [], ['query' => ['destination' => '/last-minute-seats']])->toString() . '">' . $this->t('Log in to sign up') . '</a> <a class="btn btn-outline-primary" href="/join-makehaven">' . $this->t('Become a member') . '</a></p>'
          : '<p class="mh-lms__cta"><a class="btn btn-primary" href="/join-makehaven">' . $this->t('Become a member') . '</a></p>',
      ];
    }
    else {
      $form['card']['status'] = [
        '#markup' => '<p class="mh-lms__status">' . ($in
          ? $this->t("You're on the list. Watch your email the day before a class.")
          : $this->t("You're not on the list yet.")) . '</p>',
      ];
      $form['in'] = ['#type' => 'value', '#value' => $in];
      $form['card']['actions'] = ['#type' => 'actions'];
      $form['card']['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $in ? $this->t('Stop these emails') : $this->t('Send me last-minute seats'),
        '#button_type' => $in ? 'secondary' : 'primary',
      ];
    }

    $steps = [
      [$this->t('Join the list'), $this->t('One click on this page. Members only.')],
      [$this->t('Get one email'), $this->t('About a day before a class that is running and still has seats. Some weeks there are none.')],
      [$this->t('Register'), $this->t('The link takes you to registration with @d% off already applied.', ['@d' => $discount])],
    ];
    $items = '';
    foreach ($steps as $i => [$title, $text]) {
      $items .= '<li><span class="mh-lms__num" aria-hidden="true">' . ($i + 1) . '</span><div><strong>' . $title . '</strong><br>' . $text . '</div></li>';
    }
    $form['how'] = [
      '#markup' => '<h2 class="mh-lms__h2">' . $this->t('How it works') . '</h2><ol class="mh-lms__steps">' . $items . '</ol>'
      . '<p class="mh-lms__fine">' . $this->t('One discounted seat per person per class, first come first served, until the seats are gone or the class starts. Every email has a link to stop them.') . '</p>',
    ];

    // People looking for "member discounts" usually mean the local businesses.
    $form['elsewhere'] = [
      '#markup' => '<aside class="mh-lms__elsewhere"><h2 class="mh-lms__h2">' . $this->t('Looking for something else?') . '</h2><ul>'
      . '<li>' . $this->t('<a href=":url">Member discounts at local businesses</a>: food, parking, supplies and more, in Suppliers and Community Resources.', [':url' => '/community-resources']) . '</li>'
      . '<li>' . $this->t('<a href=":url">All upcoming classes</a> at the regular price.', [':url' => '/events']) . '</li>'
      . '</ul></aside>',
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
      ? $this->t("You're on the list. Watch your email the day before a class.")
      : $this->t('Done. You will not get Last-Minute Class Seats emails any more.'));
  }

}
