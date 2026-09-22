<?php

declare(strict_types=1);

namespace Drupal\openmeteo_weather\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure the OpenMeteo Weather provider chain.
 */
class OpenmeteoWeatherSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['openmeteo_weather.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'openmeteo_weather_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('openmeteo_weather.settings');

    $form['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Forecast cache TTL (seconds)'),
      '#description' => $this->t('How long a fetched forecast is kept before the provider chain is consulted again.'),
      '#min' => 60,
      '#max' => 86400,
      '#default_value' => $config->get('cache_ttl') ?? 10800,
      '#required' => TRUE,
    ];

    $form['provider'] = [
      '#type' => 'details',
      '#title' => $this->t('Copernicus Marine provider (fallback)'),
      '#open' => TRUE,
    ];

    $form['provider']['copernicus_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Display label'),
      '#description' => $this->t('Shown in the widget footer when data comes from the fallback provider.'),
      '#default_value' => $config->get('provider.copernicus_label') ?? 'Copernicus Marine Service',
      '#required' => TRUE,
    ];

    $form['provider']['cmems_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cache directory'),
      '#description' => $this->t('Directory (stream wrapper or absolute path) holding the JSON cache files written by the periodic batch job. Use private://cmems to keep data out of the public web root.'),
      '#default_value' => $config->get('provider.cmems_path') ?? 'private://cmems',
      '#required' => TRUE,
    ];

    $form['provider']['cmems_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Freshness TTL (seconds)'),
      '#description' => $this->t('Data younger than this is considered fresh; older data is still served but flagged as stale.'),
      '#min' => 300,
      '#max' => 604800,
      '#default_value' => $config->get('provider.cmems_ttl') ?? 21600,
      '#required' => TRUE,
    ];

    $form['provider']['cmems_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum accepted age (seconds)'),
      '#description' => $this->t('Cached data older than this is treated as unavailable, forcing the widget to show the fallback message.'),
      '#min' => 3600,
      '#max' => 2592000,
      '#default_value' => $config->get('provider.cmems_max_age') ?? 86400,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('openmeteo_weather.settings')
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('provider.copernicus_label', $form_state->getValue('copernicus_label'))
      ->set('provider.cmems_path', $form_state->getValue('cmems_path'))
      ->set('provider.cmems_ttl', (int) $form_state->getValue('cmems_ttl'))
      ->set('provider.cmems_max_age', (int) $form_state->getValue('cmems_max_age'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}