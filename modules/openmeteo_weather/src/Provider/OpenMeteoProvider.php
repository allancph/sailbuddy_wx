<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Provider;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Live weather + marine forecast provider backed by the Open-Meteo REST API.
 */
class OpenMeteoProvider implements MarineWeatherProviderInterface {

  protected const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
  protected const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';

  protected ClientInterface $httpClient;
  protected LoggerChannelInterface $logger;
  protected bool $available = TRUE;

  /**
   * Constructs an OpenMeteoProvider.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('openmeteo_weather');
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'openmeteo';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Open-Meteo (DWD/ECMWF)';
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
    $weather = $this->fetch(self::FORECAST_URL, [
      'latitude' => $lat,
      'longitude' => $lon,
      'current' => 'temperature_2m,wind_speed_10m,wind_direction_10m,precipitation,weather_code',
      'daily' => 'temperature_2m_max,temperature_2m_min,weather_code,wind_speed_10m_max,wind_direction_10m_dominant',
      'timezone' => 'auto',
      'forecast_days' => 5,
      'wind_speed_unit' => 'ms',
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
      $this->available = FALSE;
      return NULL;
    }

    $data = $this->normalize($weather, $marine);
    $data['fetched'] = time();
    return $data;
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
   *   Normalized structure with 'current' and 'forecast' keys.
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
    ];
  }

}