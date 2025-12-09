<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Service;

use Drupal\config_guardian\Model\RiskAssessment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\config_guardian\Model\RollbackResult;
use Drupal\config_guardian\Model\RollbackSimulation;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service for handling configuration rollback operations.
 */
class RollbackEngineService {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The config analyzer.
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * The settings service.
   */
  protected SettingsService $settings;

  /**
   * The config storage (active).
   */
  protected StorageInterface $configStorage;

  /**
   * The sync config storage.
   */
  protected StorageInterface $syncStorage;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The config manager.
   */
  protected ConfigManagerInterface $configManager;

  /**
   * The event dispatcher.
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The lock backend.
   */
  protected LockBackendInterface $lock;

  /**
   * The typed config manager.
   */
  protected TypedConfigManagerInterface $typedConfigManager;

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
   * The string translation.
   */
  protected TranslationInterface $stringTranslation;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a RollbackEngineService object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ConfigAnalyzerService $config_analyzer,
    StorageInterface $config_storage,
    StorageInterface $sync_storage,
    ConfigFactoryInterface $config_factory,
    ConfigManagerInterface $config_manager,
    EventDispatcherInterface $event_dispatcher,
    LockBackendInterface $lock,
    TypedConfigManagerInterface $typed_config_manager,
    ModuleHandlerInterface $module_handler,
    ModuleInstallerInterface $module_installer,
    ThemeHandlerInterface $theme_handler,
    TranslationInterface $string_translation,
    LoggerChannelFactoryInterface $logger_factory,
    SettingsService $settings,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->configAnalyzer = $config_analyzer;
    $this->configStorage = $config_storage;
    $this->syncStorage = $sync_storage;
    $this->configFactory = $config_factory;
    $this->configManager = $config_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->lock = $lock;
    $this->typedConfigManager = $typed_config_manager;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->themeHandler = $theme_handler;
    $this->stringTranslation = $string_translation;
    $this->logger = $logger_factory->get('config_guardian');
    $this->settings = $settings;
  }

  /**
   * Filters config names to remove excluded patterns.
   *
   * @param array $config_names
   *   The configuration names to filter.
   *
   * @return array
   *   The filtered configuration names.
   */
  protected function filterExcludedConfigs(array $config_names): array {
    $exclude_patterns = $this->settings->getExcludePatterns();
    if (empty($exclude_patterns)) {
      return $config_names;
    }

    return array_filter($config_names, function ($name) use ($exclude_patterns) {
      foreach ($exclude_patterns as $pattern) {
        if (fnmatch($pattern, $name)) {
          return FALSE;
        }
      }
      return TRUE;
    });
  }

  /**
   * Executes a full rollback to a snapshot state.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $options
   *   Optional options.
   *
   * @return \Drupal\config_guardian\Model\RollbackResult
   *   The rollback result.
   */
  public function rollbackToSnapshot(int $snapshot_id, array $options = []): RollbackResult {
    $result = new RollbackResult();
    $result->snapshotId = $snapshot_id;
    $result->startTime = time();

    // Load snapshot.
    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      $result->success = FALSE;
      $result->error = 'Snapshot not found';
      return $result;
    }

    // Create pre-rollback snapshot for safety (if enabled).
    $create_backup = $options['create_backup'] ?? TRUE;
    if ($create_backup) {
      try {
        $pre_rollback = $this->snapshotManager->createSnapshot(
          'Pre-rollback snapshot #' . $snapshot_id,
          'pre_rollback'
        );
        $result->preRollbackSnapshotId = (int) $pre_rollback['id'];
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not create pre-rollback snapshot: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    try {
      // Verify snapshot integrity.
      if (!$this->snapshotManager->verifySnapshotIntegrity($snapshot_id)) {
        throw new \Exception('Snapshot integrity check failed');
      }

      // Get active and sync data from snapshot (handles both v1 and v2 formats).
      $active_data = $this->snapshotManager->getSnapshotActiveData($snapshot_id);
      $sync_data = $this->snapshotManager->getSnapshotSyncData($snapshot_id);

      // Create temporary storage with snapshot active data.
      $temp_active_storage = new MemoryStorage();
      foreach ($active_data as $name => $data) {
        $temp_active_storage->write($name, $data);
      }

      // Use StorageComparer to determine active config changes.
      $active_comparer = new StorageComparer(
        $temp_active_storage,
        $this->configStorage
      );
      $active_comparer->createChangelist();

      // Create temporary storage with snapshot sync data.
      $temp_sync_storage = new MemoryStorage();
      foreach ($sync_data as $name => $data) {
        $temp_sync_storage->write($name, $data);
      }

      // Use StorageComparer to determine sync changes.
      $sync_comparer = new StorageComparer(
        $temp_sync_storage,
        $this->syncStorage
      );
      $sync_comparer->createChangelist();

      if (!$active_comparer->hasChanges() && !$sync_comparer->hasChanges()) {
        $result->success = TRUE;
        $result->message = 'No changes needed - already at snapshot state';
        return $result;
      }

      // Build changelists for both active and sync.
      $active_changelist = [
        'create' => $active_comparer->getChangelist('create'),
        'update' => $active_comparer->getChangelist('update'),
        'delete' => !empty($options['delete_new_configs'])
          ? $active_comparer->getChangelist('delete')
          : [],
      ];

      $sync_changelist = [
        'create' => $sync_comparer->getChangelist('create'),
        'update' => $sync_comparer->getChangelist('update'),
        'delete' => $sync_comparer->getChangelist('delete'),
      ];

      // Apply ACTIVE config changes.
      foreach ($active_changelist['create'] as $config_name) {
        $data = $temp_active_storage->read($config_name);
        if ($data !== FALSE) {
          $this->configStorage->write($config_name, $data);
        }
      }

      foreach ($active_changelist['update'] as $config_name) {
        $data = $temp_active_storage->read($config_name);
        if ($data !== FALSE) {
          $this->configStorage->write($config_name, $data);
        }
      }

      foreach ($active_changelist['delete'] as $config_name) {
        $this->configStorage->delete($config_name);
      }

      // Apply SYNC directory changes.
      foreach ($sync_changelist['create'] as $config_name) {
        $data = $temp_sync_storage->read($config_name);
        if ($data !== FALSE) {
          $this->syncStorage->write($config_name, $data);
        }
      }

      foreach ($sync_changelist['update'] as $config_name) {
        $data = $temp_sync_storage->read($config_name);
        if ($data !== FALSE) {
          $this->syncStorage->write($config_name, $data);
        }
      }

      foreach ($sync_changelist['delete'] as $config_name) {
        $this->syncStorage->delete($config_name);
      }

      // Record applied changes.
      $result->changesApplied = [
        'active' => $active_changelist,
        'sync' => $sync_changelist,
      ];

      // Clear all caches to ensure the restored configuration is fully active.
      // This is critical - without this, Drupal may continue using cached
      // configuration values from before the rollback.
      $this->configFactory->reset();
      drupal_flush_all_caches();

      $result->success = TRUE;
      $result->message = 'Rollback completed successfully';

      $active_changes = $result->changesApplied['active'] ?? [];
      $sync_changes = $result->changesApplied['sync'] ?? [];
      $this->logger->info('Rollback to snapshot @id completed successfully. Active: @a_create created, @a_update updated, @a_delete deleted. Sync: @s_create created, @s_update updated, @s_delete deleted.', [
        '@id' => $snapshot_id,
        '@a_create' => count($active_changes['create'] ?? []),
        '@a_update' => count($active_changes['update'] ?? []),
        '@a_delete' => count($active_changes['delete'] ?? []),
        '@s_create' => count($sync_changes['create'] ?? []),
        '@s_update' => count($sync_changes['update'] ?? []),
        '@s_delete' => count($sync_changes['delete'] ?? []),
      ]);
    }
    catch (\Exception $e) {
      $result->success = FALSE;
      $result->error = $e->getMessage();

      $this->logger->error('Rollback to snapshot @id failed: @message', [
        '@id' => $snapshot_id,
        '@message' => $e->getMessage(),
      ]);
    }

    $result->endTime = time();
    $result->duration = $result->endTime - $result->startTime;

    return $result;
  }

  /**
   * Simulates a rollback without executing it (dry-run).
   *
   * This simulates restoring BOTH active configuration AND sync directory
   * to their state at the time of the snapshot.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   *
   * @return \Drupal\config_guardian\Model\RollbackSimulation
   *   The simulation result.
   */
  public function simulateRollback(int $snapshot_id): RollbackSimulation {
    $simulation = new RollbackSimulation();

    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      $simulation->valid = FALSE;
      $simulation->error = 'Snapshot not found';
      return $simulation;
    }

    try {
      // Get active and sync data from snapshot.
      $active_data = $this->snapshotManager->getSnapshotActiveData($snapshot_id);
      $sync_data = $this->snapshotManager->getSnapshotSyncData($snapshot_id);

      // Simulate ACTIVE config changes.
      $temp_active_storage = new MemoryStorage();
      foreach ($active_data as $name => $data) {
        $temp_active_storage->write($name, $data);
      }

      $active_comparer = new StorageComparer(
        $temp_active_storage,
        $this->configStorage
      );
      $active_comparer->createChangelist();

      // Simulate SYNC directory changes.
      $temp_sync_storage = new MemoryStorage();
      foreach ($sync_data as $name => $data) {
        $temp_sync_storage->write($name, $data);
      }

      $sync_comparer = new StorageComparer(
        $temp_sync_storage,
        $this->syncStorage
      );
      $sync_comparer->createChangelist();

      // Combine changes for active config.
      // Filter out excluded configs from delete lists - these were intentionally
      // excluded when creating the snapshot and should not be deleted on restore.
      $simulation->valid = TRUE;
      $simulation->toCreate = $active_comparer->getChangelist('create');
      $simulation->toUpdate = $active_comparer->getChangelist('update');
      $simulation->toDelete = $this->filterExcludedConfigs($active_comparer->getChangelist('delete'));
      $simulation->toRename = $active_comparer->getChangelist('rename');

      // Add sync changes as separate properties.
      $simulation->syncToCreate = $sync_comparer->getChangelist('create');
      $simulation->syncToUpdate = $sync_comparer->getChangelist('update');
      $simulation->syncToDelete = $this->filterExcludedConfigs($sync_comparer->getChangelist('delete'));

      // Total changes includes both active and sync.
      $simulation->activeChanges = count($simulation->toCreate) +
        count($simulation->toUpdate) +
        count($simulation->toDelete) +
        count($simulation->toRename);

      $simulation->syncChanges = count($simulation->syncToCreate) +
        count($simulation->syncToUpdate) +
        count($simulation->syncToDelete);

      $simulation->totalChanges = $simulation->activeChanges + $simulation->syncChanges;

      // Calculate risk based on active config changes (higher impact).
      $all_configs = array_merge(
        $simulation->toUpdate,
        $simulation->toDelete
      );
      $simulation->riskAssessment = $this->configAnalyzer->calculateRiskScore($all_configs);
    }
    catch (\Exception $e) {
      $simulation->valid = FALSE;
      $simulation->error = $e->getMessage();
    }

    return $simulation;
  }

  /**
   * Simulates a sync directory restore without executing it (dry-run).
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   *
   * @return \Drupal\config_guardian\Model\RollbackSimulation
   *   The simulation result.
   *
   * @deprecated in config_guardian:1.1.0 and is removed from config_guardian:2.0.0.
   *   Use simulateRollback() which now simulates both active AND sync.
   * @see \Drupal\config_guardian\Service\RollbackEngineService::simulateRollback()
   */
  public function simulateSyncRestore(int $snapshot_id): RollbackSimulation {
    $simulation = new RollbackSimulation();

    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      $simulation->valid = FALSE;
      $simulation->error = 'Snapshot not found';
      return $simulation;
    }

    try {
      // Get sync data from snapshot (handles v1 and v2 formats).
      $snapshot_data = $this->snapshotManager->getSnapshotSyncData($snapshot_id);

      // Compare snapshot against sync storage (not active).
      $current_sync_configs = $this->syncStorage->listAll();
      $snapshot_configs = array_keys($snapshot_data);

      // Calculate what would change in sync directory.
      $to_create = array_diff($snapshot_configs, $current_sync_configs);
      $to_delete = array_diff($current_sync_configs, $snapshot_configs);
      $to_update = [];

      // Check for modifications in common configs.
      $common = array_intersect($current_sync_configs, $snapshot_configs);
      foreach ($common as $config_name) {
        $current_data = $this->syncStorage->read($config_name);
        if ($current_data !== $snapshot_data[$config_name]) {
          $to_update[] = $config_name;
        }
      }

      $simulation->valid = TRUE;
      $simulation->toCreate = array_values($to_create);
      $simulation->toUpdate = $to_update;
      $simulation->toDelete = array_values($to_delete);
      $simulation->toRename = [];
      $simulation->totalChanges = count($to_create) + count($to_update) + count($to_delete);

      // Calculate risk based on sync changes (lower risk since we're not touching active).
      // Sync restore is generally lower risk - use a simpler calculation.
      $base_risk = 0;
      if ($simulation->totalChanges > 100) {
        $base_risk = 30;
      }
      elseif ($simulation->totalChanges > 50) {
        $base_risk = 20;
      }
      elseif ($simulation->totalChanges > 20) {
        $base_risk = 15;
      }
      elseif ($simulation->totalChanges > 0) {
        $base_risk = 10;
      }

      // Create a simple risk assessment for sync restore.
      $risk = new RiskAssessment();
      $risk->score = $base_risk;
      $risk->level = $base_risk >= 25 ? 'medium' : 'low';
      $risk->riskFactors = [];

      if ($simulation->totalChanges > 50) {
        $risk->riskFactors[] = "Large number of sync changes ({$simulation->totalChanges} files)";
      }
      if (count($to_delete) > 10) {
        $risk->riskFactors[] = count($to_delete) . " files will be removed from sync";
      }

      $simulation->riskAssessment = $risk;
    }
    catch (\Exception $e) {
      $simulation->valid = FALSE;
      $simulation->error = $e->getMessage();
    }

    return $simulation;
  }

  /**
   * Selective rollback of specific configurations.
   *
   * This restores specific active configurations from a snapshot.
   * For selective sync restore, you would need to implement a separate method.
   *
   * @param array $config_names
   *   The configuration names to rollback.
   * @param int $snapshot_id
   *   The snapshot ID.
   *
   * @return \Drupal\config_guardian\Model\RollbackResult
   *   The rollback result.
   */
  public function rollbackConfigs(array $config_names, int $snapshot_id): RollbackResult {
    $result = new RollbackResult();
    $result->snapshotId = $snapshot_id;
    $result->startTime = time();

    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      $result->success = FALSE;
      $result->error = 'Snapshot not found';
      return $result;
    }

    // Get active configuration data from snapshot (handles v1 and v2 formats).
    $snapshot_data = $this->snapshotManager->getSnapshotActiveData($snapshot_id);

    $restored = [];
    $errors = [];

    foreach ($config_names as $config_name) {
      if (!isset($snapshot_data[$config_name])) {
        $errors[] = "Config '$config_name' not found in snapshot";
        continue;
      }

      try {
        // Restore individual configuration.
        $this->configStorage->write($config_name, $snapshot_data[$config_name]);
        $restored[] = $config_name;
      }
      catch (\Exception $e) {
        $errors[] = "Failed to restore '$config_name': " . $e->getMessage();
      }
    }

    // Clear cache.
    $this->configFactory->reset();
    drupal_flush_all_caches();

    $result->success = empty($errors);
    $result->changesApplied = ['update' => $restored];
    $result->errors = $errors;
    $result->endTime = time();
    $result->duration = $result->endTime - $result->startTime;

    if ($result->success) {
      $this->logger->info('Selective rollback completed. Restored @count configurations.', [
        '@count' => count($restored),
      ]);
    }
    else {
      $this->logger->warning('Selective rollback completed with errors: @errors', [
        '@errors' => implode(', ', $errors),
      ]);
    }

    return $result;
  }

  /**
   * Syncs the sync storage directory to match the current active state.
   *
   * This ensures that after a rollback, both the active configuration
   * and the sync directory are in sync (no pending changes to import/export).
   *
   * @param array $snapshot_data
   *   The configuration data from the snapshot (used as base).
   */
  protected function syncStorageWithSnapshot(array $snapshot_data): void {
    // Get current sync storage contents and active storage contents.
    $current_sync_configs = $this->syncStorage->listAll();
    $current_active_configs = $this->configStorage->listAll();

    $written = 0;
    $deleted = 0;

    // Write all active configs to sync storage to ensure they match.
    // This includes both snapshot configs and any configs that exist in active
    // but weren't in the snapshot (which rollback preserves by default).
    foreach ($current_active_configs as $config_name) {
      $data = $this->configStorage->read($config_name);
      if ($data !== FALSE) {
        $this->syncStorage->write($config_name, $data);
        $written++;
      }
    }

    // Delete configs from sync that don't exist in active storage.
    $to_delete = array_diff($current_sync_configs, $current_active_configs);
    foreach ($to_delete as $config_name) {
      $this->syncStorage->delete($config_name);
      $deleted++;
    }

    $this->logger->info('Sync directory updated to match active config. Written: @written, Deleted: @deleted', [
      '@written' => $written,
      '@deleted' => $deleted,
    ]);
  }

  /**
   * Restores the sync directory from a snapshot (without touching active).
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   *
   * @return \Drupal\config_guardian\Model\RollbackResult
   *   The result of the restore operation.
   *
   * @deprecated in config_guardian:1.1.0 and is removed from config_guardian:2.0.0.
   *   Use rollbackToSnapshot() or batch processing which restores both active AND sync.
   * @see \Drupal\config_guardian\Service\RollbackEngineService::rollbackToSnapshot()
   */
  public function restoreSyncFromSnapshot(int $snapshot_id): RollbackResult {
    $result = new RollbackResult();
    $result->snapshotId = $snapshot_id;
    $result->startTime = time();

    // Load snapshot.
    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      $result->success = FALSE;
      $result->error = 'Snapshot not found';
      return $result;
    }

    try {
      // Verify integrity.
      if (!$this->snapshotManager->verifySnapshotIntegrity($snapshot_id)) {
        throw new \Exception('Snapshot integrity check failed');
      }

      // Get sync data from snapshot (handles v1 and v2 formats).
      $snapshot_data = $this->snapshotManager->getSnapshotSyncData($snapshot_id);

      // Get current sync storage contents.
      $current_sync_configs = $this->syncStorage->listAll();
      $snapshot_configs = array_keys($snapshot_data);

      $created = 0;
      $updated = 0;
      $deleted = 0;

      // Write all configs from snapshot to sync storage.
      foreach ($snapshot_data as $config_name => $data) {
        if (in_array($config_name, $current_sync_configs)) {
          // Check if different.
          $current_data = $this->syncStorage->read($config_name);
          if ($current_data !== $data) {
            $this->syncStorage->write($config_name, $data);
            $updated++;
          }
        }
        else {
          $this->syncStorage->write($config_name, $data);
          $created++;
        }
      }

      // Delete configs from sync that are not in snapshot.
      $to_delete = array_diff($current_sync_configs, $snapshot_configs);
      foreach ($to_delete as $config_name) {
        $this->syncStorage->delete($config_name);
        $deleted++;
      }

      $result->success = TRUE;
      $result->message = 'Sync directory restored to snapshot state';
      $result->changesApplied = [
        'create' => [],
        'update' => [],
        'delete' => [],
      ];

      $this->logger->info('Sync directory restored from snapshot @id. Created: @created, Updated: @updated, Deleted: @deleted', [
        '@id' => $snapshot_id,
        '@created' => $created,
        '@updated' => $updated,
        '@deleted' => $deleted,
      ]);
    }
    catch (\Exception $e) {
      $result->success = FALSE;
      $result->error = $e->getMessage();

      $this->logger->error('Failed to restore sync from snapshot @id: @message', [
        '@id' => $snapshot_id,
        '@message' => $e->getMessage(),
      ]);
    }

    $result->endTime = time();
    $result->duration = $result->endTime - $result->startTime;

    return $result;
  }

  /**
   * Prepares data for batch rollback processing.
   *
   * Prepares the changelist for BOTH active configuration AND sync directory
   * restoration, ensuring the environment is returned to the exact state
   * captured in the snapshot.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $options
   *   Optional options.
   *
   * @return array
   *   The batch preparation data including changelist and pre-rollback snapshot.
   */
  public function prepareRollbackBatch(int $snapshot_id, array $options = []): array {
    $result = [
      'valid' => FALSE,
      'error' => NULL,
      'snapshot_id' => $snapshot_id,
      'pre_rollback_snapshot_id' => NULL,
      'active_changelist' => [
        'create' => [],
        'update' => [],
        'delete' => [],
      ],
      'sync_changelist' => [
        'create' => [],
        'update' => [],
        'delete' => [],
      ],
    ];

    // Load snapshot.
    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      $result['error'] = 'Snapshot not found';
      return $result;
    }

    // Create pre-rollback snapshot for safety (if enabled).
    $create_backup = $options['create_backup'] ?? TRUE;
    if ($create_backup) {
      try {
        $pre_rollback = $this->snapshotManager->createSnapshot(
          'Pre-rollback backup - ' . date('Y-m-d H:i:s'),
          'pre_rollback'
        );
        $result['pre_rollback_snapshot_id'] = (int) $pre_rollback['id'];
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not create pre-rollback snapshot: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    try {
      // Get active and sync data from snapshot.
      $active_data = $this->snapshotManager->getSnapshotActiveData($snapshot_id);
      $sync_data = $this->snapshotManager->getSnapshotSyncData($snapshot_id);

      // Prepare ACTIVE config changelist.
      $temp_active_storage = new MemoryStorage();
      foreach ($active_data as $name => $data) {
        $temp_active_storage->write($name, $data);
      }

      $active_comparer = new StorageComparer(
        $temp_active_storage,
        $this->configStorage
      );
      $active_comparer->createChangelist();

      $result['active_changelist'] = [
        'create' => $active_comparer->getChangelist('create'),
        'update' => $active_comparer->getChangelist('update'),
        'delete' => !empty($options['delete_new_configs'])
          ? $active_comparer->getChangelist('delete')
          : [],
      ];

      // Prepare SYNC directory changelist.
      $temp_sync_storage = new MemoryStorage();
      foreach ($sync_data as $name => $data) {
        $temp_sync_storage->write($name, $data);
      }

      $sync_comparer = new StorageComparer(
        $temp_sync_storage,
        $this->syncStorage
      );
      $sync_comparer->createChangelist();

      // For sync, we apply all changes but filter out excluded configs from deletes.
      // Excluded configs were intentionally not captured in the snapshot.
      $result['sync_changelist'] = [
        'create' => $sync_comparer->getChangelist('create'),
        'update' => $sync_comparer->getChangelist('update'),
        'delete' => $this->filterExcludedConfigs($sync_comparer->getChangelist('delete')),
      ];

      // Check if there are any changes at all.
      $has_active_changes = !empty($result['active_changelist']['create']) ||
        !empty($result['active_changelist']['update']) ||
        !empty($result['active_changelist']['delete']);

      $has_sync_changes = !empty($result['sync_changelist']['create']) ||
        !empty($result['sync_changelist']['update']) ||
        !empty($result['sync_changelist']['delete']);

      if (!$has_active_changes && !$has_sync_changes) {
        $result['valid'] = TRUE;
        $result['no_changes'] = TRUE;
        return $result;
      }

      $result['valid'] = TRUE;
    }
    catch (\Exception $e) {
      $result['error'] = $e->getMessage();

      $this->logger->error('Failed to prepare rollback batch for snapshot @id: @message', [
        '@id' => $snapshot_id,
        '@message' => $e->getMessage(),
      ]);
    }

    return $result;
  }

}
