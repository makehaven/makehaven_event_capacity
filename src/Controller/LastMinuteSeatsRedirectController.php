<?php

namespace Drupal\makehaven_event_capacity\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * /member-deals moved to /last-minute-seats (2026-09-30).
 */
class LastMinuteSeatsRedirectController extends ControllerBase {

  /**
   * Permanent redirect to the renamed page.
   */
  public function page(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('makehaven_event_capacity.member_deals')->toString(), 301);
  }

}
