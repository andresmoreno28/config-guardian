<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Config Guardian settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['config_guardian.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('config_guardian.settings');

    $form['snapshots'] = [
      '#type' => 'details',
      '#title' => $this->t('Snapshot Settings'),
      '#open' => TRUE,
    ];

    $form['snapshots']['auto_snapshot_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic snapshots'),
      '#description' => $this->t('Automatically create snapshots based on the interval below.'),
      '#default_value' => $config->get('auto_snapshot_enabled'),
    ];

    $form['snapshots']['auto_snapshot_before_import'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create snapshot before configuration import'),
      '#description' => $this->t('Automatically create a backup snapshot before any configuration import.'),
      '#default_value' => $config->get('auto_snapshot_before_import'),
    ];

    $form['snapshots']['auto_snapshot_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Automatic snapshot interval'),
      '#options' => [
        'hourly' => $this->t('Hourly'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
      ],
      '#default_value' => $config->get('auto_snapshot_interval'),
      '#states' => [
        'visible' => [
          ':input[name="auto_snapshot_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Retention Settings'),
      '#open' => TRUE,
    ];

    $form['retention']['max_snapshots'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum snapshots to keep'),
      '#description' => $this->t('Older automatic snapshots will be deleted when this limit is exceeded.'),
      '#min' => 5,
      '#max' => 500,
      '#default_value' => $config->get('max_snapshots'),
    ];

    $form['retention']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention days'),
      '#description' => $this->t('Automatic snapshots older than this will be deleted. Set to 0 to disable.'),
      '#min' => 0,
      '#max' => 365,
      '#default_value' => $config->get('retention_days'),
    ];

    $form['storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Storage Settings'),
      '#open' => TRUE,
    ];

    $form['storage']['compression'] = [
      '#type' => 'select',
      '#title' => $this->t('Compression method'),
      '#options' => [
        'none' => $this->t('None'),
        'gzip' => $this->t('Gzip (recommended)'),
        'bzip2' => $this->t('Bzip2'),
      ],
      '#default_value' => $config->get('compression'),
      '#description' => $this->t('Compression reduces storage space but may slightly increase CPU usage.'),
    ];

    $form['exclusions'] = [
      '#type' => 'details',
      '#title' => $this->t('Snapshot Exclusions'),
      '#open' => FALSE,
    ];

    $form['exclusions']['exclusions_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
    ];

    $form['exclusions']['exclusions_info']['message'] = [
      '#markup' => '<strong>' . $this->t('Important') . ':</strong> ' .
      $this->t('These exclusions ONLY affect snapshots. They do NOT affect the standard Import/Export operations, which will always process all configurations.'),
    ];

    $form['exclusions']['exclude_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exclude patterns for snapshots'),
      '#description' => $this->t('Configurations matching these patterns will be excluded when creating snapshots and will be protected from deletion when restoring them. One pattern per line. Supports wildcards (*).'),
      '#default_value' => implode("\n", $config->get('exclude_patterns') ?? []),
      '#rows' => 5,
      '#placeholder' => "system.cron\ncore.extension\ndevel.*",
    ];

    $form['exclusions']['examples'] = [
      '#type' => 'details',
      '#title' => $this->t('Pattern examples'),
      '#open' => FALSE,
    ];

    $form['exclusions']['examples']['list'] = [
      '#theme' => 'item_list',
      '#items' => [
        ['#markup' => '<code>system.cron</code> - ' . $this->t('Excludes only system.cron (contains volatile timestamps)')],
        ['#markup' => '<code>core.extension</code> - ' . $this->t('Excludes module/theme list (dangerous to restore manually)')],
        ['#markup' => '<code>devel.*</code> - ' . $this->t('Excludes all devel module configurations')],
        ['#markup' => '<code>views.view.test_*</code> - ' . $this->t('Excludes views starting with "test_"')],
        ['#markup' => '<code>*.local</code> - ' . $this->t('Excludes any configuration ending in ".local"')],
      ],
    ];

    $form['exclusions']['use_cases'] = [
      '#type' => 'details',
      '#title' => $this->t('When to use exclusions'),
      '#open' => FALSE,
    ];

    $form['exclusions']['use_cases']['content'] = [
      '#markup' => '<p>' . $this->t('Use exclusions for configurations that:') . '</p>' .
      '<ul>' .
      '<li>' . $this->t('<strong>Change frequently</strong>: Like system.cron which updates timestamps constantly.') . '</li>' .
      '<li>' . $this->t('<strong>Are environment-specific</strong>: Development-only configurations that should not be restored.') . '</li>' .
      '<li>' . $this->t('<strong>Could cause issues if restored</strong>: Like core.extension which controls installed modules.') . '</li>' .
      '</ul>',
    ];

    $form = parent::buildForm($form, $form_state);

    // Custom button styling.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'button';
      $form['actions']['submit']['#attributes']['class'][] = 'button--primary';
      $form['actions']['submit']['#attributes']['class'][] = 'button--action';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $exclude_patterns = array_filter(
      array_map('trim', explode("\n", $form_state->getValue('exclude_patterns')))
    );

    $this->config('config_guardian.settings')
      ->set('auto_snapshot_enabled', (bool) $form_state->getValue('auto_snapshot_enabled'))
      ->set('auto_snapshot_before_import', (bool) $form_state->getValue('auto_snapshot_before_import'))
      ->set('auto_snapshot_interval', $form_state->getValue('auto_snapshot_interval'))
      ->set('max_snapshots', (int) $form_state->getValue('max_snapshots'))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->set('compression', $form_state->getValue('compression'))
      ->set('exclude_patterns', $exclude_patterns)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
