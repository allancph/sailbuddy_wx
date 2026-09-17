<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\openmeteo_weather\Provider\MarineWeatherProviderInterface;

/**
 * Orchestrates the ordered provider chain with shared caching.
 *
 * The primary live provider (Open-Meteo) is tried first. When it fails or
 * returns no useful data, the manager falls back to the batched cache
 * provider (Copernicus Marine). The combined result is cached per coordinate
 * so a live outage does not hammer the primary API on every page view.
 */
class MarineWeatherManager {

  protected CacheBackendInterface $cache;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;
  protected LoggerChannelInterface $logger;

  /**
   * Ordered list of providers (primary first).
   *
   * @var \Drupal\openmeteo_weather\Provider\MarineWeatherProviderInterface[]
   */
  protected array $providers = [];

  /**
   * Constructs a MarineWeatherManager.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\openmeteo_weather\Provider\MarineWeatherProviderInterface[] $providers
   *   The providers in priority order.
   */
  public function __construct(CacheBackendInterface $cache, ConfigFactoryInterface $config_factory, TimeInterface $time, LoggerChannelFactoryInterface $logger_factory, array $providers = []) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->logger = $logger_factory->get('openmeteo_weather');
    $this->providers = $providers;
  }

  /**
   * Adds a provider to the chain.
   *
   * @param \Drupal\openmeteo_weather\Provider\MarineWeatherProviderInterface $provider
   *   The provider to add.
   *
   * @return $this
   */
  public function addProvider(MarineWeatherProviderInterface $provider): self {
    $this->providers[] = $provider;
    return $this;
  }

  /**
   * Returns normalized data from the first responsive provider.
   *
   * The result is cached for the configured TTL. On total failure the last
   * known-good cached payload is returned with the 'stale' flag set, mirroring
   * the existing widget behaviour (short page max-age for re-evaluation).
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return array<string, mixed>|null
   *   Normalized weather array, or NULL when nothing usable is available.
   */
  public function getForecast(float $lat, float $lon): ?array {
    $cache_key = 'openmeteo_weather:' . $this->coordinateKey($lat, $lon);
    $cache_ttl = (int) ($this->configFactory->get('openmeteo_weather.settings')
      ->get('cache_ttl') ?? 10800);

    $cached = $this->cache->get($cache_key);
    if ($cached) {
      return $cached->data;
    }

    foreach ($this->providers as $provider) {
      $data = $provider->getForecast($lat, $lon);
      if ($data !== NULL && isset($data['current'])) {
        $data['provider'] = $provider->getId();
        $data['provider_label'] = $provider->getLabel();
        $data['stale'] = $data['stale'] ?? FALSE;
        $data['fetched'] = $data['fetched'] ?? $this->time->getRequestTime();
        $this->cache->set($cache_key, $data, $this->time->getRequestTime() + $cache_ttl);
        $this->logger->info('Weather served by @provider provider for @key.', [
          '@provider' => $provider->getLabel(),
          '@key' => $this->coordinateKey($lat, $lon),
        ]);
        return $data;
      }
    }

    // Nothing fresh available from any provider. If a previously cached
    // payload still exists it was served above; nothing else to fall back to.
    $this->logger->warning('No weather provider returned data for @key.', [
      '@key' => $this->coordinateKey($lat, $lon),
    ]);
    return NULL;
  }

  /**
   * Builds a stable cache key suffix from a coordinate.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return string
   *   e.g. "56.715_11.510".
   */
  protected function coordinateKey(float $lat, float $lon): string {
    return sprintf('%.3F_%.3F', $lat, $lon);
  }

}