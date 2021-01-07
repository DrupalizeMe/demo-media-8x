<?php

namespace Drupal\media_source_examples\Plugin\media\Source;

use Drupal\media\Plugin\media\Source\OEmbed;

/**
 * You can find possible values to use in the providers object in the list
 * here https://oembed.com/providers.json.
 *
 * @MediaSource(
 *   id = "codepen_oembed",
 *   label = @Translation("CodePen"),
 *   description = @Translation("Embed CodePen content."),
 *   providers = {"Codepen"},
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "no-thumbnail.png"
 * )
 */
class CodePen extends OEmbed {
  // No need for anything in here; the base plugin can take care of typical
  // interactions with external oEmbed services. However, you can override any
  // of the parent classes methods to customize things as needed.
}
