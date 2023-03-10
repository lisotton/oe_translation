<?php

declare(strict_types=1);

namespace Drupal\oe_translation;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\link\Plugin\Field\FieldType\LinkItem;

class LinkItemMultiple extends LinkItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);

    if ((int) $field_definition->getCardinality() === 1) {
      return $properties;
    }

    $properties['translation_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Translation ID'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = parent::schema($field_definition);

    if ((int) $field_definition->getCardinality() === 1) {
      return $schema;
    }

    $schema['columns']['translation_id'] = [
      'description' => 'The translation ID.',
      'type' => 'int',
      'unsigned' => TRUE,
      'not null' => FALSE,
    ];

    return $schema;
  }
}
