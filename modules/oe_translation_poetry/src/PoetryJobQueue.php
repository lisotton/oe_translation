<?php

declare(strict_types = 1);

namespace Drupal\oe_translation_poetry;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\Url;
use Drupal\tmgmt\JobInterface;

/**
 * Tracks the TMGMT jobs that need to be transformed into a Poetry request.
 */
class PoetryJobQueue {

  /**
   * The private temp store.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $store;

  /**
   * The namespace in the storage to keep the data in.
   *
   * @var string
   */
  protected $namespace;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * PoetryJobQueue constructor.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStore $store
   *   The private temp store.
   * @param string $namespace
   *   The namespace in the storage to keep the data in.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(PrivateTempStore $store, string $namespace, EntityTypeManagerInterface $entityTypeManager, LanguageManagerInterface $languageManager) {
    $this->store = $store;
    $this->entityTypeManager = $entityTypeManager;
    $this->languageManager = $languageManager;
    $this->namespace = $namespace;
  }

  /**
   * Adds a job to the queue.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   */
  public function addJob(JobInterface $job): void {
    $queue = $this->initializeQueue();

    $jobs = &$queue['jobs'];
    if (!in_array($job->id(), $jobs)) {
      $jobs[] = $job->id();
      $this->saveQueue($queue);
    }
  }

  /**
   * Sets the entity type and ID of the entity being translated.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $id
   *   The entity ID.
   */
  public function setEntityId(string $entity_type, string $id): void {
    $queue = $this->initializeQueue();
    $queue['entity_type'] = $entity_type;
    $queue['entity_revision_id'] = $id;
    $this->saveQueue($queue);
  }

  /**
   * Returns the entity being translated.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity.
   */
  public function getEntity(): ContentEntityInterface {
    $queue = $this->initializeQueue();
    if (!$this->hasEntity()) {
      throw new \Exception('Missing Entity in the queue.');
    }

    return $this->entityTypeManager->getStorage($queue['entity_type'])->loadRevision($queue['entity_revision_id']);
  }

  /**
   * Checks whether the queue has the entity info saved.
   */
  public function hasEntity(): bool {
    $queue = $this->initializeQueue();
    return (bool) $queue['entity_revision_id'];
  }

  /**
   * Returns all the jobs in the queue.
   *
   * @return \Drupal\tmgmt\JobInterface[]
   *   The jobs.
   */
  public function getAllJobs(): array {
    $queue = $this->initializeQueue();
    if (!$queue['jobs']) {
      return [];
    }

    return $this->entityTypeManager->getStorage('tmgmt_job')->loadMultiple($queue['jobs']);
  }

  /**
   * Sets the destination URL.
   *
   * @param \Drupal\Core\Url $url
   *   The URL where the user will be redirected once the queue is processed.
   */
  public function setDestination(Url $url): void {
    $queue = $this->initializeQueue();
    $queue['destination'] = $url;
    $this->saveQueue($queue);
  }

  /**
   * Returns the destination URL.
   *
   * @return \Drupal\Core\Url|null
   *   The URL where the user will be redirected once the queue is processed.
   */
  public function getDestination(): ?Url {
    $queue = $this->initializeQueue();
    return $queue['destination'];
  }

  /**
   * Resets the entire queue.
   */
  public function reset(): void {
    $this->store->delete('queue.' . $this->namespace);
  }

  /**
   * Returns an array of the job target languages keyed by language code.
   *
   * @return array
   *   The languages.
   */
  public function getTargetLanguages(): array {
    $target_languages = [];
    foreach ($this->getAllJobs() as $job) {
      $target_languages[$job->getTargetLangcode()] = $this->languageManager->getLanguage($job->getTargetLangcode())->getName();
    }

    return $target_languages;
  }

  /**
   * Initializes the queue if empty and returns the queue data.
   *
   * @return array
   *   The queue data.
   */
  protected function initializeQueue(): array {
    $queue = $this->store->get('queue.' . $this->namespace);
    if (!$queue) {
      $queue = [
        'jobs' => [],
        'entity_revision_id' => NULL,
        'entity_type' => NULL,
        'destination' => NULL,
      ];
    }

    return $queue;
  }

  /**
   * Saves the queue data in the right place.
   *
   * @param array $queue
   *   The queue info.
   */
  protected function saveQueue(array $queue): void {
    $this->store->set('queue.' . $this->namespace, $queue);
  }

}
