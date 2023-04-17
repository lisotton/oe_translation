<?php

declare(strict_types=1);

namespace Drupal\oe_translation_epoetry;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Http\Discovery\Psr17Factory;
use OpenEuropa\EPoetry\TicketValidation\EuLogin\EuLoginTicketValidation;

/**
 * Validation service for the notifications requests that come from ePoetry.
 */
class NotificationTicketValidation extends EuLoginTicketValidation {

  /**
   * {@inheritdoc}
   */
  public function __construct(ClientInterface $guzzle, LoggerChannelFactoryInterface $logger_channel_factory) {
    parent::__construct(static::getCallbackUrl(), static::getEuLoginBasePath(), static::getEuLoginJobAccount(), new Psr17Factory(), $guzzle, $logger_channel_factory->get('oe_translation_epoetry'));
  }

  /**
   * Returns if we should use the ticket validation.
   *
   * @return bool
   *   Whether ticket validation should be used.
   */
  public static function shouldUseTicketValidation(): bool {
    return Settings::get('epoetry.ticket_validation.on') ? (bool) Settings::get('epoetry.ticket_validation.on') : FALSE;
  }

  /**
   * Returns the EULogin base path used for the ticket validation.
   *
   * @return string|null
   *   The base path.
   */
  public static function getEuLoginBasePath(): ?string {
    return Settings::get('epoetry.ticket_validation.eulogin_base_path') ? Settings::get('epoetry.ticket_validation.eulogin_base_path') : '';
  }

  /**
   * Returns the EULogin job account used for the ticket validation.
   *
   * @return string|null
   *   The base path.
   */
  public static function getEuLoginJobAccount(): ?string {
    return Settings::get('epoetry.ticket_validation.eulogin_job_account') ? Settings::get('epoetry.ticket_validation.eulogin_job_account') : '';
  }

  /**
   * Returns the EULogin callback URL to be used for the ticket validation.
   *
   * @return string|null
   *   The base path.
   */
  public static function getCallbackUrl(): ?string {
    return Settings::get('epoetry.ticket_validation.callback_url') ? Settings::get('epoetry.ticket_validation.callback_url') : '';
  }

}
