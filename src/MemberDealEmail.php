<?php

namespace Drupal\makehaven_event_capacity;

/**
 * Builds the Last-Minute Class Seats email.
 *
 * Plain PHP with no Drupal services, so the markup can be rendered and looked
 * at without a site. The first version was plain text: Postmark's click
 * tracking turned every link into a 200-character track.pstmrk.it URL in the
 * body, and members could not see what they would actually pay (JR,
 * 2026-10-21). Links now sit behind a button and short link text, and the
 * price is shown before and after the discount.
 *
 * Inline styles only: Gmail drops <style> blocks. Colors follow the theme
 * tokens (brand primary #8b1919).
 */
class MemberDealEmail {

  public const LOGO_URL = 'https://www.makehaven.org/sites/default/files/mh-logos/logo-banner-email.png';

  /**
   * Default intro paragraph. Tokens: [discount], [seats], [event_title].
   */
  public const DEFAULT_INTRO = "This class is running either way, and we would rather see the last seats filled. As a member on the Last-Minute Class Seats list, you can take [discount]% off.";

  /**
   * Formats a dollar amount: $67, or $78.50.
   */
  public static function money(float $amount): string {
    return '$' . (floor($amount) == $amount ? number_format($amount, 0) : number_format($amount, 2));
  }

  /**
   * The email body.
   *
   * @param array $p
   *   first_name, event_title, event_day, event_time, seats, discount, code,
   *   register_url, event_url, leave_url, and optionally image_url, summary,
   *   price_before, price_after (floats), intro.
   */
  public static function html(array $p): string {
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $brand = '#8b1919';
    $ink = '#1e1f24';
    $muted = '#616161';
    $font = 'font-family:Helvetica,Arial,sans-serif;';

    $seats = (int) ($p['seats'] ?? 0);
    $seatsLabel = $seats === 1 ? '1 seat left' : $seats . ' seats left';
    $before = isset($p['price_before']) ? (float) $p['price_before'] : NULL;
    $after = isset($p['price_after']) ? (float) $p['price_after'] : NULL;
    $hasPrice = $before !== NULL && $after !== NULL && $before > $after;

    $intro = strtr((string) ($p['intro'] ?? self::DEFAULT_INTRO), [
      '[discount]' => (string) (int) $p['discount'],
      '[seats]' => (string) $seats,
      '[event_title]' => (string) $p['event_title'],
    ]);

    $image = !empty($p['image_url'])
      ? '<tr><td style="padding:0;"><a href="' . $e($p['register_url']) . '"><img src="' . $e($p['image_url']) . '" width="560" alt="' . $e($p['event_title']) . '" style="display:block;width:100%;max-width:560px;height:auto;border:0;"></a></td></tr>'
      : '';

    $price = $hasPrice
      ? '<p style="margin:0 0 4px;' . $font . 'font-size:15px;color:' . $muted . ';">Your member price</p>'
        . '<p style="margin:0;' . $font . 'font-size:30px;font-weight:bold;color:' . $ink . ';">'
        . '<span style="font-size:20px;font-weight:normal;color:' . $muted . ';text-decoration:line-through;">' . $e(self::money($before)) . '</span>&nbsp; '
        . $e(self::money($after)) . '</p>'
      : '<p style="margin:0;' . $font . 'font-size:24px;font-weight:bold;color:' . $ink . ';">' . (int) $p['discount'] . '% off</p>';

    $button = 'Claim your seat' . ($hasPrice ? ' for ' . self::money($after) : '');

    $summary = !empty($p['summary'])
      ? '<p style="margin:0 0 20px;' . $font . 'font-size:15px;line-height:1.5;color:' . $ink . ';">' . $e($p['summary']) . ' <a href="' . $e($p['event_url']) . '" style="color:' . $brand . ';">Class details</a></p>'
      : '<p style="margin:0 0 20px;' . $font . 'font-size:15px;"><a href="' . $e($p['event_url']) . '" style="color:' . $brand . ';">Class details</a></p>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:0;background:#f2f2f2;">'
      // Inbox preview line.
      . '<div style="display:none;max-height:0;overflow:hidden;">' . $e($seatsLabel . ' in ' . $p['event_title'] . ', ' . $p['event_day'] . '. ' . (int) $p['discount'] . '% off for members.') . '</div>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f2;"><tr><td align="center" style="padding:24px 12px;">'
      . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;">'
      . '<tr><td align="center" style="padding:20px 24px 12px;"><img src="' . self::LOGO_URL . '" width="260" alt="MakeHaven" style="display:block;width:260px;max-width:100%;height:auto;border:0;"></td></tr>'
      . $image
      . '<tr><td style="padding:24px 28px 8px;">'
      . '<p style="margin:0 0 10px;"><span style="display:inline-block;padding:4px 10px;border-radius:12px;background:' . $brand . ';color:#ffffff;' . $font . 'font-size:12px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase;">Last-minute member seat</span></p>'
      . '<h1 style="margin:0 0 8px;' . $font . 'font-size:24px;line-height:1.25;color:' . $ink . ';">' . $e($p['event_title']) . '</h1>'
      . '<p style="margin:0 0 20px;' . $font . 'font-size:16px;color:' . $ink . ';"><strong>' . $e($p['event_day']) . '</strong>' . (!empty($p['event_time']) ? ' &middot; ' . $e($p['event_time']) : '') . ' &middot; <strong style="color:' . $brand . ';">' . $e($seatsLabel) . '</strong></p>'
      . '<p style="margin:0 0 12px;' . $font . 'font-size:16px;line-height:1.5;color:' . $ink . ';">Hi ' . $e($p['first_name']) . ',</p>'
      . '<p style="margin:0 0 20px;' . $font . 'font-size:16px;line-height:1.5;color:' . $ink . ';">' . $e($intro) . '</p>'
      . '</td></tr>'
      . '<tr><td style="padding:0 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border:1px solid #e7e7e7;border-radius:8px;"><tr><td align="center" style="padding:20px;">'
      . $price
      . '<a href="' . $e($p['register_url']) . '" style="display:inline-block;margin:16px 0 8px;padding:14px 32px;background:' . $brand . ';border-radius:6px;color:#ffffff;' . $font . 'font-size:17px;font-weight:bold;text-decoration:none;">' . $e($button) . '</a>'
      . '<p style="margin:0;' . $font . 'font-size:13px;color:' . $muted . ';">The discount is applied when you register. Code <strong>' . $e($p['code']) . '</strong> if you need it.</p>'
      . '</td></tr></table></td></tr>'
      . '<tr><td style="padding:20px 28px 8px;">'
      . $summary
      . '<p style="margin:0 0 24px;' . $font . 'font-size:13px;line-height:1.5;color:' . $muted . ';">One seat per member, until the seats are gone or the class starts.</p>'
      . '</td></tr>'
      . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e7e7e7;">'
      . '<p style="margin:0;' . $font . 'font-size:12px;line-height:1.5;color:' . $muted . ';">You are getting this because you joined Last-Minute Class Seats. <a href="' . $e($p['leave_url']) . '" style="color:' . $muted . ';">Stop these emails</a>.</p>'
      . '</td></tr>'
      . '</table></td></tr></table></body></html>';
  }

}
