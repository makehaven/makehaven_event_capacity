<?php

namespace Drupal\makehaven_event_capacity\EventSubscriber;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Answers 404 for CiviCRM event pages whose event no longer exists.
 *
 * CRM_Event_Form_Registration::preProcess() looks the event up through
 * EntityLookupTrait, which throws "Expected to find one Event record, but there
 * were zero" when the row has been deleted. That is an uncaught exception, so
 * the member — or, far more often, a crawler working from an indexed URL — gets
 * a 500. Each one costs a full PHP worker out of a pool of six.
 *
 * The lookup happens in preProcess(), before hook_civicrm_buildForm() runs, so
 * there is no CiviCRM hook early enough to catch it. This subscriber checks the
 * id straight off the query string, before the CiviCRM controller is reached.
 */
class MissingCiviEventSubscriber implements EventSubscriberInterface {

  /**
   * Event paths that take an `id` naming a civicrm_event row.
   *
   * Deliberately short. Other /civicrm/event/* paths use `id` to mean other
   * things (a participant, a registration), and guessing wrong here would 404 a
   * page that works.
   */
  protected const GUARDED_PATHS = [
    '/civicrm/event/register',
    '/civicrm/event/info',
  ];

  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of the router (32) — this needs nothing the router provides, and
    // the cheapest possible exit for the ~99% of requests that are not CiviCRM
    // event pages matters more than ordering.
    return [KernelEvents::REQUEST => [['onRequest', 40]]];
  }

  /**
   * Turns a request for a deleted event into a 404.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $this->normalisePath($request);
    if (!in_array($path, self::GUARDED_PATHS, TRUE)) {
      return;
    }

    $id = $request->query->get('id');
    // No id at all is CiviCRM's business, not ours — it has its own handling
    // for that, and it does not 500.
    if ($id === NULL || !ctype_digit((string) $id)) {
      return;
    }

    if ($this->eventExists((int) $id)) {
      return;
    }

    $this->logger->info('Refused @path for missing event @id (referrer: @referrer).', [
      '@path' => $path,
      '@id' => $id,
      '@referrer' => $request->headers->get('referer') ?: 'none',
    ]);

    throw new NotFoundHttpException();
  }

  /**
   * Returns the request path with any front-controller prefix removed.
   */
  protected function normalisePath(Request $request): string {
    $path = rtrim($request->getPathInfo(), '/');
    // Both /civicrm/event/register and /index.php/civicrm/event/register are
    // live in the wild; the access logs carry plenty of each.
    if (str_starts_with($path, '/index.php/')) {
      $path = substr($path, strlen('/index.php'));
    }
    return $path;
  }

  /**
   * Whether a civicrm_event row with this id exists.
   *
   * Fails open. If the table cannot be read — a separate CiviCRM database, a
   * connection problem — the answer is "assume it exists" and let CiviCRM
   * behave exactly as it does today. A guard for a 500 must never become a new
   * way to hide a working page.
   */
  protected function eventExists(int $id): bool {
    try {
      if (!$this->database->schema()->tableExists('civicrm_event')) {
        return TRUE;
      }
      return (bool) $this->database->select('civicrm_event', 'e')
        ->fields('e', ['id'])
        ->condition('e.id', $id)
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not check whether event @id exists: @message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      return TRUE;
    }
  }

}
