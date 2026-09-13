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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('openmeteo_weather.client');
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');

    // Only render for the content types this widget was built for.
    if (!$node instanceof EntityInterface || !in_array($node->bundle(), ['harbour', 'anchorage'], TRUE)) {
      return [];
    }

    $coords = $this->getCoordinates($node);
    if ($coords === NULL) {
      return [];
    }

    $data = $this->client->getForecast($coords[0], $coords[1]);
    if ($data === NULL) {
      // Open-Meteo er ikke tilgængeligt (DNS/API-nedbrud). Caches siden
      // kun kort, så den ikke gemmes uden widget i 3 timer.
      return [
        '#cache' => [
          'contexts' => ['route', 'languages:language_interface'],
          'tags' => ['node:' . $node->id()],
          'max-age' => 300,
        ],
      ];
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
        if (is_numeric($lat) && is_numeric($lon) && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lon >= -180 && (float) $lon <= 180) {
          return [(float) $lat, (float) $lon];
        }
      }
    }
    return NULL;
  }

}