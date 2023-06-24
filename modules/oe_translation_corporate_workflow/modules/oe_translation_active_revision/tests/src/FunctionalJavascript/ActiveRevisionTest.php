<?php

declare(strict_types = 1);

namespace Drupal\Tests\oe_translation_active_revision\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\oe_translation_active_revision\ActiveRevisionInterface;
use Drupal\oe_translation_active_revision\Entity\ActiveRevision;
use Drupal\oe_translation_active_revision\Plugin\Field\FieldType\LanguageWithEntityRevisionItem;
use Drupal\Tests\oe_editorial\Traits\BatchTrait;
use Drupal\Tests\oe_editorial_corporate_workflow\Traits\CorporateWorkflowTrait;
use Drupal\Tests\oe_translation\Traits\TranslationsTestTrait;
use Drupal\user\Entity\Role;

/**
 * Tests Active revision functionality.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @group batch2
 */
class ActiveRevisionTest extends WebDriverTestBase {

  use BatchTrait;
  use TranslationsTestTrait;
  use CorporateWorkflowTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'toolbar',
    'content_translation',
    'user',
    'field',
    'text',
    'options',
    'oe_editorial_workflow_demo',
    'oe_translation',
    'oe_translation_corporate_workflow',
    'oe_translation_local',
    'oe_translation_active_revision',
    'oe_translation_active_revision_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = \Drupal::entityTypeManager();

    \Drupal::service('content_translation.manager')->setEnabled('node', 'page', TRUE);
    \Drupal::service('oe_editorial_corporate_workflow.workflow_installer')->installWorkflow('page');
    $default_values = [
      'major' => 0,
      'minor' => 1,
      'patch' => 0,
    ];
    \Drupal::service('entity_version.entity_version_installer')->install('node', ['page'], $default_values);

    \Drupal::entityTypeManager()->getStorage('entity_version_settings')->create([
      'target_entity_type_id' => 'node',
      'target_bundle' => 'page',
      'target_field' => 'version',
    ])->save();

    \Drupal::service('router.builder')->rebuild();

    $user = $this->setUpTranslatorUser();
    // Grant the editorial roles.
    foreach (['oe_author', 'oe_reviewer', 'oe_validator'] as $role) {
      $user->addRole($role);
      $user->save();
    }

