<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Provider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Fallback provider reading pre-fetched Copernicus Marine cache files.
 *
 * Files are written by the external batch job (scripts/cmems_fetch.py) into
 * a configured directory (default: private://cmems) as JSON, one file per
 * rounded coordinate. This provider never performs network calls itself; it
 * only reports cached results as long as the batch data is fresh enough.
 */
class CopernicusMarineProvider implements MarineWeatherProviderInterface {

  protected ConfigFactoryInterface $configFactory;
  protected FileSystemInterface $fileSystem;
  protected LoggerChannelInterface $logger;
  protected bool $available = TRUE;

  /**
   * Constructs a CopernicusMarineProvider.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('openmeteo_weather');
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'copernicus_marine';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return $this->configFactory->get('openmeteo_weather.settings')
      ->get('provider.copernicus_label') ?? 'Copernicus Marine Service';
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->available;
  }

  /**
   * {@inheritdoc}
   */
  public function getForecast(float $lat, float $lon): ?array {
    $directory = $this->configFactory->get('openmeteo_weather.settings')
      ->get('provider.cmems_path') ?? 'private://cmems';
    $path = $this->fileSystem->realpath($directory);
    if ($path === FALSE) {
      return NULL;
    }

    $filename = $this->cacheKey($lat, $lon);
    $file = $path . DIRECTORY_SEPARATOR . $filename . '.json';
    if (!is_file($file)) {
      return NULL;
    }

    $ttl = (int) ($this->configFactory->get('openmeteo_weather.settings')
      ->get('provider.cmems_ttl') ?? 21600);
    $max_age = (int) ($this->configFactory->get('openmeteo_weather.settings')
      ->get('provider.cmems_max_age') ?? 86400);

    $content = @file_get_contents($file);
    if ($content === FALSE) {
      return NULL;
    }

    $data = json_decode($content, TRUE);
    if (!is_array($data) || !isset($data['fetched'])) {
      $this->available = FALSE;
      return NULL;
    }

    $age = max(0, time() - (int) $data['fetched']);
    if ($age > $max_age) {
      $this->logger->notice('Copernicus Marine data for @key is too old (@age s).', [
        '@key' => $filename,
        '@age' => $age,
      ]);
      return NULL;
    }

    $data['provider'] = $this->getId();
    $data['provider_label'] = $this->getLabel();
    $data['stale'] = $age > $ttl;

    return $data;
  }

  /**
   * Builds the cache key for a coordinate, matching the batch job naming.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return string
   *   e.g. "56.715_11.510".
   */
  protected function cacheKey(float $lat, float $lon): string {
    return sprintf('%.3F_%.3F', $lat, $lon);
  }

}