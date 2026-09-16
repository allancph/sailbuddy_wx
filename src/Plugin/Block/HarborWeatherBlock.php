<?php

namespace Drupal\openmeteo_weather\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\openmeteo_weather\Service\OpenMeteoClient;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a harbor weather + marine forecast block.
 *
 * @Block(
 *   id = "harbor_weather_block",
 *   admin_label = @Translation("Harbor Weather (Open-Meteo)"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"), required = FALSE)
 *   }
 * )
 */
class HarborWeatherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected OpenMeteoClient $client;
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('openmeteo_weather.client');
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');

    // Only render for the content types this widget was built for. This must
    // NOT return an empty array: an empty block view gets cached in
    // cache_render WITHOUT the route cache context, and that route-less entry
    // is then served for EVERY subsequent node route (build() is skipped, so
    // the widget disappears on all harbours). Always return real markup with a
    // route-scoped cache context instead.
    if (!$node instanceof EntityInterface || !in_array($node->bundle(), ['harbour', 'anchorage'], TRUE)) {
      return $this->unavailable();
    }

    $coords = $this->getCoordinates($node);
    if ($coords === NULL) {
      // No usable coordinate: return fallback markup so the block always
      // renders (an empty block view is dropped by BlockViewBuilder).
      return $this->unavailable();
    }

    $data = $this->client->getForecast($coords[0], $coords[1]);
    if ($data === NULL) {
      // Open-Meteo is unreachable (DNS/API outage/rate-limit). Return real
      // markup with a short max-age so the page is NOT cached permanently
      // without the widget, but re-evaluated after a few minutes.
      return $this->unavailable();
    }

    return [
      '#theme' => 'openmeteo_weather_widget',
      '#current' => $data['current'],
      '#forecast' => $data['forecast'],
      '#attached' => ['library' => ['openmeteo_weather/widget']],
      '#cache' => [
        'contexts' => ['route', 'languages:language_interface'],
        'tags' => ['node:' . $node->id()],
        'max-age' => 10800,
      ],
    ];
  }

  /**
   * Fallback render array shown when the weather service cannot be reached.
   *
   * Returns real markup (not an empty array) so the block wrapper always
   * renders, and uses a short max-age so the page cache re-evaluates the
   * block quickly instead of storing the page without a widget.
   *
   * @return array
   *   Render array for the fallback message.
   */
  protected function unavailable(): array {
    return [
      '#type' => 'markup',
      '#markup' => '<div class="openmeteo-weather-unavailable">' .
        $this->t('The weather service is temporarily unavailable. Please try again later.') .
        '</div>',
      '#cache' => [
        'contexts' => ['route', 'languages:language_interface'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Extracts [lat, lon] from a node's geolocation field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The node.
   *
   * @return array|null
   *   [lat, lon] pair, or NULL if the node has no usable coordinate.
   */
  protected function getCoordinates(EntityInterface $node): ?array {
    foreach (['field_geolocation', 'field_geofield'] as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $value = $node->get($field_name)->first()->getValue();
        $lat = $value['lat'] ?? $value['latitude'] ?? NULL;
        $lon = $value['lon'] ?? $value['longitude'] ?? NULL;
        $is_valid_lat = is_numeric($lat) && (float) $lat >= -90 && (float) $lat <= 90;
        $is_valid_lon = is_numeric($lon) && (float) $lon >= -180 && (float) $lon <= 180;
        if ($is_valid_lat && $is_valid_lon) {
          return [(float) $lat, (float) $lon];
        }
      }
    }
    return NULL;
  }

}