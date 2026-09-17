<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Provider;

/**
 * Contract for weather + marine forecast data sources.
 *
 * Implementations provide live forecasts (Open-Meteo REST) or read
 * pre-fetched batched data (Copernicus Marine cache files).
 */
interface MarineWeatherProviderInterface {

  /**
   * Machine name of the provider.
   *
   * @return string
   *   Provider id, e.g. "openmeteo".
   */
  public function getId(): string;

  /**
   * Human readable label shown to the user in the widget footer.
   *
   * @return string
   *   Provider label, e.g. "Open-Meteo (DWD/ECMWF)".
   */
  public function getLabel(): string;

  /**
   * Determines whether the provider produced usable data at least once.
   *
   * @return bool
   *   TRUE if the provider is functional, FALSE otherwise.
   */
  public function isAvailable(): bool;

  /**
   * Returns normalized weather + marine data for a coordinate.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return array|null
   *   Normalized data array with 'current', 'forecast' and 'fetched' keys,
   *   or NULL when no usable data can be returned.
   */
  public function getForecast(float $lat, float $lon): ?array;

}