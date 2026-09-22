<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\openmeteo_weather\Service\MarineWeatherManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Status overview for the weather provider chain.
 */
class InfoController extends ControllerBase {

  protected MarineWeatherManager $manager;
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs an InfoController.
   *
   * @param \Drupal\openmeteo_weather\Service\MarineWeatherManager $manager
   *   The weather manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(MarineWeatherManager $manager, FileSystemInterface $file_system) {
    $this->manager = $manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('openmeteo_weather.manager'),
      $container->get('file_system'),
    );
  }

  /**
   * Renders the provider chain status page.
   *
   * @return array
   *   Render array.
   */
  public function status(): array {
    $config = $this->config('openmeteo_weather.settings');
    $cmems_path = $config->get('provider.cmems_path') ?? 'private://cmems';
    $real = $this->fileSystem->realpath($cmems_path);

    $rows = [];
    $count = 0;
    if ($real !== FALSE && is_dir($real)) {
      $count = count(glob($real . DIRECTORY_SEPARATOR . '*.json') ?: []);
    }

    $rows[] = [$this->t('Cache TTL'), $config->get('cache_ttl') ?? 10800];
    $rows[] = [$this->t('Copernicus label'), $config->get('provider.copernicus_label') ?? 'Copernicus Marine Service'];
    $rows[] = [
      $this->t('CMEMS cache directory'),
      $cmems_path . ($real !== FALSE ? ' (resolved: ' . $real . ')' : ' (not resolvable)'),
    ];
    $rows[] = [$this->t('CMEMS freshness TTL'), $config->get('provider.cmems_ttl') ?? 21600];
    $rows[] = [$this->t('CMEMS max age'), $config->get('provider.cmems_max_age') ?? 86400];
    $rows[] = [$this->t('Cached CMEMS coordinates'), $count];

    $build = [];
    $build['provider_table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Setting'), $this->t('Value')],
      '#rows' => $rows,
    ];

    $build['coverage'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Coverage notes'),
      '#items' => [
        $this->t('The widget fetches from the live Open-Meteo API first via the manager service (openmeteo_weather.manager).'),
        $this->t('When Open-Meteo is unavailable, the Copernicus Marine provider serves batched data from @dir.', ['@dir' => $cmems_path]),
        $this->t('Missing/expired cache files make the block show the temporary-unavailable message until the batch job refreshes the cache.'),
      ],
    ];

    return $build;
  }

}