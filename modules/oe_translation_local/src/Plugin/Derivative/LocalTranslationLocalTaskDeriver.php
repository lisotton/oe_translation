<?php

declare(strict_types = 1);

namespace Drupal\oe_translation_local\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\oe_translation\TranslatorProvidersInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates the local translation tasks for our translation system.
 */
class LocalTranslationLocalTaskDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * The translation providers service.
   *
   * @var \Drupal\oe_translation\TranslatorProvidersInterface
   */
  protected $translatorProviders;

  /**
   * Creates a LocalTranslationLocalTaskDeriver object.
   *
   * @param \Drupal\oe_translation\TranslatorProvidersInterface $translatorProviders
   *   The translation providers service.
   */
  public function __construct(TranslatorProvidersInterface $translatorProviders) {
    $this->translatorProviders = $translatorProviders;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('oe_translation.translator_providers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [];

    $entity_types = $this->translatorProviders->getDefinitions();
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if (!$this->translatorProviders->hasLocal($entity_type)) {
        continue;
      }
      $this->derivatives["$entity_type_id.local_translation"] = [
        'route_name' => "entity.$entity_type_id.local_translation",
        'title' => $this->t('Local translations'),
        'parent_id' => "content_translation.local_tasks:entity.$entity_type_id.content_translation_overview",
        'weight' => 10,
      ] + $base_plugin_definition;
    }

    return $this->derivatives;
  }

}
