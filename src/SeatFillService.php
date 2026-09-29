<?php

namespace Drupal\makehaven_event_capacity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Seat-fill rules: "help it run" and the last-minute member deal.
 *
 * Two plays with opposite goals (JR + Ashley, 2026-09-29; track
 * seat_fill_offers_20260929):
 *
 * - A class under the run threshold (50% of seats sold to attendees) is AT
 *   RISK. It gets urgency, not a discount: "register now so this runs".
 * - A class at or over the threshold WILL RUN. Instructors are paid per
 *   class, so an empty seat filled at half price is almost pure margin. About
 *   24 hours before it starts, members who opted in get one email with a 50%
 *   CiviDiscount code limited to the seats that are left. Skipped when only
 *   one or two seats are left: those fill at full price.
 *
 * Only the event types in config (Ticketed Workshop, Ticketed Member Only;
 * GEMS behind its own switch) are ever considered, never "everything but".
 * "Sold" counts attendees only: an instructor on the roster is not a seat.
 */
class SeatFillService {

  /**
   * Offers made, keyed by event id: code, code_id, seats, sent, recipients.
   */
  public const OFFERS_STATE_KEY = 'makehaven_event_capacity.member_deal_offers';

  /**
   * CiviCRM participant role "Attendee".
   */
  protected const ATTENDEE_ROLE = '1';

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected $civicrm,
  ) {}

  /**
   * Module settings.
   */
  protected function config() {
    return $this->configFactory->get('makehaven_event_capacity.settings');
  }

  /**
   * Percent of seats that must be sold for a class to count as running.
   */
  public function runThreshold(): int {
    return (int) ($this->config()->get('seat_fill_run_threshold') ?? 50);
  }

  /**
   * Whether an event of this type and title is in scope at all.
   */
  public function inScope(int $event_type_id, string $title): bool {
    $types = array_map('intval', (array) ($this->config()->get('seat_fill_event_types') ?? [6, 16]));
    if (in_array($event_type_id, $types, TRUE)) {
      return TRUE;
    }
    // GEMS cohorts are Program events (type 7) with GEMS in the title.
    return (bool) $this->config()->get('seat_fill_include_gems')
      && $event_type_id === 7
      && stripos($title, 'GEMS') !== FALSE;
  }

  /**
   * Attendees holding a seat: counted, Attendee role, not the instructor.
   */
  public function attendeeCount(int $event_id): int {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->join('civicrm_participant_status_type', 'st', 'st.id = p.status_id');
    $q->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0)
      ->condition('st.is_counted', 1)
      ->where("FIND_IN_SET(:role, REPLACE(p.role_id, CHAR(1), ',')) > 0", [':role' => self::ATTENDEE_ROLE]);
    $instructor_contacts = $this->instructorContactIds($event_id);
    if ($instructor_contacts) {
      $q->condition('p.contact_id', $instructor_contacts, 'NOT IN');
    }
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * CiviCRM contact ids of the instructors on an event.
   *
   * @return int[]
   *   Contact ids; empty when none are linked.
   */
  protected function instructorContactIds(int $event_id): array {
    if (!$this->database->schema()->tableExists('civicrm_event__field_civi_event_instructor')) {
      return [];
    }
    $q = $this->database->select('civicrm_event__field_civi_event_instructor', 'i');
    $q->join('civicrm_uf_match', 'uf', 'uf.uf_id = i.field_civi_event_instructor_target_id');
    $q->addField('uf', 'contact_id');
    $q->condition('i.entity_id', $event_id)->condition('i.deleted', 0);
    return array_map('intval', $q->execute()->fetchCol());
  }

  /**
   * Seats CiviCRM will still sell: capacity minus every counted participant.
   */
  public function seatsLeft(int $event_id, int $max): int {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->join('civicrm_participant_status_type', 'st', 'st.id = p.status_id');
    $q->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0)
      ->condition('st.is_counted', 1);
    return max(0, $max - (int) $q->countQuery()->execute()->fetchField());
  }

  /**
   * The marketing status for an event: 'at_risk' or 'normal'.
   *
   * At risk = in scope, starts within the help-it-run window, has a seat
   * limit, and fewer than the run threshold of seats are sold to attendees.
   */
  public function marketingStatus(int $event_id, int $event_type_id, string $title, ?int $max, int $start_ts): string {
    if (!$max || !$this->inScope($event_type_id, $title)) {
      return 'normal';
    }
    $days = ($start_ts - $this->time->getRequestTime()) / 86400;
    $window = (int) ($this->config()->get('seat_fill_at_risk_days') ?? 10);
    if ($days <= 0 || $days > $window) {
      return 'normal';
    }
    $pct = $this->attendeeCount($event_id) / $max * 100;
    return $pct < $this->runThreshold() ? 'at_risk' : 'normal';
  }

  /**
   * Works out whether one event qualifies for the member deal, and why not.
   *
   * @param object $event
   *   Row with id, title, event_type_id, start_date, max_participants,
   *   is_online_registration.
   * @param bool $ignore_timing
   *   TRUE for the dry-run report: judge everything except "is it ~24h out".
   *
   * @return array
   *   eligible (bool), reason (string), attendees, max, seats_left, pct,
   *   hours (until start).
   */
  public function evaluate(object $event, bool $ignore_timing = FALSE): array {
    $config = $this->config();
    $id = (int) $event->id;
    $max = (int) $event->max_participants;
    $hours = (strtotime($event->start_date) - $this->time->getRequestTime()) / 3600;
    $out = [
      'eligible' => FALSE,
      'reason' => '',
      'attendees' => 0,
      'max' => $max,
      'seats_left' => 0,
      'pct' => 0,
      'hours' => $hours,
    ];
    if (!$this->inScope((int) $event->event_type_id, (string) $event->title)) {
      $out['reason'] = 'event type not in scope';
      return $out;
    }
    if (!$max || empty($event->is_online_registration)) {
      $out['reason'] = 'no seat limit or no online registration';
      return $out;
    }
    $out['attendees'] = $this->attendeeCount($id);
    $out['seats_left'] = $this->seatsLeft($id, $max);
    $out['pct'] = round($out['attendees'] / $max * 100);

    $offers = (array) $this->state->get(self::OFFERS_STATE_KEY, []);
    if (isset($offers[$id])) {
      $out['reason'] = 'already offered';
      return $out;
    }
    $lead = (int) ($config->get('member_deal_lead_hours') ?? 24);
    $min_lead = (int) ($config->get('member_deal_min_lead_hours') ?? 4);
    if (!$ignore_timing && ($hours > $lead || $hours < $min_lead)) {
      $out['reason'] = sprintf('not in the %d–%d h window', $min_lead, $lead);
      return $out;
    }
    if ($out['pct'] < $this->runThreshold()) {
      $out['reason'] = sprintf('under %d%% sold: at risk, not a deal', $this->runThreshold());
      return $out;
    }
    $min_seats = (int) ($config->get('member_deal_min_seats') ?? 3);
    if ($out['seats_left'] < $min_seats) {
      $out['reason'] = sprintf('only %d seat(s) left: let them sell at full price', $out['seats_left']);
      return $out;
    }
    if (!$this->hasPaidPrice($id)) {
      $out['reason'] = 'free event';
      return $out;
    }
    $out['eligible'] = TRUE;
    $out['reason'] = $ignore_timing && ($hours > $lead || $hours < $min_lead)
      ? sprintf('would qualify ~%d h before start', $lead)
      : 'eligible';
    return $out;
  }

  /**
   * Upcoming in-scope events within the next $days days.
   *
   * @return object[]
   *   Rows for evaluate().
   */
  public function upcomingEvents(int $days): array {
    $now = $this->time->getRequestTime();
    $q = $this->database->select('civicrm_event', 'e')
      ->fields('e', ['id', 'title', 'event_type_id', 'start_date', 'max_participants', 'is_online_registration'])
      ->condition('e.is_active', 1)
      ->condition('e.is_template', 0)
      ->condition('e.start_date', date('Y-m-d H:i:s', $now), '>')
      ->condition('e.start_date', date('Y-m-d H:i:s', $now + $days * 86400), '<=')
      ->orderBy('e.start_date');
    $out = [];
    foreach ($q->execute() as $row) {
      if ($this->inScope((int) $row->event_type_id, (string) $row->title)) {
        $out[] = $row;
      }
    }
    return $out;
  }

  /**
   * Whether the event charges anything.
   */
  protected function hasPaidPrice(int $event_id): bool {
    $q = $this->database->select('civicrm_price_set_entity', 'pse');
    $q->join('civicrm_price_field', 'pf', 'pf.price_set_id = pse.price_set_id');
    $q->join('civicrm_price_field_value', 'pfv', 'pfv.price_field_id = pf.id');
    $q->condition('pse.entity_table', 'civicrm_event')
      ->condition('pse.entity_id', $event_id)
      ->condition('pfv.is_active', 1)
      ->condition('pfv.amount', 0, '>');
    return (bool) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Cron entry: make every due offer. Does nothing unless switched on.
   *
   * @return int
   *   Offers made.
   */
  public function run(): int {
    if (!$this->config()->get('member_deal_enabled')) {
      return 0;
    }
    $made = 0;
    foreach ($this->upcomingEvents(2) as $event) {
      $verdict = $this->evaluate($event);
      if ($verdict['eligible'] && $this->offer($event, $verdict)) {
        $made++;
      }
    }
    return $made;
  }

  /**
   * Creates the discount code and emails the opted-in members.
   *
   * The offer is recorded before any email goes out, so a failure half way
   * can never send the same class twice.
   */
  public function offer(object $event, array $verdict): bool {
    $logger = $this->loggerFactory->get('makehaven_event_capacity');
    $config = $this->config();
    $id = (int) $event->id;
    $discount = (int) ($config->get('member_deal_discount') ?? 50);
    $this->civicrm->initialize();

    $code = 'LASTSEAT' . strtoupper(substr(Crypt::hashBase64($id . microtime()), 0, 5));
    $code = preg_replace('/[^A-Z0-9]/', 'X', $code);
    try {
      $created = civicrm_api3('DiscountCode', 'create', [
        'code' => $code,
        'description' => sprintf('Member last-minute deal: %s (event %d)', $event->title, $id),
        // Required column; it is what the registrant sees once applied.
        'frontend_description' => sprintf('Members\' last-minute deal: %d%% off', $discount),
        'amount' => $discount,
        // 1 = percent.
        'amount_type' => 1,
        // An array: the API serializes it to CiviCRM's bookended list.
        'events' => [$id],
        'count_max' => (int) $verdict['seats_left'],
        'count_user_max' => 1,
        'expire_on' => $event->start_date,
        'is_active' => 1,
      ]);
    }
    catch (\Throwable $e) {
      $extra = method_exists($e, 'getExtraParams') ? ($e->getExtraParams()['debug_info'] ?? '') : '';
      $logger->error('Member deal for event @id: discount code not created: @m @x', [
        '@id' => $id,
        '@m' => $e->getMessage(),
        '@x' => $extra,
      ]);
      return FALSE;
    }

    $recipients = $this->recipients($id);
    $offers = (array) $this->state->get(self::OFFERS_STATE_KEY, []);
    $offers[$id] = [
      'code' => $code,
      'code_id' => (int) ($created['id'] ?? 0),
      'seats' => (int) $verdict['seats_left'],
      'attendees' => (int) $verdict['attendees'],
      'max' => (int) $verdict['max'],
      'sent' => $this->time->getRequestTime(),
      'recipients' => count($recipients),
      'title' => (string) $event->title,
    ];
    $this->state->set(self::OFFERS_STATE_KEY, $offers);

    $base = rtrim((string) ($config->get('site_base_url') ?: 'https://www.makehaven.org'), '/');
    $params = [
      'event_title' => (string) $event->title,
      'event_date' => date('l, F j \a\t g:ia', strtotime($event->start_date)),
      'discount' => $discount,
      'seats' => (int) $verdict['seats_left'],
      'code' => $code,
      'register_url' => $base . '/civicrm/event/register?reset=1&id=' . $id . '&discountcode=' . $code,
    ];
    $sent = 0;
    foreach ($recipients as $r) {
      $params['first_name'] = $r['first_name'] ?: 'there';
      $params['leave_url'] = $base . '/member-deals/leave/' . $r['contact_id'] . '/' . self::leaveToken((int) $r['contact_id']);
      $result = $this->mailManager->mail('makehaven_event_capacity', 'member_deal', $r['email'], 'en', $params);
      if (!empty($result['result'])) {
        $sent++;
      }
    }
    $logger->notice('Member deal for "@t" (event @id): code @c, @s seat(s), emailed @n of @r opted-in member(s).', [
      '@t' => $event->title,
      '@id' => $id,
      '@c' => $code,
      '@s' => $verdict['seats_left'],
      '@n' => $sent,
      '@r' => count($recipients),
    ]);
    return TRUE;
  }

  /**
   * The CiviCRM group that holds the opt-ins, or 0 if it does not exist yet.
   */
  public function groupId(): int {
    $name = (string) ($this->config()->get('member_deal_group') ?: 'member_flash_deals');
    return (int) $this->database->select('civicrm_group', 'g')
      ->fields('g', ['id'])
      ->condition('g.name', $name)
      ->execute()
      ->fetchField();
  }

  /**
   * Opted-in members who can be emailed and are not already registered.
   *
   * @return array[]
   *   contact_id, first_name, email.
   */
  public function recipients(int $event_id): array {
    $gid = $this->groupId();
    if (!$gid) {
      return [];
    }
    $q = $this->database->select('civicrm_group_contact', 'gc');
    $q->join('civicrm_contact', 'c', 'c.id = gc.contact_id');
    $q->join('civicrm_email', 'em', 'em.contact_id = c.id AND em.is_primary = 1');
    // Members only: the page that adds people is member-only, and someone
    // who has since left stops getting deals.
    $q->join('civicrm_uf_match', 'uf', 'uf.contact_id = c.id');
    $q->join('users_field_data', 'u', 'u.uid = uf.uf_id AND u.status = 1');
    $q->join('user__roles', 'r', "r.entity_id = u.uid AND r.roles_target_id = 'member'");
    $q->fields('c', ['first_name']);
    $q->addField('c', 'id', 'contact_id');
    $q->addField('em', 'email');
    $q->condition('gc.group_id', $gid)
      ->condition('gc.status', 'Added')
      ->condition('c.is_deleted', 0)
      ->condition('c.do_not_email', 0)
      ->condition('c.is_opt_out', 0)
      ->condition('em.on_hold', 0);
    $registered = $this->database->select('civicrm_participant', 'p')
      ->fields('p', ['contact_id'])
      ->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0);
    $q->condition('c.id', $registered, 'NOT IN');
    $q->distinct();
    return $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Signed token for the one-click leave link in each email.
   */
  public static function leaveToken(int $contact_id): string {
    return substr(Crypt::hmacBase64('member-deals-leave:' . $contact_id, Settings::getHashSalt()), 0, 24);
  }

  /**
   * Adds or removes a contact from the opt-in group.
   */
  public function setOptIn(int $contact_id, bool $in): void {
    $gid = $this->groupId();
    if (!$gid || !$contact_id) {
      throw new \RuntimeException('The member deals group does not exist.');
    }
    $this->civicrm->initialize();
    civicrm_api3('GroupContact', 'create', [
      'group_id' => $gid,
      'contact_id' => $contact_id,
      'status' => $in ? 'Added' : 'Removed',
    ]);
  }

  /**
   * Whether a contact is currently opted in.
   */
  public function isOptedIn(int $contact_id): bool {
    $gid = $this->groupId();
    if (!$gid || !$contact_id) {
      return FALSE;
    }
    return (bool) $this->database->select('civicrm_group_contact', 'gc')
      ->condition('gc.group_id', $gid)
      ->condition('gc.contact_id', $contact_id)
      ->condition('gc.status', 'Added')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Opted-in member count, for the staff report.
   */
  public function optInCount(): int {
    $gid = $this->groupId();
    return $gid ? (int) $this->database->select('civicrm_group_contact', 'gc')
      ->condition('gc.group_id', $gid)
      ->condition('gc.status', 'Added')
      ->countQuery()->execute()->fetchField() : 0;
  }

  /**
   * Offers made so far with redemptions from CiviDiscount, newest first.
   *
   * @return array[]
   *   The recorded offer plus event_id and used (codes redeemed).
   */
  public function offerReport(): array {
    $offers = (array) $this->state->get(self::OFFERS_STATE_KEY, []);
    $out = [];
    foreach ($offers as $event_id => $o) {
      $used = 0;
      if (!empty($o['code_id']) && $this->database->schema()->tableExists('cividiscount_item')) {
        $used = (int) $this->database->select('cividiscount_item', 'i')
          ->fields('i', ['count_use'])
          ->condition('i.id', $o['code_id'])
          ->execute()->fetchField();
      }
      $out[] = ['event_id' => (int) $event_id, 'used' => $used] + $o;
    }
    usort($out, fn($a, $b) => ($b['sent'] ?? 0) <=> ($a['sent'] ?? 0));
    return $out;
  }

}
