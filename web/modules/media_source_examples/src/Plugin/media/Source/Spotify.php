<?php

namespace Drupal\media_source_examples\Plugin\media\Source;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\OEmbed\Resource;
use Drupal\media\Plugin\media\Source\OEmbed;
use GuzzleHttp\Exception\RequestException;

/**
 * @MediaSource(
 *   id = "spotify_oembed",
 *   label = @Translation("Spotify"),
 *   description = @Translation("Embed spotify content."),
 *   providers = {"Spotify"},
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "no-thumbnail.png"
 * )
 */
class Spotify extends OEmbed {
  // No need for anything in here; the base plugin can take care of typical
  // interactions with external oEmbed services.

  // \Drupal\media\Plugin\media\Source\OEmbed::getLocalThumbnailUri doesn't
  // handle images that do not have an extension. So we override it an force a
  // .jpg extension for file names. Without the extension Drupal's image
  // handling breaks.
  //
  // See https://www.drupal.org/project/drupal/issues/3080666
  protected function getLocalThumbnailUri(Resource $resource) {
    // If there is no remote thumbnail, there's nothing for us to fetch here.
    $remote_thumbnail_url = $resource->getThumbnailUrl();
    if (!$remote_thumbnail_url) {
      return NULL;
    }
    $remote_thumbnail_url = $remote_thumbnail_url->toString();

    // Compute the local thumbnail URI, regardless of whether or not it exists.
    $configuration = $this->getConfiguration();
    $directory = $configuration['thumbnails_directory'];
    $local_thumbnail_uri = "$directory/" . Crypt::hashBase64($remote_thumbnail_url) . '.' . pathinfo($remote_thumbnail_url, PATHINFO_EXTENSION);

    $local_thumbnail_uri .= '.jpg';

    // If the local thumbnail already exists, return its URI.
    if (file_exists($local_thumbnail_uri)) {
      return $local_thumbnail_uri;
    }

    // The local thumbnail doesn't exist yet, so try to download it. First,
    // ensure that the destination directory is writable, and if it's not,
    // log an error and bail out.
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->warning('Could not prepare thumbnail destination directory @dir for oEmbed media.', [
        '@dir' => $directory,
      ]);
      return NULL;
    }

    try {
      $response = $this->httpClient->get($remote_thumbnail_url);
      if ($response->getStatusCode() === 200) {
        $this->fileSystem->saveData((string) $response->getBody(), $local_thumbnail_uri, FileSystemInterface::EXISTS_REPLACE);
        return $local_thumbnail_uri;
      }
    }
    catch (RequestException $e) {
      $this->logger->warning($e->getMessage());
    }
    catch (FileException $e) {
      $this->logger->warning('Could not download remote thumbnail from {url}.', [
        'url' => $remote_thumbnail_url,
      ]);
    }
    return NULL;
  }

}
