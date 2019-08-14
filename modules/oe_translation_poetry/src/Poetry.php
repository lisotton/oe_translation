<?php

declare(strict_types = 1);

namespace Drupal\oe_translation_poetry;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\State;
use Drupal\tmgmt\Entity\Job;
use EC\Poetry\Messages\Components\Identifier;
use EC\Poetry\Poetry as PoetryLibrary;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Poetry client.
 *
 * Integrates the Poetry client library with Drupal.
 */
class Poetry extends PoetryLibrary {

  /**
   * The settings provided by the translator config.
   *
   * @var array
   */
  protected $translatorSettings;

  /**
   * The state.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a Poetry instance.
   *
   * @param array $settings
   *   The settings provided by the translator config.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   The logger channel.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\State\State $state
   *   The state.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(array $settings, ConfigFactoryInterface $configFactory, LoggerChannelInterface $loggerChannel, LoggerInterface $logger, State $state, EntityTypeManagerInterface $entityTypeManager, Connection $database) {
    // @todo improve this in case we need alternative logging mechanisms.
    $loggerChannel->addLogger($logger);
    // Cannot rely on the translator getSetting() method because that might
    // instantiate the corresponding plugin which results in a circular
    // reference error.
    $values = [
      'identifier.code' => $settings['identifier_code'] ?? 'WEB',
      // The default version will always start from 0.
      'identifier.version' => 0,
      // The default part will always start from 0.
      'identifier.part' => 0,
      'identifier.year' => date('Y'),
      'service.username' => Settings::get('poetry.service.username'),
      'service.password' => Settings::get('poetry.service.password'),
      'notification.username' => Settings::get('poetry.notification.username'),
      'notification.password' => Settings::get('poetry.notification.password'),
      'logger' => $loggerChannel,
      'log_level' => LogLevel::INFO,
    ];

    if (isset($settings['service_wsdl'])) {
      $values['service.wsdl'] = $settings['service_wsdl'];
    }

    if (!isset($settings['site_id'])) {
      $settings['site_id'] = $configFactory->get('system.site')->get('name');
    }

    parent::__construct($values);
    $this->translatorSettings = $settings;
    $this->state = $state;
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
  }

  /**
   * Gets the global identification number.
   *
   * @return string|null
   *   The number.
   */
  public function getGlobalIdentifierNumber(): ?string {
    return $this->state->get('oe_translation_poetry_id_number');
  }

  /**
   * Sets the global identification number.
   *
   * @param string $number
   *   The number.
   */
  public function setGlobalIdentifierNumber(string $number): void {
    $this->state->set('oe_translation_poetry_id_number', $number);
  }

  /**
   * Returns the identifier for making a translation request for a content.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return \EC\Poetry\Messages\Components\Identifier
   *   The identifier.
   */
  public function getIdentifierForContent(ContentEntityInterface $entity): Identifier {
    $last_identifier_for_content = $this->getLastIdentifierForContent($entity);
    if ($last_identifier_for_content instanceof Identifier) {
      // If the content has already been translated, we need to use the
      // identifier it had and just increase the version. This means potentially
      // even an older number than the most current global one as well as an
      // older year.
      $last_identifier_for_content->setVersion($last_identifier_for_content->getVersion() + 1);
      return $last_identifier_for_content;
    }

    $identifier = $this->getIdentifier();
    $number = $this->getGlobalIdentifierNumber();

    if (!$number) {
      // If we don't have a number it means it's the first ever request.
      $identifier->setSequence(Settings::get('poetry.identifier.sequence'));
      return $identifier;
    }

    // If we have a global number, we can maybe use it. However, we first to
    // determine the part. And for this we need to check the jobs.
    $part = $this->getLastPartForNumber($number);
    if ($part > -1) {
      // We check if the part came back as -1 in case jobs were missing from
      // the system, we increment only if we know where to increment from.
      $part++;
    }

    // If the incremented part is 100, we need to scrap the the global number
    // and request a new one. The maximum can be 99.
    if ($part === 100) {
      $identifier->setSequence(Settings::get('poetry.identifier.sequence'));
      return $identifier;
    }

    $identifier->setNumber($number);

    if ($part > -1) {
      // In case we return a -1, we don't want to set that.
      $identifier->setPart($part);
    }

    return $identifier;
  }

  /**
   * Returns the settings configured in the translator.
   *
   * @return array
   *   The settings.
   */
  public function getTranslatorSettings(): array {
    return $this->translatorSettings;
  }

  /**
   * Locates the last identifier that was used for a given content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return \EC\Poetry\Messages\Components\Identifier
   *   The identifier.
   */
  protected function getLastIdentifierForContent(ContentEntityInterface $entity): ?Identifier {
    $query = $this->database->select('tmgmt_job', 'job');
    $query->join('tmgmt_job_item', 'job_item', 'job.tjid = job_item.tjid');
    $query->fields('job');
    $query->condition('job_item.item_id', $entity->id());
    // Do not include unprocessed Jobs. These are the ones which have not been
    // ever sent to Poetry.
    $query->condition('job.state', Job::STATE_UNPROCESSED, '!=');
    $query->orderBy('job.poetry_request_id__version', 'DESC');
    $result = $query->execute()->fetchCol('poetry_request_id__version');
    if (!$result) {
      return NULL;
    }

    $job = $this->entityTypeManager->getStorage('tmgmt_job')->load(reset($result));

    $identifier = new Identifier();
    $identifier->withArray($job->get('poetry_request_id')->first()->getValue());
    return $identifier;
  }

  /**
   * Gets the next part to use for a global number.
   *
   * @param string $number
   *   The number.
   *
   * @return int
   *   The part.
   */
  protected function getLastPartForNumber(string $number): int {
    $job_ids = $this->entityTypeManager->getStorage('tmgmt_job')->getQuery()
      ->condition('poetry_request_id__number', $number)
      ->sort('poetry_request_id.part', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$job_ids) {
      // Normally we should get a value since the number must have been used
      // on previous jobs.
      return -1;
    }

    /** @var \Drupal\tmgmt\JobInterface $job */
    $job = $this->entityTypeManager->getStorage('tmgmt_job')->load(reset($job_ids));
    return (int) $job->get('poetry_request_id')->part;
  }

}
