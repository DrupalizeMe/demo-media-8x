<?php

namespace Drupal\media_source_examples\Plugin\media\Source;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaTypeInterface;
use Drupal\media_source_examples\SongwhipFetcher;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * You can find possible values to use in the providers object in the list
 * here https://oembed.com/providers.json.
 *
 * @MediaSource(
 *   id = "songwhip",
 *   label = @Translation("Songwhip"),
 *   description = @Translation("Embed Songwhip content."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "no-thumbnail.png",
 *   forms = {
 *     "media_library_add" = "\Drupal\media_source_examples\Form\SongwhipMediaLibraryAddForm",
 *   }
 * )
 */
class Songwhip extends MediaSourceBase {

  /**
   * Key for "Name" metadata attribute.
   *
   * @var string
   */
  const METADATA_ATTRIBUTE_NAME = 'name';

  /**
   * Key for "URL" metadata attribute.
   *
   * @var string
   */
  const METADATA_ATTRIBUTE_URL = 'url';

  /**
   * Key for "Image" metadata attribute.
   *
   * @var string
   */
  const METADATA_ATTRIBUTE_IMAGE = 'image';

  /**
   * Key for "releaseDate" metadata attribute.
   *
   * @var string
   */
  const METADATA_ATTRIBUTE_RELEASE_DATE = 'releaseDate';

  /**
   * Key for "type" metadata attribute.
   *
   * Example values, 'artist', 'album', etc.
   *
   * @var string
   */
  const METADATA_ATTRIBUTE_TYPE = 'type';

  /**
   * API client for Songwhip service.
   *
   * @var \Drupal\media_source_examples\SongwhipFetcher
   */
  protected $songwhipFetcher;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The logger channel for media.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, FieldTypePluginManagerInterface $field_type_manager, ConfigFactoryInterface $config_factory, SongwhipFetcher $songwhip_fetcher, ClientInterface $http_client, FileSystemInterface $file_system, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_field_manager, $field_type_manager, $config_factory);

    $this->songwhipFetcher = $songwhip_fetcher;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('config.factory'),
      $container->get('media_source_examples.songwhip_fetcher'),
      $container->get('http_client'),
      $container->get('file_system'),
      $container->get('logger.factory')->get('media')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      static::METADATA_ATTRIBUTE_NAME => $this->t('Name'),
      static::METADATA_ATTRIBUTE_RELEASE_DATE => $this->t('Release date'),
      static::METADATA_ATTRIBUTE_TYPE => $this->t('Media type'),
      static::METADATA_ATTRIBUTE_IMAGE => $this->t('Image'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    $media_url = $this->getSourceFieldValue($media);
    // The URL may be NULL if the source field is empty, in which case just
    // return NULL.
    if (empty($media_url)) {
      return NULL;
    }

    $data = $this->songwhipFetcher->fetch($media_url);
    if (!$data) {
      return NULL;
    }

    switch ($attribute_name) {
      case 'default_name':
      case static::METADATA_ATTRIBUTE_NAME:
        return $data->{static::METADATA_ATTRIBUTE_NAME};

      case static::METADATA_ATTRIBUTE_URL:
        return $data->{static::METADATA_ATTRIBUTE_URL};

      case 'thumbnail_uri':
        return $this->getLocalThumbnailUri($data);

      case static::METADATA_ATTRIBUTE_IMAGE:
        return $data->{static::METADATA_ATTRIBUTE_IMAGE};

      case static::METADATA_ATTRIBUTE_RELEASE_DATE:
        return $data->{static::METADATA_ATTRIBUTE_RELEASE_DATE};

      case static::METADATA_ATTRIBUTE_TYPE:
        return $data->{static::METADATA_ATTRIBUTE_TYPE};

      default:
        return parent::getMetadata($media, $attribute_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['generate_thumbnails'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate thumbnails'),
      '#default_value' => $this->configuration['generate_thumbnails'],
      '#description' => $this->t('If checked, Drupal will automatically generate thumbnails from Songwhip provided images.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // @todo: Include custom validation logic for the form fields added above.
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'thumbnails_directory' => 'public://songwhip_thumbnails',
        'generate_thumbnails' => TRUE,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function prepareViewDisplay(MediaTypeInterface $type, EntityViewDisplayInterface $display) {
    $display->setComponent($this->getSourceFieldDefinition($type)->getName(), [
      'type' => 'media_source_examples_songwhip_embed',
      'label' => 'visually_hidden',
    ]);
  }

  /**
   * Retrieve a thumbnail for a Songwhip resource.
   *
   * Uses the 'image' referenced in the Songwhip response and copies it to the
   * local filesystem if possible. This is a fairly basic implementation for
   * demonstration purposes and could be expanded to include resizing etc.
   *
   * @param object $data
   *   Data returned from Songwhip API.
   *
   * @return string|null
   *   Either the URL of a local thumbnail, or NULL.
   */
  protected function getLocalThumbnailUri($data) {
    // If there is no remote thumbnail, there's nothing for us to fetch here.
    $remote_thumbnail_url = $data->{self::METADATA_ATTRIBUTE_IMAGE};
    if (!$remote_thumbnail_url) {
      return NULL;
    }

    // Compute the local thumbnail URI, regardless of whether it exists.
    $directory = $this->configuration['thumbnails_directory'];
    $local_thumbnail_uri = "$directory/" . Crypt::hashBase64($remote_thumbnail_url) . '.' . pathinfo($remote_thumbnail_url, PATHINFO_EXTENSION);
    // This assumes they are all JPEG. Is that safe?
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
