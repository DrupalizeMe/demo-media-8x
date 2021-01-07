<?php

namespace Drupal\media_source_examples\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\media\Entity\MediaType;
use Drupal\media_source_examples\Plugin\media\Source\Songwhip;

/**
 * Plugin implementation of the 'Songwhip embed' formatter.
 *
 * @FieldFormatter(
 *   id = "media_source_examples_songwhip_embed",
 *   label = @Translation("Songwhip embed"),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class SongwhipEmbedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];

    $media = $items->getEntity();
    $songwhip = $media->getSource();
    foreach ($items as $delta => $item) {
      $url = $songwhip->getMetadata($media, Songwhip::METADATA_ATTRIBUTE_URL);
      if ($url) {
        $element[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'iframe',
          '#attributes' => [
            'src' => $url,
            'frameborder' => 0,
            'allowtransparency' => TRUE,
            // @todo width and height should be configurable.
            'height' => '600px',
            'width' => '400px',
            'class' => ['media-oembed-content'],
          ],
        ];
      }
      else {
        $element[$delta] = [
          '#markup' => $item->value,
        ];
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    if ($field_definition->getTargetEntityTypeId() !== 'media') {
      return FALSE;
    }

    if (parent::isApplicable($field_definition)) {
      $media_type = $field_definition->getTargetBundle();

      if ($media_type) {
        $media_type = MediaType::load($media_type);
        return $media_type && $media_type->getSource() instanceof Songwhip;
      }
    }
    return FALSE;
  }
}