    $role = Role::load('oe_translator');
    $role->grantPermission('delete any page content');
    $role->grantPermission('delete content translations');
    $role->grantPermission('delete all revisions');
    $role->save();
    $this->drupalLogin($user);
  }

  /**
   * Tests that when we validated/publish, we can map the translations.
   */
  public function testActiveRevisionMapping(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Create a node, validate it, and translate it.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'validated');

    $node->addTranslation('fr', ['title' => 'My FR node']);
    $node->save();

    // Go to the node moderation state form and assert we don't see our
    // elements for mapping the active revision.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->fieldNotExists('The new version needs NEW translations');
    $this->assertSession()->fieldNotExists('The new version does NOT need NEW translations (there have been only changes to non-translatable fields)');

    $node->delete();

    // Now create a node for each target moderation state and assert we will
    // get our form elements to choose what to do with the translations upon
    // making a new revision.
    $states = [
      'Validated',
      'Published',
    ];

    foreach ($states as $state) {
      // Create a node and translate it.
      /** @var \Drupal\node\NodeInterface $node */
      $node = $node_storage->create([
        'type' => 'page',
        'title' => 'My node',
        'field_non_translatable_field' => 'Non translatable value',
        'moderation_state' => 'draft',
      ]);
      $node->save();

      $node = $node_storage->load($node->id());
      $node = $this->moderateNode($node, 'published');

      $node->addTranslation('fr', ['title' => 'My FR node']);
      $node->save();

      // Keep track of the version 1 revision ID.
      $version_one_revision_id = $node->getRevisionId();

      $this->drupalGet($node->toUrl());
      $this->assertSession()->pageTextContains('My node');
      $this->assertSession()->pageTextContains('Non translatable value');
      $this->assertSession()->fieldNotExists('The new version needs NEW translations');
      $this->assertSession()->fieldNotExists('The new version does NOT need NEW translations (there have been only changes to non-translatable fields)');

      $this->drupalGet('/fr/node/' . $node->id());
      $this->assertSession()->pageTextContains('My FR node');
      $this->assertSession()->pageTextContains('Non translatable value');

      // Start a new draft.
      $node->set('title', 'My updated node');
      $node->set('field_non_translatable_field', 'Non translatable updated value');
      $node->set('moderation_state', 'draft');
      $node->setNewRevision();
      $node->save();

      $this->drupalGet($node->toUrl('latest-version'));
      $this->assertSession()->fieldExists('The new version needs NEW translations');
      $this->assertSession()->fieldExists('The new version does NOT need NEW translations (there have been only changes to non-translatable fields)');
      $this->assertFalse($this->getSession()->getPage()->findField('The new version needs NEW translations')->isVisible());
      $this->getSession()->getPage()->selectFieldOption('Change to', $state);
      $this->assertTrue($this->getSession()->getPage()->findField('The new version needs NEW translations')->isVisible());
      $this->assertTrue($this->getSession()->getPage()->findField('The new version needs NEW translations')->isChecked());
      $this->assertTrue($this->getSession()->getPage()->findField('The new version does NOT need NEW translations (there have been only changes to non-translatable fields)')->isVisible());
      $this->assertFalse($this->getSession()->getPage()->findField('The new version does NOT need NEW translations (there have been only changes to non-translatable fields)')->isChecked());
      $this->assertTrue($this->getSession()->getPage()->findField('Keep current translations until new ones are synchronised.')->isVisible());
      $this->assertTrue($this->getSession()->getPage()->findField('Keep current translations until new ones are synchronised.')->isChecked());
      $this->assertTrue($this->getSession()->getPage()->findField('Delete current translations until new ones are synchronized')->isVisible());
      $this->assertFalse($this->getSession()->getPage()->findField('Delete current translations until new ones are synchronized')->isChecked());

      // Assert the state changes the other options.
      $this->getSession()->getPage()->findField('The new version does NOT need NEW translations (there have been only changes to non-translatable fields)')->click();
      $this->assertFalse($this->getSession()->getPage()->findField('Keep current translations until new ones are synchronised.')->isVisible());
      $this->assertFalse($this->getSession()->getPage()->findField('Delete current translations until new ones are synchronized')->isVisible());
      // Change back to the default.
      $this->getSession()->getPage()->findField('The new version needs NEW translations')->click();
      $this->assertTrue($this->getSession()->getPage()->findField('Keep current translations until new ones are synchronised.')->isVisible());
      $this->assertTrue($this->getSession()->getPage()->findField('Keep current translations until new ones are synchronised.')->isChecked());

      // Update the moderation state.
      $this->getSession()->getPage()->pressButton('Apply');
      $this->waitForBatchExecution();
      $this->assertSession()->waitForText('The moderation state has been updated.');

      // One active revision entity was created, mapping the FR language to the
      // previous revision.
      $this->assertCount(1, ActiveRevision::loadMultiple());
      $active_revision = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision')->getActiveRevisionForEntity('node', $node->id());
      $this->assertInstanceOf(ActiveRevisionInterface::class, $active_revision);
      $language_values = $active_revision->get('field_language_revision')->getValue();
      $this->assertCount(1, $language_values);
      $this->assertEquals([
        'entity_type' => 'node',
        'entity_id' => $node->id(),
        'entity_revision_id' => $version_one_revision_id,
        'langcode' => 'fr',
        'scope' => 0,
      ], $language_values[0]);

      // Visit the node page and assert we see the correct versions.
      if ($state === 'Published') {
        $this->drupalGet($node->toUrl());
      }
      else {
        $this->drupalGet($node->toUrl('latest-version'));
      }

      $this->assertSession()->pageTextContains('My updated node');
      $this->assertSession()->pageTextContains('Non translatable updated value');

      if ($state === 'Published') {
        $this->drupalGet('/fr/node/' . $node->id());
        $this->assertSession()->pageTextContains('My FR node');
        $this->assertSession()->pageTextContains('Non translatable value');
      }
      else {
        // We have 2 revision routes where our param converter applies.
        $this->drupalGet('/fr/node/' . $node->id() . '/latest');
        $this->assertSession()->pageTextContains('My FR node');
        $this->assertSession()->pageTextContains('Non translatable value');
        $this->drupalGet('/fr/node/' . $node->id() . '/revisions/' . $version_one_revision_id . '/view');
        $this->assertSession()->pageTextContains('My FR node');
        $this->assertSession()->pageTextContains('Non translatable value');
      }

      // Visit a view of nodes and assert that also in the teaser, we see the
      // correct version. This can only work for the Published case.
      if ($state === 'Published') {
        $this->drupalGet('/nodes');
        $this->assertSession()->pageTextContains('My updated node');
        $this->assertSession()->pageTextContains('Non translatable updated value');
        $this->drupalGet('/fr/nodes');
        $this->assertSession()->pageTextContains('My FR node');
        $this->assertSession()->pageTextContains('Non translatable value');
      }

      // Delete the active revision and assert we see the revisions as we would
      // by default.
      $active_revision->delete();

      if ($state === 'Published') {
        $this->drupalGet($node->toUrl());
      }
      else {
        $this->drupalGet($node->toUrl('latest-version'));
      }

      $this->assertSession()->pageTextContains('My updated node');
      $this->assertSession()->pageTextContains('Non translatable updated value');
      if ($state === 'Published') {
        $this->drupalGet('/fr/node/' . $node->id());
      }
      else {
        $this->drupalGet('/fr/node/' . $node->id() . '/latest');
      }
      $this->assertSession()->pageTextContains('My FR node');
      $this->assertSession()->pageTextContains('Non translatable updated value');

      if ($state === 'Published') {
        $this->drupalGet('/nodes');
        $this->assertSession()->pageTextContains('My updated node');
        $this->assertSession()->pageTextContains('Non translatable updated value');
        $this->drupalGet('/fr/nodes');
        $this->assertSession()->pageTextContains('My FR node');
        $this->assertSession()->pageTextContains('Non translatable updated value');
      }

      $node->delete();
    }

  }

  /**
   * Tests that new translations are mapped to the correct revision.
   *
   * Tests the situation in which we have for version 1 a translation in FR and
   * then we make version 2 for which we map FR to version 1. However, in
   * version 2 we add a translation in IT as well. Then, in version 3, we keep
   * the mapping of FR to version 1, but the IT to version 2.
   */
  public function testIncrementingVersionTranslations(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Create version 1 with a FR translation.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');

    $node->addTranslation('fr', ['title' => 'My FR version 1 node']);
    $node->save();

    // Keep track of the version 1 revision ID.
    $version_one_revision_id = $node->getRevisionId();

    // Make a change and create version 2, mapping FR to version 1.
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    // By default, the mapping option is selected by default.
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // One active revision entity was created, mapping the FR language to the
    // previous revision.
    $this->assertCount(1, ActiveRevision::loadMultiple());
    $active_revision = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision')->getActiveRevisionForEntity('node', $node->id());
    $this->assertInstanceOf(ActiveRevisionInterface::class, $active_revision);
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertCount(1, $language_values);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);

    $node_storage->resetCache();
    $node = $node_storage->load($node->id());

    // Add the IT translation to version 2.
    $node->addTranslation('it', ['title' => 'My IT version 2 node']);
    $node->save();

    // Keep track of the version 2 revision ID.
    $version_two_revision_id = $node->getRevisionId();

    // Make a change and create version 3, mapping IT to version 2 and keeping
    // FR mapped to version 1.
    $node->set('title', 'My version 3 node');
    $node->set('field_non_translatable_field', 'Non translatable version 3 updated value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    // By default, the mapping option is selected by default.
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    $this->assertCount(1, ActiveRevision::loadMultiple());
    $active_revision = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision')->getActiveRevisionForEntity('node', $node->id());
    $this->assertInstanceOf(ActiveRevisionInterface::class, $active_revision);
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertCount(2, $language_values);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_two_revision_id,
      'langcode' => 'it',
      'scope' => 0,
    ], $language_values[1]);

    $this->drupalGet('/fr/node/' . $node->id());
    $this->assertSession()->pageTextContains('My FR version 1 node');
    $this->assertSession()->pageTextContains('Non translatable value');
    $this->drupalGet('/it/node/' . $node->id());
    $this->assertSession()->pageTextContains('My IT version 2 node');
    // Since we added the IT translation in version 2, we see the non
    // translatable value from that version.
    $this->assertSession()->pageTextContains('Non translatable updated version 2 value');

    // Delete the node and assert the active revision entity is also deleted.
    $node->delete();
    $this->assertCount(0, ActiveRevision::loadMultiple());
  }

  /**
   * Tests that the active revision applies in the correct scope.
   *
   * We ensure that if the scope is set to only apply to published revisions,
   * the validated one no longer shows the mapping.
   */
  public function testActiveRevisionScope(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Create version 1 with a FR translation.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');

    $node->addTranslation('fr', ['title' => 'My FR version 1 node']);
    $node->save();

    // Keep track of the version 1 revision ID.
    $version_one_revision_id = $node->getRevisionId();

    // Make a change and create version 2, mapping FR to version 1.
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    // By default, the mapping option is selected by default.
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Make a change and create version 3 but only validated.
    $node->set('title', 'My version 3 node');
    $node->set('field_non_translatable_field', 'Non translatable version 3 updated value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    // By default, the mapping option is selected by default.
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Validated');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    $this->assertCount(1, ActiveRevision::loadMultiple());
    $active_revision = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision')->getActiveRevisionForEntity('node', $node->id());
    $this->assertInstanceOf(ActiveRevisionInterface::class, $active_revision);
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertCount(1, $language_values);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);

    // For the moment, both the published version and the validated version
    // show the version 1 translation in FR.
    $this->drupalGet('/fr/node/' . $node->id());
    $this->assertSession()->pageTextContains('My FR version 1 node');
    $this->assertSession()->pageTextContains('Non translatable value');
    $this->drupalGet('/fr/node/' . $node->id() . '/latest');
    $this->assertSession()->pageTextContains('My FR version 1 node');
    $this->assertSession()->pageTextContains('Non translatable value');

    // Now change the scope of the mapping to only apply to the Published
    // version.
    $language_values[0]['scope'] = LanguageWithEntityRevisionItem::SCOPE_PUBLISHED;
    $active_revision->set('field_language_revision', $language_values);
    $active_revision->save();

    $this->drupalGet('/fr/node/' . $node->id());
    $this->assertSession()->pageTextContains('My FR version 1 node');
    $this->assertSession()->pageTextContains('Non translatable value');
    $this->drupalGet('/fr/node/' . $node->id() . '/latest');
    $this->assertSession()->pageTextContains('My FR version 1 node');
    $this->assertSession()->pageTextContains('Non translatable version 3 updated value');

    // Publish the node and make a new version, validating it.
    $this->drupalGet('/node/' . $node->id() . '/latest');
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');
    $node->set('title', 'My version 4 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 4 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    // By default, the mapping option is selected by default.
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Validated');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Assert that the active revision mapping scope is back to support both
    // moderation states.
    $active_revision = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision')->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
  }

  /**
   * Tests the translation delete.
   *
   * Tests the with the override on the moderation form from this module, we
   * can still drop the translations. This is also tested in
   * CorporateWorkflowTranslationDropTest.
   */
  public function testTranslationsDrop(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Create version 1 with a FR translation.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');

    $node->addTranslation('fr', ['title' => 'My FR version 1 node']);
    $node->save();

    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $this->assertTrue($node->hasTranslation('fr'));

    $version_one_revision_id = $node->getRevisionId();

    // Make a change and create version 2, marking to delete the translations.
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->findField('Delete current translations until new ones are synchronized')->click();
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Assert that version 1 has the translation but version 2 no longer does.
    $version_one = $node_storage->loadRevision($version_one_revision_id);
    $this->assertTrue($version_one->hasTranslation('fr'));
    $node = $node_storage->load($node->id());
    $this->assertFalse($node->hasTranslation('fr'));
  }

  /**
   * Tests that we can create/remove/update a mapping for Published nodes.
   */
  public function testMappingOperationsForSingleVersion(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\oe_translation_active_revision\ActiveRevisionStorage $active_revision_storage */
    $active_revision_storage = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision');

    // Create version 1 with a FR translation.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');

    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="fr"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My FR version 1 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been saved.');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');

    // Keep track of the version 1 revision ID.
    $version_one_revision_id = $node->getRevisionId();

    // Make a change and create version 2, mapping FR to version 1.
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Keep track of the revision ID of version 2.
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $version_two_revision_id = $node->getRevisionId();
    $this->drupalGet('/node/' . $node->id() . '/translations');
    $table = $this->getSession()->getPage()->find('css', 'table.existing-translations-table');
    $french_row = $table->find('xpath', '//tr[@hreflang="fr"]');
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $italian_row = $table->find('xpath', '//tr[@hreflang="it"]');
    // The FR translation is mapped to version 1 and we don't have a translation
    // for IT.
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('No translation', $italian_row->find('xpath', '//td[2]')->getText());
    $this->assertOperationLinks([
      'View' => TRUE,
      // We cannot delete the translation as it's mapped.
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      // The update mapping op is missing because we only have 1 previous major
      // version it can map to, and it's already mapped to it.
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);

    // Remove the mapping.
    $this->getSession()->getPage()->pressButton('List additional actions');
    $this->clickLink('Remove mapping');
    $this->assertSession()->pageTextContains('Are you sure you want to remove this mapping?');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been removed. There are no more language mappings for this entity.');
    // The existing active revision entity was deleted.
    $this->assertCount(0, $active_revision_storage->loadMultiple());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      // Now we can delete the translation as it's no longer mapped.
      'Delete translation' => TRUE,
      'Add mapping' => TRUE,
      'Map to version' => FALSE,
      'Remove mapping' => FALSE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);

    // Map to null (hide the translation).
    $this->getSession()->getPage()->pressButton('List additional actions');
    $this->clickLink('Map to "hidden" (hide translation)');
    $this->assertSession()->pageTextContains('Are you sure you want to map this translation to "hidden"?');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The translation has been mapped to "hidden". It has not been deleted so you can always remove this mapping.');
    $this->assertCount(1, $active_revision_storage->loadMultiple());
    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => 0,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    $this->assertEquals('Mapped to "hidden" (translation hidden)', $french_row->find('xpath', '//td[2]')->getText());
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => TRUE,
      'Remove mapping' => TRUE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => FALSE,
    ], $french_operations);

    // Map to a version.
    $this->getSession()->getPage()->pressButton('List additional actions');
    $this->clickLink('Map to version');
    $this->assertSession()->pageTextContains('Updating mapping for My version 2 node');
    $this->assertEquals('required', $this->assertSession()->selectExists('Update mapping for French')->getAttribute('required'));
    // We can only pick version 1. Version 2 is the currently published
    // version so we cannot pick it as it would make no sense to map to itself.
    $this->assertEquals([
      '' => '- Select -',
      $version_one_revision_id => '1.0.0',
    ], $this->getSelectOptions('Update mapping for French'));
    $this->getSession()->getPage()->selectFieldOption('Update mapping for French', '1.0.0');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been updated.');
    $active_revision_storage->resetCache();
    $active_revision = $active_revision_storage->load($active_revision->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
     // The update mapping op is missing because we only have 1 previous major
     // version it can map to, and it's already mapped to it.
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);

    // Remove the mapping.
    $this->getSession()->getPage()->pressButton('List additional actions');
    $this->clickLink('Remove mapping');
    $this->assertSession()->pageTextContains('Are you sure you want to remove this mapping?');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been removed. There are no more language mappings for this entity.');
    // The existing active revision entity was deleted.
    $this->assertCount(0, $active_revision_storage->loadMultiple());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => TRUE,
      'Add mapping' => TRUE,
      'Map to version' => FALSE,
      'Remove mapping' => FALSE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);

    // Add mapping (this behaves like "Update mapping", but it's available also
    // when there is no mapping yet).
    $this->getSession()->getPage()->pressButton('List additional actions');
    $this->clickLink('Add mapping');
    $this->assertSession()->pageTextContains('Add a mapping for My version 2 node');
    $this->assertEquals('required', $this->assertSession()->selectExists('Add mapping for French')->getAttribute('required'));
    $this->assertEquals([
      '' => '- Select -',
      $version_one_revision_id => '1.0.0',
    ], $this->getSelectOptions('Add mapping for French'));
    $this->getSession()->getPage()->selectFieldOption('Add mapping for French', '1.0.0');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been added.');
    $active_revision_storage->resetCache();
    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);

    // Add an italian translation to version 2.0.0.
    $node_storage->resetCache();
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->load($node->id());
    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="it"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My IT version 2 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been saved.');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');
    $this->drupalGet('/node/' . $node->id() . '/translations');
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 2.0.0', $italian_row->find('xpath', '//td[2]')->getText());
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);
    // For the new language created in the latest version, we can only hide it.
    // We cannot map it to anything.
    $italian_operations = $italian_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => TRUE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => FALSE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $italian_operations);
    // Assert we can see the italian translation.
    $this->drupalGet('/it/node/' . $node->id());
    $this->assertSession()->pageTextContains('My IT version 2 node');
    $this->assertSession()->pageTextContains('Non translatable updated version 2 value');
    // Hide the IT from version 2.
    $this->drupalGet('/node/' . $node->id() . '/translations');
    $italian_row->pressButton('List additional actions');
    $italian_row->clickLink('Map to "hidden" (hide translation)');
    $this->assertSession()->pageTextContains('Are you sure you want to map this translation to "hidden"?');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The translation has been mapped to "hidden". It has not been deleted so you can always remove this mapping.');
    $this->assertCount(1, $active_revision_storage->loadMultiple());
    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertCount(2, $language_values);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => 0,
      'langcode' => 'it',
      'scope' => 0,
    ], $language_values[1]);
    $this->assertEquals('Mapped to "hidden" (translation hidden)', $italian_row->find('xpath', '//td[2]')->getText());
    $italian_operations = $italian_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => FALSE,
    ], $italian_operations);
    // Assert we can see not see the italian translation anymore.
    $this->drupalGet('/it/node/' . $node->id());
    $this->assertSession()->pageTextContains('My version 2 node');
    $this->assertSession()->pageTextContains('Non translatable updated version 2 value');

    // Remove the mapping for IT.
    $this->drupalGet('/node/' . $node->id() . '/translations');
    $italian_row->pressButton('List additional actions');
    $italian_row->clickLink('Remove mapping');
    $this->assertSession()->pageTextContains('Are you sure you want to remove this mapping?');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been removed for this language.');
    $active_revision_storage->resetCache();
    $active_revision = $active_revision_storage->load($active_revision->id());
    // We removed the mapping in IT but we still have in FR so the active
    // revision entity was not deleted.
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertCount(1, $language_values);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);

    // Make a new draft and publish it, leaving the mapping option by default
    // to map to previous version.
    $node_storage->resetCache();
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->load($node->id());
    $node->set('title', 'My version 3 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 3 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // This caused the FR translation to stay mapped to version 1, as it was
    // before, and IT mapped to version 2.
    $active_revision_storage->resetCache();
    $active_revision = $active_revision_storage->load($active_revision->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertCount(2, $language_values);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_two_revision_id,
      'langcode' => 'it',
      'scope' => 0,
    ], $language_values[1]);

    $this->drupalGet('/node/' . $node->id() . '/translations');
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $italian_operations = $italian_row->findAll('xpath', '//td[3]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      // Now we can also update the mapping because we have both version 1 and
      // version 2 to choose from.
      'Update mapping' => TRUE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);
    // For the new language created in the latest version, we can only hide it.
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      // For IT, we cannot update the mapping because version 1 doesn't have
      // a translation in IT and version 3 is the current version. And it's
      // already mapped to version 2.
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $italian_operations);

    // Assert that we can update the mapping of FR to pick between version 1
    // and 2.
    $french_row->pressButton('List additional actions');
    $french_row->clickLink('Update mapping');
    $this->assertSession()->pageTextContains('Updating mapping for My version 3 node');
    $this->assertEquals('required', $this->assertSession()->selectExists('Update mapping for French')->getAttribute('required'));
    $this->assertEquals([
      '' => '- Select -',
      $version_one_revision_id => '1.0.0',
      $version_two_revision_id => '2.0.0 (carried over)',
    ], $this->getSelectOptions('Update mapping for French'));
    $this->getSession()->getPage()->selectFieldOption('Update mapping for French', '2.0.0');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been updated.');
    $this->assertEquals('Mapped to version 2.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Mapped to version 2.0.0', $italian_row->find('xpath', '//td[2]')->getText());
  }

  /**
   * Tests that we can create/remove/update a mapping for multiple versions.
   *
   * Covers the case in which we have a validated major after a published
   * version.
   */
  public function testMappingOperationsForMultipleVersions(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\oe_translation_active_revision\ActiveRevisionStorage $active_revision_storage */
    $active_revision_storage = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision');

    // Create version 1 with a FR translation.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');

    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="fr"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My FR version 1 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been saved.');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');

    // Keep track of the version 1 revision ID.
    $version_one_revision_id = $node->getRevisionId();

    // Make a change and create version 2, mapping FR to version 1.
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Keep track of the revision ID of version 2.
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $version_two_revision_id = $node->getRevisionId();

    // Now make a new draft and validate it, creating version 3. This way we
    // have 2 parallel versions.
    $node->set('title', 'My version 3 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 3 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Validated');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    $this->drupalGet('/node/' . $node->id() . '/translations');
    $table = $this->getSession()->getPage()->find('css', 'table.existing-translations-table');
    $french_row = $table->find('xpath', '//tr[@hreflang="fr"]');
    // Both published and validated versions are mapped to 1.0.0.
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[4]')->getText());
    $french_published_operations = $french_row->findAll('xpath', '//td[3]//a');
    $french_validated_operations = $french_row->findAll('xpath', '//td[5]//a');
    $french_mapping_operations = $french_row->findAll('xpath', '//td[6]//a');
    $this->assertOperationLinks([
      'View' => TRUE,
    ], $french_published_operations);
    $this->assertOperationLinks([
      'View' => TRUE,
      // We can delete the validated translation even if we have a mapping.
      // But we don't have any other mapping operations here to avoid confusion.
      'Delete translation' => TRUE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => FALSE,
      'Update mapping' => FALSE,
      'Map to "hidden" (hide translation)' => FALSE,
    ], $french_validated_operations);
    $this->assertOperationLinks([
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      'Update mapping' => TRUE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_mapping_operations);

    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);

    // Update the mapping.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Update mapping');
    $this->assertSession()->pageTextContains('Updating mapping for My version 2 node');
    $this->assertSession()->pageTextContains('Please be aware that updating this mapping will apply to both the currently Published version and the new Validated major version.');
    $this->assertEquals('required', $this->assertSession()->selectExists('Update mapping for French')->getAttribute('required'));
    $this->assertEquals([
      '' => '- Select -',
      $version_one_revision_id => '1.0.0',
      $version_two_revision_id => '2.0.0 (carried over)',
    ], $this->getSelectOptions('Update mapping for French'));
    $this->getSession()->getPage()->selectFieldOption('Update mapping for French', '2.0.0 (carried over)');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been updated.');
    $active_revision_storage->resetCache();
    $active_revision = $active_revision_storage->load($active_revision->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_two_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);
    // Because the mapping is the version 2 and the published version IS version
    // 2, the label for the published version should be simply "Version 2.0.0".
    // But, because the version 2 translation was not synced but was carried
    // over, we display that it is in fact the translation from version 1,
    // carried over.
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    // For the validated version, we do display that we have a mapping to
    // whatever is being used on version 2.
    $this->assertEquals('Mapped to version 2.0.0', $french_row->find('xpath', '//td[4]')->getText());

    // Map to hidden.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Map to "hidden" (hide translation)');
    $this->assertSession()->pageTextContains('Are you sure you want to map this translation to "hidden"?');
    $this->assertSession()->pageTextContains('Please be aware that mapping to "hidden" will apply to both the currently Published version and the new Validated major version.');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The translation has been mapped to "hidden". It has not been deleted so you can always remove this mapping.');
    $this->assertEquals('Mapped to "hidden" (translation hidden)', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Mapped to "hidden" (translation hidden)', $french_row->find('xpath', '//td[4]')->getText());

    // Remove mapping.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Remove mapping');
    $this->assertSession()->pageTextContains('Are you sure you want to remove this mapping?');
    $this->assertSession()->pageTextContains('Please be aware that this will remove the mapping for both the currently Published version and the new Validated major version.');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been removed. There are no more language mappings for this entity.');
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[4]')->getText());

    // Add back a mapping.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Add mapping');
    $this->assertSession()->pageTextContains('Add a mapping for My version 2 node');
    $this->assertSession()->pageTextContains('Please be aware that creating this mapping will apply to both the currently Published version and the new Validated major version.');
    $this->assertEquals('required', $this->assertSession()->selectExists('Add mapping for French')->getAttribute('required'));
    $this->assertEquals([
      '' => '- Select -',
      $version_one_revision_id => '1.0.0',
      $version_two_revision_id => '2.0.0 (carried over)',
    ], $this->getSelectOptions('Add mapping for French'));
    $this->getSession()->getPage()->selectFieldOption('Add mapping for French', '1.0.0');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been added.');
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[4]')->getText());

    // Update the scope of the mapping to only apply to published version.
    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_one_revision_id,
      'langcode' => 'fr',
      'scope' => 0,
    ], $language_values[0]);

    $language_values[0]['scope'] = LanguageWithEntityRevisionItem::SCOPE_PUBLISHED;
    $active_revision->set('field_language_revision', $language_values);
    $active_revision->save();
    $this->getSession()->reload();
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[4]')->getText());

    // Update the mapping.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Update mapping');
    $this->assertSession()->pageTextContains('Updating mapping for My version 2 node');
    // The scope is only for published, so no more warning message.
    $this->assertSession()->pageTextNotContains('Please be aware that updating this mapping will apply to both the currently Published version and the new Validated major version.');
    $this->getSession()->getPage()->selectFieldOption('Update mapping for French', '2.0.0');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been updated.');
    $active_revision_storage->resetCache();
    $active_revision = $active_revision_storage->load($active_revision->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $version_two_revision_id,
      'langcode' => 'fr',
      'scope' => 1,
    ], $language_values[0]);
    // We mapped to version 2, but version 2 is in fact version 1 carried over.
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[4]')->getText());

    // Map to hidden.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Map to "hidden" (hide translation)');
    $this->assertSession()->pageTextContains('Are you sure you want to map this translation to "hidden"?');
    $this->assertSession()->pageTextNotContains('Please be aware that mapping to "hidden" will apply to both the currently Published version and the new Validated major version.');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The translation has been mapped to "hidden". It has not been deleted so you can always remove this mapping.');
    $this->assertEquals('Mapped to "hidden" (translation hidden)', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[4]')->getText());

    // Remove the mapping.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Remove mapping');
    $this->assertSession()->pageTextContains('Are you sure you want to remove this mapping?');
    $this->assertSession()->pageTextNotContains('Please be aware that this will remove the mapping for both the currently Published version and the new Validated major version.');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been removed. There are no more language mappings for this entity.');
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[4]')->getText());
  }

  /**
   * Tests the mapping creation when we have multiple major versions.
   *
   * Covers the case in which the validated version gets a new translation so
   * that any new mapping that gets created, needs to be with the scope
   * applying only to the published version.
   */
  public function testMappingCreateWithMultipleVersions(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\oe_translation_active_revision\ActiveRevisionStorage $active_revision_storage */
    $active_revision_storage = \Drupal::entityTypeManager()->getStorage('oe_translation_active_revision');

    // Create version 1 with a FR translation.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();

    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'published');

    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="fr"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My FR version 1 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been saved.');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');

    // Make a change and create version 2, mapping FR to version 1.
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Now make a new draft and validate it, creating version 3. This way we
    // have 2 parallel versions.
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $node->set('title', 'My version 3 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 3 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Validated');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');

    // Translate the Validated version.
    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="fr"] td[data-version="3.0.0"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My FR version 3 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been saved.');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');

    // Mark the active revision scope as only for published.
    // @todo remove this step once the sync process does it automatically.
    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $language_values[0]['scope'] = LanguageWithEntityRevisionItem::SCOPE_PUBLISHED;
    $active_revision->set('field_language_revision', $language_values);
    $active_revision->save();

    // Assert that the published version shows as mapped and the validated one
    // shows its version.
    $this->drupalGet('/node/' . $node->id() . '/translations');
    $table = $this->getSession()->getPage()->find('css', 'table.existing-translations-table');
    $french_row = $table->find('xpath', '//tr[@hreflang="fr"]');
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 3.0.0', $french_row->find('xpath', '//td[4]')->getText());

    // Remove the mapping.
    $active_revision->delete();
    $this->getSession()->reload();
    $this->assertEquals('Version 1.0.0 (carried over to the current version)', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 3.0.0', $french_row->find('xpath', '//td[4]')->getText());

    // Add back a mapping.
    $french_row->find('xpath', '//td[6]')->pressButton('List additional actions');
    $this->clickLink('Add mapping');
    $this->assertSession()->pageTextContains('Add a mapping for My version 2 node');
    // We don't see the warning message because the mapping would apply only
    // to the published version.
    $this->assertSession()->pageTextNotContains('Please be aware that creating this mapping will apply to both the currently Published version and the new Validated major version.');
    $this->assertEquals('required', $this->assertSession()->selectExists('Add mapping for French')->getAttribute('required'));
    $this->getSession()->getPage()->selectFieldOption('Add mapping for French', '1.0.0');
    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertSession()->addressEquals('/node/' . $node->id() . '/translations');
    $this->assertSession()->pageTextContains('The mapping has been added.');
    // The published version shows the mapping but the validated one has its
    // own translation and the scope applies only to the published.
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertEquals('Version 3.0.0', $french_row->find('xpath', '//td[4]')->getText());
    $active_revision = $active_revision_storage->getActiveRevisionForEntity('node', $node->id());
    $language_values = $active_revision->get('field_language_revision')->getValue();
    $this->assertEquals(LanguageWithEntityRevisionItem::SCOPE_PUBLISHED, $language_values[0]['scope']);
  }

  /**
   * Tests that users can map only to specific versions (revisions).
   *
   * This tests that users can map to both published and validated major
   * versions from the past. If the validated major has a published
   * revision, only that should be available, otherwise the validated one.
   * Moreover, the latest revision should not be mappable as it makes no sense.
   */
  public function testLimitingVersionsToMap(): void {
    /** @var \Drupal\node\NodeStorageInterface $node_storage */
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Create version 1 with a FR translation (validated only).
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create([
      'type' => 'page',
      'title' => 'My version 1 node',
      'field_non_translatable_field' => 'Non translatable value',
      'moderation_state' => 'draft',
    ]);
    $node->save();
    $node = $node_storage->load($node->id());
    $node = $this->moderateNode($node, 'validated');
    // Add a translation.
    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="fr"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My FR version 1 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');

    $expected_version_one_revision_id = $node->getRevisionId();

    // Start a new draft from this version without publishing (again just
    // validated).
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $node->set('title', 'My version 2 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 2 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl());
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Validated');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $expected_version_two_revision_id = $node->getRevisionId();

    // Start a new draft from this version this time publishing.
    $node->set('title', 'My version 3 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 3 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl());
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');
    $node_storage->resetCache();
    $node = $node_storage->load($node->id());
    $expected_version_three_revision_id = $node->getRevisionId();

    // Add a translation.
    $this->drupalGet('/node/' . $node->id() . '/translations/local');
    $this->getSession()->getPage()->find('css', 'table tbody tr[hreflang="fr"] a')->click();
    $this->getSession()->getPage()->fillField('title|0|value[translation]', 'My FR version 3 node');
    $this->getSession()->getPage()->pressButton('Save and synchronise');
    $this->assertSession()->pageTextContains('The translation has been synchronised.');

    // Start a new draft from this version without publishing (just
    // validated).
    $node->set('title', 'My version 4 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 4 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Validated');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');
    $node_storage->resetCache();
    $node = $node_storage->loadRevision($node_storage->getLatestRevisionId($node->id()));
    $expected_version_four_revision_id = $node->getRevisionId();

    // Start a new draft from this version this time again publishing.
    $node->set('title', 'My version 5 node');
    $node->set('field_non_translatable_field', 'Non translatable updated version 5 value');
    $node->set('moderation_state', 'draft');
    $node->setNewRevision();
    $node->save();
    $this->drupalGet($node->toUrl('latest-version'));
    $this->getSession()->getPage()->selectFieldOption('Change to', 'Published');
    $this->getSession()->getPage()->pressButton('Apply');
    $this->waitForBatchExecution();
    $this->assertSession()->waitForText('The moderation state has been updated.');
    $node_storage->resetCache();
    $expected_version_five_revision_id = $node_storage->load($node->id())->getRevisionId();

    $this->drupalGet('/node/' . $node->id() . '/translations');
    $table = $this->getSession()->getPage()->find('css', 'table.existing-translations-table');
    $french_row = $table->find('xpath', '//tr[@hreflang="fr"]');
    $french_operations = $french_row->findAll('xpath', '//td[3]//a');
    $this->assertEquals('Mapped to version 1.0.0', $french_row->find('xpath', '//td[2]')->getText());
    $this->assertOperationLinks([
      'View' => TRUE,
      'Delete translation' => FALSE,
      'Add mapping' => FALSE,
      'Map to version' => FALSE,
      'Remove mapping' => TRUE,
      'Update mapping' => TRUE,
      'Map to "hidden" (hide translation)' => TRUE,
    ], $french_operations);
    $this->getSession()->getPage()->pressButton('List additional actions');
    $this->clickLink('Update mapping');

    // Assert that the user can pick only the correct revisions.
    $this->assertEquals('validated', $node_storage->loadRevision($expected_version_one_revision_id)->get('moderation_state')->value);
    $this->assertEquals('validated', $node_storage->loadRevision($expected_version_two_revision_id)->get('moderation_state')->value);
    $this->assertEquals('published', $node_storage->loadRevision($expected_version_three_revision_id)->get('moderation_state')->value);
    $this->assertEquals('validated', $node_storage->loadRevision($expected_version_four_revision_id)->get('moderation_state')->value);
    $this->assertEquals('published', $node_storage->loadRevision($expected_version_five_revision_id)->get('moderation_state')->value);
    $this->assertEquals([
      '' => '- Select -',
      // There is only one single revision to pick for each version, even
      // though, every time we published, we also created a validated revision
      // and those are not here.
      $expected_version_one_revision_id => '1.0.0',
      $expected_version_two_revision_id => '2.0.0 (carried over)',
      $expected_version_three_revision_id => '3.0.0',
      $expected_version_four_revision_id => '4.0.0 (carried over)',
      // Version 5 is not here because it's the latest one.
    ], $this->getSelectOptions('Update mapping for French'));
  }

  /**
   * Asserts the operations links.
   *
   * @param array $expected
   *   The expected links titles.
   * @param \Behat\Mink\Element\NodeElement[] $actual
   *   The actual links titles.
   */
  protected function assertOperationLinks(array $expected, array $actual): void {
    $operations = [];
    foreach ($actual as $item) {
      $operations[] = $item->getHtml();
    }

    $expected = array_keys(array_filter($expected));
    $this->assertEquals($expected, $operations);
  }

  /**
   * Returns the select options of a given select field.
   *
   * @param string $select
   *   The select field title.
   *
   * @return array
   *   The options, keyed by value.
   */
  protected function getSelectOptions(string $select): array {
    $select = $this->assertSession()->selectExists($select);
    $options = [];
    foreach ($select->findAll('xpath', '//option') as $element) {
      $options[$element->getValue()] = trim($element->getText());
    }

    return $options;
  }

}
