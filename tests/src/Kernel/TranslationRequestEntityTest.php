<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_translation\Kernel;

use Drupal\Tests\tmgmt\Functional\TmgmtTestTrait;

/**
 * Tests the Translation Request entity.
 */
class TranslationRequestEntityTest extends TranslationKernelTestBase {

  use TmgmtTestTrait;

  /**
   * A translation request job entity to be referenced.
   *
   * @var \Drupal\oe_translation\Entity\TranslationRequestLogInterface
   */
  protected $translationRequestLog;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'options',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('oe_translation_request');
    $this->installEntitySchema('oe_translation_request_log');

    // Create test bundle.
    $type_storage = $this->container->get('entity_type.manager')->getStorage('oe_translation_request_type');
    $type_storage->create([
      'id' => 'request',
      'label' => 'Request',
    ])->save();

    // Create nodes to reference.
    $this->container->get('entity_type.manager')->getStorage('node_type')->create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'Node to be referenced in content entity',
    ]);
    $node->save();
    // Create a new revision.
    $node->setNewRevision(TRUE);
    $node->save();

    // Create a translation request log entity to be referenced.
    $translation_request_log_storage = $this->container->get('entity_type.manager')->getStorage('oe_translation_request_log');
    $this->translationRequestLog = $translation_request_log_storage->create([
      'message' => '@status: The translation request message.',
      'variables' => [
        '@status' => 'Draft',
      ],
    ]);
    $this->translationRequestLog->save();
  }

  /**
   * Tests Translation Request entities.
   */
  public function testTranslationRequestEntity(): void {
    // Create a translation request.
    $translation_request_storage = $this->container->get('entity_type.manager')->getStorage('oe_translation_request');
    $values = [
      'bundle' => 'request',
      'translation_provider' => 'Translation provider',
      'source_language_code' => 'en',
      'target_language_codes' => [
        'fr',
        'es',
      ],
      'request_status' => 'draft',
      'log' => $this->translationRequestLog->id(),
    ];
    /** @var \Drupal\oe_translation\Entity\TranslationRequestInterface $translation_request */
    $translation_request = $translation_request_storage->create($values);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision(2);
    $translation_request->setContentEntity($node);
    $translation_request->save();

    // Assert values are saved and retrieved properly from the entity.
    $entity = $translation_request->getContentEntity();
    $this->assertEquals(1, $entity->id());
    $this->assertEquals(2, $entity->getRevisionId());
    $this->assertEquals('node', $entity->getEntityTypeId());
    $this->assertEquals('Translation provider', $translation_request->getTranslationProvider());
    $this->assertEquals('en', $translation_request->getSourceLanguageCode());
    $this->assertEquals([
      'fr',
      'es',
    ], $translation_request->getTargetLanguageCodes());
    $this->assertEquals('draft', $translation_request->getRequestStatus());

    // Update some entity values.
    $translation_request->setTranslationProvider('New translation provider');
    $this->assertEquals('New translation provider', $translation_request->getTranslationProvider());

    $translation_request->setSourceLanguageCode('ro');
    $this->assertEquals('ro', $translation_request->getSourceLanguageCode());

    $translation_request->setTargetLanguageCodes(['de', 'el']);
    $this->assertEquals([
      'de',
      'el',
    ], $translation_request->getTargetLanguageCodes());

    $translation_request->setRequestStatus('accepted');
    $this->assertEquals('accepted', $translation_request->getRequestStatus());

    $data_for_translation = [
      'title' => 'Page title',
      'description' => 'Page description',
    ];
    $translation_request->setData($data_for_translation);
    $this->assertEquals($data_for_translation, $translation_request->getData());
    $log = $translation_request->getLog();
    $this->assertEquals($this->translationRequestLog->getMessage(), $log->getMessage());
    $this->assertEquals($this->translationRequestLog->getType(), $log->getType());
  }

}
