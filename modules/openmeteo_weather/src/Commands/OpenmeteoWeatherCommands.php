<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the OpenMeteo Weather module.
 */
class OpenmeteoWeatherCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an OpenmeteoWeatherCommands instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Exports harbour/anchorage coordinates as JSON for the CMEMS batch job.
   *
   * @param array $options
   *   Command options.
   *
   * @command openmeteo_weather:cmems-coordinates
   * @aliases omw:cmc
   *
   * @option nodes Only include nodes of this type (comma separated).
   * @usage openmeteo_weather:cmems-coordinates --nodes=harbour,anchorage
   */
  public function cmemsCoordinates(array $options = ['nodes' => 'harbour,anchorage']): int {
    $types = array_map('trim', explode(',', (string) $options['nodes']));

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', $types, 'IN');

    $ids = $query->execute();
    if (empty($ids)) {
      $this->output()->writeln('[]');
      return self::EXIT_SUCCESS;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $out = [];
    foreach ($nodes as $node) {
      $coords = $this->coordinates($node);
      if ($coords === NULL) {
        continue;
      }
      $out[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'lat' => $coords[0],
        'lon' => $coords[1],
      ];
    }

    $this->output()->writeln(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');
    return self::EXIT_SUCCESS;
  }

  /**
   * Extracts [lat, lon] from a node's geolocation field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The node.
   *
   * @return array{0: float, 1: float}|null
   *   [lat, lon] pair, or NULL if the node has no usable coordinate.
   */
  protected function coordinates($node): ?array {
    if (!$node instanceof \Drupal\node\NodeInterface) {
      return NULL;
    }
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