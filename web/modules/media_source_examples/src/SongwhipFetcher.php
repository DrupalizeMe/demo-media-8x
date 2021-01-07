<?php

namespace Drupal\media_source_examples;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Songwhip\Client;

/**
 * Fetch, and cache, data from the Songwhip API.
 */
class SongwhipFetcher {

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Songwhip API client.
   *
   * @var \Songwhip\Client
   */
  protected $songwhipClient;

  /**
   * Constructs a SongwhipClient object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   A cache bin for storing fetched API responses.
   */
  public function __construct(CacheBackendInterface $cache, Client $songwhip_client) {
    $this->cache = $cache;
    $this->songwhipClient = $songwhip_client;
  }

  /**
   * Retrieve sonwgwhip response for a streaming service URL.
   *
   * @param string $url
   *   Streaming service URL to look up via Songwhip.
   *
   * @return false|object
   *   A generic object containing the decoded JSON response, or false.
   *
   * @throws \Exception
   */
  public function fetch($url) {
    if ($this->cache && $cached_response = $this->cache->get($url)) {
      return $cached_response->data;
    }

    // Query Songwhip's API.
    $response = $this->songwhipClient->getFromServiceUrl($url);

    if (empty($response)) {
      throw new \Exception("Could not retrieve response for $url.");
    }

    // Cache the response for future use.
    if ($this->cache) {
      // Data doesn't change often, but it could, so the response should expire
      // from the cache on its own in 7 days.
      $this->cache->set($url, $response, time() + (86400 * 7));
    }

    return $response;
  }

}
