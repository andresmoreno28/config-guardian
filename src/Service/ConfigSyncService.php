<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Service;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service for configuration synchronization (import/export).
 */
class ConfigSyncService {

  use StringTranslationTrait;

  /**
   * The active config storage.
   */
  protected StorageInterface $activeStorage;

  /**
   * The sync config storage.
   */
  protected StorageInterface $syncStorage;

  /**
   * The config manager.
   */
  protected ConfigManagerInterface $configManager;

  /**
   * The typed config manager.
   */
  protected TypedConfigManagerInterface $typedConfigManager;

  /**
   * The lock backend.
   */
  protected LockBackendInterface $lock;

  /**
   * The event dispatcher.
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The module installer.
   */
  protected ModuleInstallerInterface $moduleInstaller;

  /**
   * The theme handler.
   */
  protected ThemeHandlerInterface $themeHandler;

  /**
   * The module extension list.
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * The theme extension list.
   */
  protected ThemeExtensionList $themeExtensionList;

  /**
   * The config analyzer service.
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * The activity logger service.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * The snapshot manager service.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * Constructs a ConfigSyncService object.
   */
  public function __construct(
    StorageInterface $active_storage,
    StorageInterface $sync_storage,
    ConfigManagerInterface $config_manager,
    TypedConfigManagerInterface $typed_config_manager,
    LockBackendInterface $lock,
    EventDispatcherInterface $event_dispatcher,
    ModuleHandlerInterface $module_handler,
    ModuleInstallerInterface $module_installer,
    ThemeHandlerInterface $theme_handler,
    ModuleExtensionList $module_extension_list,
    ThemeExtensionList $theme_extension_list,
    ConfigAnalyzerService $config_analyzer,
    ActivityLoggerService $activity_logger,
    SnapshotManagerService $snapshot_manager,
  ) {
    $this->activeStorage = $active_storage;
    $this->syncStorage = $sync_storage;
    $this->configManager = $config_manager;
    $this->typedConfigManager = $typed_config_manager;
    $this->lock = $lock;
    $this->eventDispatcher = $event_dispatcher;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->themeHandler = $theme_handler;
    $this->moduleExtensionList = $module_extension_list;
    $this->themeExtensionList = $theme_extension_list;
    $this->configAnalyzer = $config_analyzer;
    $this->activityLogger = $activity_logger;
    $this->snapshotManager = $snapshot_manager;
  }

  /**
   * Gets list of all active configurations.
   *
   * @return array
   *   List of configuration names.
   */
  public function getActiveConfigList(): array {
    return $this->activeStorage->listAll();
  }

  /**
   * Gets active configurations grouped by module/type.
   *
   * @return array
   *   Configurations grouped by prefix.
   */
  public function getActiveConfigGrouped(): array {
    $all_configs = $this->activeStorage->listAll();
    $grouped = [];

    foreach ($all_configs as $config_name) {
      $parts = explode('.', $config_name);
      $group = $parts[0] ?? 'other';
      $grouped[$group][] = $config_name;
    }

    ksort($grouped);
    return $grouped;
  }

  /**
   * Gets export preview information.
   *
   * @return array
   *   Export preview data.
   */
  public function getExportPreview(): array {
    $active_configs = $this->activeStorage->listAll();
    $sync_configs = $this->syncStorage->listAll();

    $preview = [
      'total' => count($active_configs),
      'new' => [],
      'modified' => [],
      'unchanged' => [],
      'to_delete' => [],
      'grouped' => $this->getActiveConfigGrouped(),
    ];

    // Find configs in active but not in sync (new).
    $preview['new'] = array_values(array_diff($active_configs, $sync_configs));

    // Find configs in sync but not in active (will be deleted from sync).
    $preview['to_delete'] = array_values(array_diff($sync_configs, $active_configs));

    // Find modified configs.
    $common = array_intersect($active_configs, $sync_configs);
    foreach ($common as $name) {
      $active_data = $this->activeStorage->read($name);
      $sync_data = $this->syncStorage->read($name);
      if ($active_data !== $sync_data) {
        $preview['modified'][] = $name;
      }
      else {
        $preview['unchanged'][] = $name;
      }
    }

    return $preview;
  }

  /**
   * Gets import preview with risk assessment.
   *
   * @return array
   *   Import preview data with changes and risk assessment.
   */
  public function getImportPreview(): array {
    $changes = $this->configAnalyzer->getPendingChanges();

    $all_changed_configs = array_merge(
      $changes['create'],
      $changes['update'],
      $changes['delete']
    );

    $preview = [
      'changes' => [
        'create' => [],
        'update' => [],
        'delete' => [],
      ],
      'total_changes' => count($all_changed_configs),
      'risk_assessment' => NULL,
      'conflicts' => [],
      'has_changes' => !empty($all_changed_configs),
    ];

    // Add details for each change with risk level.
    foreach ($changes['create'] as $config_name) {
      $analysis = $this->configAnalyzer->analyzeConfig($config_name);
      $preview['changes']['create'][] = [
        'name' => $config_name,
        'type' => $analysis->configType,
        'risk' => $this->getConfigRiskLevel($analysis->impactScore),
      ];
    }

    foreach ($changes['update'] as $config_name) {
      $analysis = $this->configAnalyzer->analyzeConfig($config_name);
      $preview['changes']['update'][] = [
        'name' => $config_name,
        'type' => $analysis->configType,
        'risk' => $this->getConfigRiskLevel($analysis->impactScore),
        'dependents_count' => count($analysis->dependents),
      ];
    }

    foreach ($changes['delete'] as $config_name) {
      $analysis = $this->configAnalyzer->analyzeConfig($config_name);
      $preview['changes']['delete'][] = [
        'name' => $config_name,
        'type' => $analysis->configType,
        'risk' => $this->getConfigRiskLevel($analysis->impactScore),
        'dependents_count' => count($analysis->dependents),
      ];
    }

    // Calculate overall risk assessment.
    if (!empty($all_changed_configs)) {
      $preview['risk_assessment'] = $this->configAnalyzer->calculateRiskScore($all_changed_configs);
    }

    // Find conflicts.
    $preview['conflicts'] = $this->configAnalyzer->findConflicts(
      array_merge($changes['create'], $changes['update'])
    );

    return $preview;
  }

