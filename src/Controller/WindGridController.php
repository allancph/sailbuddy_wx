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
 * The grid file is written by the external batch job (scripts/wind_grid_fetch.py)
 * into private://windgrid/wind_grid.json as a compact {lat0,lon0,dlat,dlon,
 * rows,cols,u,v} payload. This controller streams it to the browser with
 * short cache headers so the particle layer never hammers Open-Meteo.
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
   * Streams the wind grid JSON payload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON response, or 404 when the batch file is not present.
   */
  public function grid(Request $request): Response {
    $directory = $this->config('openmeteo_weather.settings')
      ->get('provider.wind_grid_path') ?? 'private://windgrid';
    $real = $this->fileSystem->realpath($directory);
    $filename = $real !== FALSE ? $real . DIRECTORY_SEPARATOR . 'wind_grid.json' : FALSE;

    if ($filename === FALSE || !is_file($filename)) {
      $this->logger->warning('Wind grid file not found at @path.', [
        '@path' => $directory,
      ]);
      return new Response('Wind grid not available yet.', Response::HTTP_NOT_FOUND);
    }

    $content = @file_get_contents($filename);
    if ($content === FALSE) {
      return new Response('Wind grid read error.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // 20 minutes browser cache; ETag on mtime lets CDN/browser revalidate.
    $mtime = (int) @filemtime($filename);
    $etag = '"wg-' . $mtime . '"';

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

}