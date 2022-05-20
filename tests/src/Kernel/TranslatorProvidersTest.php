<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_translation\Kernel;

/**
 * Tests the translator providers service.
 */
class TranslatorProvidersTest extends TranslationKernelTestBase {

  /**
   * Tests the translator providers configuration.
   */
  public function testTranslatorProviders(): void {
    $translator_providers_service = \Drupal::service('oe_translation.translator_providers');
    // Asserts that the node entity type has its definition updated with the
    // oe_translation translators configuration.
    $entity_type = \Drupal::entityTypeManager()->getDefinition('node');
    $this->assertTrue($translator_providers_service->hasLocal($entity_type));
    $this->assertTrue($translator_providers_service->hasRemote($entity_type));
    $remote = ['epoetry'];
    $this->assertEquals($remote, $translator_providers_service->getRemotePlugins($entity_type));

    // Asserts that the tmgmt_job entity type doesn't contain the oe_translation
    // translators configuration.
    $entity_type = \Drupal::entityTypeManager()->getDefinition('tmgmt_job');
    $this->assertFalse($translator_providers_service->hasLocal($entity_type));
    $this->assertFalse($translator_providers_service->hasRemote($entity_type));
    $remote = [];
    $this->assertEquals($remote, $translator_providers_service->getRemotePlugins($entity_type));
  }

}
