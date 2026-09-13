<?php

namespace Drupal\openmeteo_weather\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Fetches and normalizes weather + marine forecast data from Open-Meteo.
 */
class OpenMeteoClient {

  protected const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
  protected const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';
  protected const CACHE_TTL = 10800; // 3 hours.

  protected ClientInterface $httpClient;
  protected CacheBackendInterface $cache;
  protected $logger;

  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('openmeteo_weather');
  }

  /**
   * Returns normalized weather + marine data for a coordinate.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return array|null
   *   Normalized data array, or NULL on failure.
   */
  public function getForecast(float $lat, float $lon): ?array {
    $cache_key = 'openmeteo_weather:' . round($lat, 3) . ':' . round($lon, 3);
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $weather = $this->fetch(self::FORECAST_URL, [
      'latitude' => $lat,
      'longitude' => $lon,
      'current' => 'temperature_2m,wind_speed_10m,wind_direction_10m,precipitation,weather_code',
      'daily' => 'temperature_2m_max,temperature_2m_min,weather_code,wind_speed_10m_max,wind_direction_10m_dominant',
      'timezone' => 'auto',
      'forecast_days' => 5,
      'wind_speed_unit' => 'kmh',
    ]);

    $marine = $this->fetch(self::MARINE_URL, [
      'latitude' => $lat,
      'longitude' => $lon,
      'current' => 'wave_height,wave_period,wave_direction,sea_surface_temperature,wave_peak_period',
      'daily' => 'wave_height_max,wave_period_max,wave_direction_dominant',
      'timezone' => 'auto',
      'forecast_days' => 5,
    ]);

    if ($weather === NULL) {
      // Base weather is required; marine alone is not useful enough.
      return NULL;
    }

    $normalized = $this->normalize($weather, $marine);

    $this->cache->set($cache_key, $normalized, time() + self::CACHE_TTL);

    return $normalized;
  }

  /**
   * Performs the actual HTTP call, returns decoded JSON or NULL on failure.
   *
   * @param string $url
   *   The endpoint URL.
   * @param array $query
   *   Query parameters.
   *
   * @return array|null
   *   Decoded JSON body, or NULL on failure.
   */
  protected function fetch(string $url, array $query): ?array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => $query,
        'timeout' => 8,
        'headers' => [
          'User-Agent' => 'sailbuddy-openmeteo/1.0 (Drupal; +https://dev.sailbuddy.com)',
        ],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data) || !empty($data['error'])) {
        $this->logger->warning('Open-Meteo returned an error for @url: @reason', [
          '@url' => $url,
          '@reason' => $data['reason'] ?? 'invalid payload',
        ]);
        return NULL;
      }
      return $data;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open-Meteo request failed for @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Combines weather + marine responses into a single flat structure.
   *
   * @param array $weather
   *   Decoded response from the forecast endpoint.
   * @param array|null $marine
   *   Decoded response from the marine endpoint, or NULL if unavailable.
   *
   * @return array
   *   Normalized structure with 'current', 'forecast', and 'fetched' keys.
   */
  protected function normalize(array $weather, ?array $marine): array {
    $current = $weather['current'] ?? [];
    $daily = $weather['daily'] ?? [];
    $marine_current = $marine['current'] ?? [];
    $marine_daily = $marine['daily'] ?? [];

    $forecast = [];
    $days = $daily['time'] ?? [];
    foreach ($days as $i => $date) {
      $forecast[] = [
        'date' => $date,
        'temp_max' => $daily['temperature_2m_max'][$i] ?? NULL,
        'temp_min' => $daily['temperature_2m_min'][$i] ?? NULL,
        'weather_code' => $daily['weather_code'][$i] ?? NULL,
        'wind_max' => $daily['wind_speed_10m_max'][$i] ?? NULL,
        'wind_dir' => $daily['wind_direction_10m_dominant'][$i] ?? NULL,
        'wave_max' => $marine_daily['wave_height_max'][$i] ?? NULL,
        'wave_period_max' => $marine_daily['wave_period_max'][$i] ?? NULL,
        'wave_dir' => $marine_daily['wave_direction_dominant'][$i] ?? NULL,
      ];
    }

    return [
      'current' => [
        'temperature' => $current['temperature_2m'] ?? NULL,
        'wind_speed' => $current['wind_speed_10m'] ?? NULL,
        'wind_direction' => $current['wind_direction_10m'] ?? NULL,
        'precipitation' => $current['precipitation'] ?? NULL,
        'weather_code' => $current['weather_code'] ?? NULL,
        'wave_height' => $marine_current['wave_height'] ?? NULL,
        'wave_period' => $marine_current['wave_period'] ?? NULL,
        'wave_peak_period' => $marine_current['wave_peak_period'] ?? NULL,
        'wave_direction' => $marine_current['wave_direction'] ?? NULL,
        'sea_temp' => $marine_current['sea_surface_temperature'] ?? NULL,
      ],
      'forecast' => $forecast,
      'fetched' => time(),
    ];
  }

}