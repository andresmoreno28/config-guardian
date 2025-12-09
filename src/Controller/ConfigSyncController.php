<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Drupal\config_guardian\Service\ConfigSyncService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for configuration synchronization pages.
 */
class ConfigSyncController extends ControllerBase {

  /**
   * The config sync service.
   */
  protected ConfigSyncService $configSync;

  /**
   * Constructs a ConfigSyncController object.
   */
  public function __construct(ConfigSyncService $config_sync) {
    $this->configSync = $config_sync;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.config_sync')
    );
  }

  /**
   * Displays the configuration sync overview page.
   *
   * @return array
   *   A render array.
   */
  public function overview(): array {
    // Get pending changes for status summary.
    $import_preview = $this->configSync->getImportPreview();
    $export_preview = $this->configSync->getExportPreview();

    $status_items = [];

    // Import status.
    if ($import_preview['has_changes']) {
      $total_import_changes = $import_preview['total_changes'];
      $status_items[] = [
        'type' => 'import',
        'icon' => 'download',
        'label' => $this->t('Pending Import Changes'),
        'value' => $this->t('@count configurations', ['@count' => $total_import_changes]),
        'details' => $this->t('@create to create, @update to update, @delete to delete', [
          '@create' => count($import_preview['changes']['create']),
          '@update' => count($import_preview['changes']['update']),
          '@delete' => count($import_preview['changes']['delete']),
        ]),
        'status' => $total_import_changes > 10 ? 'warning' : 'info',
      ];
    }
    else {
      $status_items[] = [
        'type' => 'import',
        'icon' => 'check',
        'label' => $this->t('Import Status'),
        'value' => $this->t('Synchronized'),
        'details' => $this->t('Active configuration matches sync directory'),
        'status' => 'success',
      ];
    }

    // Export status.
    $export_changes = count($export_preview['new']) + count($export_preview['modified']) + count($export_preview['to_delete']);
    if ($export_changes > 0) {
      $status_items[] = [
        'type' => 'export',
        'icon' => 'upload',
        'label' => $this->t('Pending Export Changes'),
        'value' => $this->t('@count configurations', ['@count' => $export_changes]),
        'details' => $this->t('@new new, @modified modified, @delete to remove', [
          '@new' => count($export_preview['new']),
          '@modified' => count($export_preview['modified']),
          '@delete' => count($export_preview['to_delete']),
        ]),
        'status' => 'info',
      ];
    }
    else {
      $status_items[] = [
        'type' => 'export',
        'icon' => 'check',
        'label' => $this->t('Export Status'),
        'value' => $this->t('Synchronized'),
        'details' => $this->t('Sync directory matches active configuration'),
        'status' => 'success',
      ];
    }

    return [
      '#theme' => 'config_guardian_sync',
      '#export_url' => Url::fromRoute('config_guardian.sync.export'),
      '#import_url' => Url::fromRoute('config_guardian.sync.import'),
      '#status_items' => $status_items,
      '#total_active_configs' => $export_preview['total'],
      '#attached' => [
        'library' => ['config_guardian/config-sync'],
      ],
    ];
  }

}