  /**
   * Gets risk level string from score.
   *
   * @param int $score
   *   The impact score.
   *
   * @return string
   *   The risk level.
   */
  protected function getConfigRiskLevel(int $score): string {
    if ($score >= 75) {
      return 'critical';
    }
    if ($score >= 50) {
      return 'high';
    }
    if ($score >= 25) {
      return 'medium';
    }
    return 'low';
  }

  /**
   * Exports a single configuration to sync storage.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return bool
   *   TRUE if exported successfully.
   */
  public function exportConfig(string $config_name): bool {
    $data = $this->activeStorage->read($config_name);
    if ($data === FALSE) {
      return FALSE;
    }

    $this->syncStorage->write($config_name, $data);
    return TRUE;
  }

  /**
   * Exports all active configurations to sync storage.
   *
   * @return array
   *   Results with counts.
   */
  public function exportAllConfigs(): array {
    $active_configs = $this->activeStorage->listAll();
    $sync_configs = $this->syncStorage->listAll();

    $results = [
      'exported' => 0,
      'deleted' => 0,
      'errors' => [],
    ];

    // Export all active configs to sync.
    foreach ($active_configs as $config_name) {
      try {
        $data = $this->activeStorage->read($config_name);
        if ($data !== FALSE) {
          $this->syncStorage->write($config_name, $data);
          $results['exported']++;
        }
      }
      catch (\Exception $e) {
        $results['errors'][] = $config_name . ': ' . $e->getMessage();
      }
    }

    // Delete configs from sync that don't exist in active.
    $to_delete = array_diff($sync_configs, $active_configs);
    foreach ($to_delete as $config_name) {
      try {
        $this->syncStorage->delete($config_name);
        $results['deleted']++;
      }
      catch (\Exception $e) {
        $results['errors'][] = $config_name . ': ' . $e->getMessage();
      }
    }

    return $results;
  }

  /**
   * Creates a ConfigImporter for importing configuration.
   *
   * @return \Drupal\Core\Config\ConfigImporter
   *   The config importer.
   *
   * @throws \Drupal\Core\Config\ConfigImporterException
   *   If validation fails.
   */
  public function createConfigImporter(): ConfigImporter {
    $storage_comparer = new StorageComparer(
      $this->syncStorage,
      $this->activeStorage
    );

    $config_importer = new ConfigImporter(
      $storage_comparer,
      $this->eventDispatcher,
      $this->configManager,
      $this->lock,
      $this->typedConfigManager,
      $this->moduleHandler,
      $this->moduleInstaller,
      $this->themeHandler,
      $this->getStringTranslation(),
      $this->moduleExtensionList,
      $this->themeExtensionList
    );

    return $config_importer;
  }

  /**
   * Validates import before execution.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validateImport(): array {
    $errors = [];

    try {
      $config_importer = $this->createConfigImporter();
      $config_importer->validate();
    }
    catch (ConfigImporterException $e) {
      $errors = $config_importer->getErrors();
    }
    catch (\Exception $e) {
      $errors[] = $e->getMessage();
    }

    return $errors;
  }

  /**
   * Creates a backup snapshot before import.
   *
   * @return int|null
   *   The snapshot ID, or NULL if creation failed.
   */
  public function createBackupSnapshot(): ?int {
    try {
      $snapshot = $this->snapshotManager->createSnapshot(
        'Pre-import backup - ' . date('Y-m-d H:i:s'),
        'pre_import'
      );

      $this->activityLogger->log(
        'snapshot_created',
        [
          'name' => $snapshot['name'],
          'type' => 'pre_import',
          'reason' => 'Automatic backup before configuration import',
        ]
      );

      return (int) $snapshot['id'];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Logs export activity.
   *
   * @param int $count
   *   Number of configurations exported.
   * @param float $duration
   *   Duration in seconds.
   */
  public function logExportActivity(int $count, float $duration): void {
    $this->activityLogger->log(
      'config_exported',
      [
        'count' => $count,
        'duration' => round($duration, 2),
      ]
    );
  }

  /**
   * Logs import activity.
   *
   * @param array $results
   *   Import results with created, updated, deleted counts.
   * @param float $duration
   *   Duration in seconds.
   * @param int|null $backup_snapshot_id
   *   The backup snapshot ID if created.
   */
  public function logImportActivity(array $results, float $duration, ?int $backup_snapshot_id = NULL): void {
    $this->activityLogger->log(
      'config_imported',
      [
        'created' => $results['created'] ?? 0,
        'updated' => $results['updated'] ?? 0,
        'deleted' => $results['deleted'] ?? 0,
        'duration' => round($duration, 2),
        'backup_snapshot_id' => $backup_snapshot_id,
      ]
    );
  }

}
