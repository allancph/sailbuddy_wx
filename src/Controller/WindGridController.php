<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the batched wind vector grid as JSON for the Leaflet overlay.
 *
 * The grid batch files are written by the external job (scripts/wind_grid_fetch.py)
 * into private://windgrid/ as compact geo-referenced JSON payloads. This
 * controller streams them to the browser with short cache headers so the
 * particle layer never hammers Open-Meteo.
 */
class WindGridController extends ControllerBase {

  protected FileSystemInterface $fileSystem;
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a WindGridController.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('openmeteo_weather');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_system'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Streams a wind grid JSON payload for a given batch file.
   *
   * Keeps one stream + cache code path shared by the DK (fine) and EU (coarse)
   * grids; each public route only picks the file name and an ETag prefix.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $filename
   *   Base name of the batch file (e.g. 'wind_grid.json').
   * @param string $etag_prefix
   *   Unique ETag prefix per grid so DK/EU never share an ETag.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON response, or 404 when the batch file is not present.
   */
  private function serve(Request $request, string $filename, string $etag_prefix): Response {
    $directory = $this->config('openmeteo_weather.settings')
      ->get('provider.wind_grid_path') ?? 'private://windgrid';
    $real = $this->fileSystem->realpath($directory);
    $full = $real !== FALSE ? $real . DIRECTORY_SEPARATOR . $filename : FALSE;

    if ($full === FALSE || !is_file($full)) {
      $this->logger->warning('Wind grid file not found at @path.', [
        '@path' => $directory . DIRECTORY_SEPARATOR . $filename,
      ]);
      return new Response('Wind grid not available yet.', Response::HTTP_NOT_FOUND);
    }

    $content = @file_get_contents($full);
    if ($content === FALSE) {
      return new Response('Wind grid read error.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // 20 minutes browser cache; ETag on mtime lets CDN/browser revalidate.
    $mtime = (int) @filemtime($full);
    $etag = '"' . $etag_prefix . '-' . $mtime . '"';

    if ($request->headers->get('If-None-Match') === $etag) {
      $response = new Response('', Response::HTTP_NOT_MODIFIED);
    }
    else {
      $response = new Response($content, Response::HTTP_OK, [
        'Content-Type' => 'application/json; charset=utf-8',
        'ETag' => $etag,
      ]);
    }

    $response->setMaxAge(1200);
    $response->setSharedMaxAge(1200);
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');

    return $response;
  }

  /**
   * Streams the fine (0.25 deg) Danish wind grid JSON payload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON response, or 404 when the batch file is not present.
   */
  public function grid(Request $request): Response {
    return $this->serve($request, 'wind_grid.json', 'wg');
  }

  /**
   * Streams the coarse (1 deg) European wind grid JSON payload.
   *
   * The EU grid has the Danish box null-masked so the fine DK grid and the
   * coarse EU grid never double up on the frontend particle overlay.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON response, or 404 when the batch file is not present.
   */
  public function gridEu(Request $request): Response {
    return $this->serve($request, 'wind_grid_eu.json', 'wgeu');
  }

}
