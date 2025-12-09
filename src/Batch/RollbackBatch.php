<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Batch;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Batch operations for configuration rollback.
 */
class RollbackBatch {

  /**
   * Batch size for processing configurations.
   */
  const BATCH_SIZE = 25;

  /**
   * Initializes the rollback batch.
   *
   * @param int $snapshot_id
   *   The snapshot ID to rollback to.
   * @param int|null $pre_rollback_snapshot_id
   *   The pre-rollback backup snapshot ID.
   * @param array $active_changelist
   *   The list of active config changes (create, update, delete).
   * @param array $sync_changelist
   *   The list of sync directory changes (create, update, delete).
   * @param array $context
   *   The batch context.
   */
  public static function initialize(int $snapshot_id, ?int $pre_rollback_snapshot_id, array $active_changelist, array $sync_changelist, array &$context): void {
    $context['results']['snapshot_id'] = $snapshot_id;
    $context['results']['pre_rollback_snapshot_id'] = $pre_rollback_snapshot_id;
    $context['results']['active_changelist'] = $active_changelist;
    $context['results']['sync_changelist'] = $sync_changelist;
    $context['results']['start_time'] = microtime(TRUE);
    $context['results']['active_processed'] = [
      'create' => 0,
      'update' => 0,
      'delete' => 0,
    ];
    $context['results']['sync_processed'] = [
      'create' => 0,
      'update' => 0,
      'delete' => 0,
    ];
    $context['results']['errors'] = [];

    $active_changes = count($active_changelist['create'] ?? []) +
      count($active_changelist['update'] ?? []) +
      count($active_changelist['delete'] ?? []);

    $sync_changes = count($sync_changelist['create'] ?? []) +
      count($sync_changelist['update'] ?? []) +
      count($sync_changelist['delete'] ?? []);

    $context['message'] = new TranslatableMarkup(
      'Initializing rollback... (Active: @active, Sync: @sync)',
      ['@active' => $active_changes, '@sync' => $sync_changes]
    );

    // Small delay so user can see the progress bar.
    usleep(300000);
  }

  /**
   * Processes a batch of ACTIVE configuration creates.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to create.
   * @param array $context
   *   The batch context.
   */
  public static function processActiveCreates(int $snapshot_id, array $config_names, array &$context): void {
    self::processActiveConfigs($snapshot_id, $config_names, 'create', $context);
  }

  /**
   * Processes a batch of ACTIVE configuration updates.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to update.
   * @param array $context
   *   The batch context.
   */
  public static function processActiveUpdates(int $snapshot_id, array $config_names, array &$context): void {
    self::processActiveConfigs($snapshot_id, $config_names, 'update', $context);
  }

  /**
   * Processes a batch of ACTIVE configuration deletes.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to delete.
   * @param array $context
   *   The batch context.
   */
  public static function processActiveDeletes(int $snapshot_id, array $config_names, array &$context): void {
    self::processActiveConfigs($snapshot_id, $config_names, 'delete', $context);
  }

  /**
   * Processes a batch of SYNC directory creates.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to create.
   * @param array $context
   *   The batch context.
   */
  public static function processSyncCreates(int $snapshot_id, array $config_names, array &$context): void {
    self::processSyncConfigs($snapshot_id, $config_names, 'create', $context);
  }

  /**
   * Processes a batch of SYNC directory updates.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to update.
   * @param array $context
   *   The batch context.
   */
  public static function processSyncUpdates(int $snapshot_id, array $config_names, array &$context): void {
    self::processSyncConfigs($snapshot_id, $config_names, 'update', $context);
  }

  /**
   * Processes a batch of SYNC directory deletes.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to delete.
   * @param array $context
   *   The batch context.
   */
  public static function processSyncDeletes(int $snapshot_id, array $config_names, array &$context): void {
    self::processSyncConfigs($snapshot_id, $config_names, 'delete', $context);
  }

  /**
   * Processes a batch of ACTIVE configurations.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to process.
   * @param string $operation
   *   The operation (create, update, delete).
   * @param array $context
   *   The batch context.
   */
  protected static function processActiveConfigs(int $snapshot_id, array $config_names, string $operation, array &$context): void {
    /** @var \Drupal\config_guardian\Service\SnapshotManagerService $snapshot_manager */
    $snapshot_manager = \Drupal::service('config_guardian.snapshot_manager');

    // Initialize results if not done.
    if (!isset($context['results']['active_processed'])) {
      $context['results']['active_processed'] = [
        'create' => 0,
        'update' => 0,
        'delete' => 0,
      ];
      $context['results']['errors'] = [];
      $context['results']['start_time'] = microtime(TRUE);
    }

    $operation_labels = [
      'create' => new TranslatableMarkup('Creating'),
      'update' => new TranslatableMarkup('Updating'),
      'delete' => new TranslatableMarkup('Deleting'),
    ];

    // Show what we're processing.
    $config_preview = implode('</code>, <code>', array_slice($config_names, 0, 3));
    if (count($config_names) > 3) {
      $config_preview .= '...';
    }

    $context['message'] = new TranslatableMarkup(
      '[Active] @operation @count configs: <code>@configs</code>',
      [
        '@operation' => $operation_labels[$operation] ?? $operation,
        '@count' => count($config_names),
        '@configs' => $config_preview,
      ]
    );

    try {
      // Get active config storage.
      /** @var \Drupal\Core\Config\StorageInterface $config_storage */
      $config_storage = \Drupal::service('config.storage');

      if ($operation === 'delete') {
        // For deletes, just delete from storage.
        foreach ($config_names as $config_name) {
          try {
            $config_storage->delete($config_name);
            $context['results']['active_processed']['delete']++;
          }
          catch (\Exception $e) {
            $context['results']['errors'][] = "Failed to delete active '$config_name': " . $e->getMessage();
          }
        }
      }
      else {
        // For create/update, load snapshot active data.
        $snapshot_data = $snapshot_manager->getSnapshotActiveData($snapshot_id);

        foreach ($config_names as $config_name) {
          if (!isset($snapshot_data[$config_name])) {
            $context['results']['errors'][] = "Active config '$config_name' not found in snapshot";
            continue;
          }

          try {
            $config_storage->write($config_name, $snapshot_data[$config_name]);
            $context['results']['active_processed'][$operation]++;
          }
          catch (\Exception $e) {
            $context['results']['errors'][] = "Failed to $operation active '$config_name': " . $e->getMessage();
          }
        }
      }
    }
    catch (\Exception $e) {
      $context['results']['errors'][] = $e->getMessage();
    }

    // Small delay so user can see progress.
    usleep(200000);
  }

  /**
   * Processes a batch of SYNC directory configurations.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $config_names
   *   The configuration names to process.
   * @param string $operation
   *   The operation (create, update, delete).
   * @param array $context
   *   The batch context.
   */
  protected static function processSyncConfigs(int $snapshot_id, array $config_names, string $operation, array &$context): void {
    /** @var \Drupal\config_guardian\Service\SnapshotManagerService $snapshot_manager */
    $snapshot_manager = \Drupal::service('config_guardian.snapshot_manager');

    // Initialize results if not done.
    if (!isset($context['results']['sync_processed'])) {
      $context['results']['sync_processed'] = [
        'create' => 0,
        'update' => 0,
        'delete' => 0,
      ];
      $context['results']['errors'] = [];
      $context['results']['start_time'] = microtime(TRUE);
    }

    $operation_labels = [
      'create' => new TranslatableMarkup('Creating'),
      'update' => new TranslatableMarkup('Updating'),
      'delete' => new TranslatableMarkup('Deleting'),
    ];

    // Show what we're processing.
    $config_preview = implode('</code>, <code>', array_slice($config_names, 0, 3));
    if (count($config_names) > 3) {
      $config_preview .= '...';
    }

    $context['message'] = new TranslatableMarkup(
      '[Sync] @operation @count configs: <code>@configs</code>',
      [
        '@operation' => $operation_labels[$operation] ?? $operation,
        '@count' => count($config_names),
        '@configs' => $config_preview,
      ]
    );

    try {
      // Get sync config storage.
      /** @var \Drupal\Core\Config\StorageInterface $sync_storage */
      $sync_storage = \Drupal::service('config.storage.sync');

      if ($operation === 'delete') {
        // For deletes, just delete from sync storage.
        foreach ($config_names as $config_name) {
          try {
            $sync_storage->delete($config_name);
            $context['results']['sync_processed']['delete']++;
          }
          catch (\Exception $e) {
            $context['results']['errors'][] = "Failed to delete sync '$config_name': " . $e->getMessage();
          }
        }
      }
      else {
        // For create/update, load snapshot sync data.
        $snapshot_data = $snapshot_manager->getSnapshotSyncData($snapshot_id);

        foreach ($config_names as $config_name) {
          if (!isset($snapshot_data[$config_name])) {
            $context['results']['errors'][] = "Sync config '$config_name' not found in snapshot";
            continue;
          }

          try {
            $sync_storage->write($config_name, $snapshot_data[$config_name]);
            $context['results']['sync_processed'][$operation]++;
          }
          catch (\Exception $e) {
            $context['results']['errors'][] = "Failed to $operation sync '$config_name': " . $e->getMessage();
          }
        }
      }
    }
    catch (\Exception $e) {
      $context['results']['errors'][] = $e->getMessage();
    }

    // Small delay so user can see progress.
    usleep(200000);
  }

  /**
   * Syncs the sync storage directory after rollback.
   *
   * @param int $snapshot_id
   *   The snapshot ID.
   * @param array $context
   *   The batch context.
   */
  public static function syncStorage(int $snapshot_id, array &$context): void {
    $context['message'] = new TranslatableMarkup('Synchronizing sync directory...');

    try {
      /** @var \Drupal\Core\Config\StorageInterface $config_storage */
      $config_storage = \Drupal::service('config.storage');

      /** @var \Drupal\Core\Config\StorageInterface $sync_storage */
      $sync_storage = \Drupal::service('config.storage.sync');

      // Get current state.
      $active_configs = $config_storage->listAll();
      $sync_configs = $sync_storage->listAll();

      // Write all active configs to sync.
      foreach ($active_configs as $config_name) {
        $data = $config_storage->read($config_name);
        if ($data !== FALSE) {
          $sync_storage->write($config_name, $data);
        }
      }

      // Delete configs from sync that don't exist in active.
      $to_delete = array_diff($sync_configs, $active_configs);
      foreach ($to_delete as $config_name) {
        $sync_storage->delete($config_name);
      }
    }
    catch (\Exception $e) {
      $context['results']['errors'][] = 'Failed to sync storage: ' . $e->getMessage();
    }

    usleep(300000);
  }

  /**
   * Clears caches after rollback.
   *
   * @param array $context
   *   The batch context.
   */
  public static function clearCaches(array &$context): void {
    $context['message'] = new TranslatableMarkup('Clearing caches...');

    try {
      // Reset config factory.
      \Drupal::configFactory()->reset();

      // Flush all caches.
      drupal_flush_all_caches();
    }
    catch (\Exception $e) {
      $context['results']['errors'][] = 'Failed to clear caches: ' . $e->getMessage();
    }

    usleep(500000);
  }

  /**
   * Batch finish callback.
   *
   * @param bool $success
   *   Whether the batch succeeded.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The batch operations.
   */
  public static function finish(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $duration = isset($results['start_time']) ? round(microtime(TRUE) - $results['start_time'], 2) : 0;

    if ($success && empty($results['errors'])) {
      // Calculate active changes.
      $active_changes = ($results['active_processed']['create'] ?? 0) +
        ($results['active_processed']['update'] ?? 0) +
        ($results['active_processed']['delete'] ?? 0);

      // Calculate sync changes.
      $sync_changes = ($results['sync_processed']['create'] ?? 0) +
        ($results['sync_processed']['update'] ?? 0) +
        ($results['sync_processed']['delete'] ?? 0);

      $total_changes = $active_changes + $sync_changes;

      $messenger->addStatus(new TranslatableMarkup(
        'Rollback completed successfully in @duration seconds.',
        ['@duration' => $duration]
      ));

      // Build detailed message for active config.
      if (($results['active_processed']['create'] ?? 0) > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          'Active config: @count created',
          ['@count' => $results['active_processed']['create']]
        ));
      }
      if (($results['active_processed']['update'] ?? 0) > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          'Active config: @count updated',
          ['@count' => $results['active_processed']['update']]
        ));
      }
      if (($results['active_processed']['delete'] ?? 0) > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          'Active config: @count deleted',
          ['@count' => $results['active_processed']['delete']]
        ));
      }

      // Build detailed message for sync directory.
      if (($results['sync_processed']['create'] ?? 0) > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          'Sync directory: @count created',
          ['@count' => $results['sync_processed']['create']]
        ));
      }
      if (($results['sync_processed']['update'] ?? 0) > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          'Sync directory: @count updated',
          ['@count' => $results['sync_processed']['update']]
        ));
      }
      if (($results['sync_processed']['delete'] ?? 0) > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          'Sync directory: @count deleted',
          ['@count' => $results['sync_processed']['delete']]
        ));
      }

      // Show pre-rollback snapshot info if available.
      if (!empty($results['pre_rollback_snapshot_id'])) {
        $messenger->addStatus(new TranslatableMarkup(
          'A backup snapshot (#@id) was created before the rollback. <a href="@url">View snapshot</a>',
          [
            '@id' => $results['pre_rollback_snapshot_id'],
            '@url' => '/admin/config/development/config-guardian/snapshot/' . $results['pre_rollback_snapshot_id'],
          ]
        ));
      }

      // Log the activity.
      try {
        /** @var \Drupal\config_guardian\Service\ActivityLoggerService $activity_logger */
        $activity_logger = \Drupal::service('config_guardian.activity_logger');
        $activity_logger->log(
          'rollback_completed',
          [
            'snapshot_id' => $results['snapshot_id'] ?? NULL,
            'active_changes' => $results['active_processed'] ?? [],
            'sync_changes' => $results['sync_processed'] ?? [],
            'duration' => $duration,
            'pre_rollback_snapshot_id' => $results['pre_rollback_snapshot_id'] ?? NULL,
          ],
          [],
          $results['snapshot_id'] ?? NULL
        );
      }
      catch (\Exception $e) {
        // Log error silently.
      }
    }
    else {
      $messenger->addError(new TranslatableMarkup('Rollback encountered errors.'));

      // Log failure.
      try {
        /** @var \Drupal\config_guardian\Service\ActivityLoggerService $activity_logger */
        $activity_logger = \Drupal::service('config_guardian.activity_logger');
        $activity_logger->log(
          'rollback_failed',
          [
            'snapshot_id' => $results['snapshot_id'] ?? NULL,
            'errors' => $results['errors'] ?? [],
          ],
          [],
          $results['snapshot_id'] ?? NULL,
          'error'
        );
      }
      catch (\Exception $e) {
        // Log error silently.
      }
    }

    // Show any errors.
    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        $messenger->addError($error);
      }
    }
  }

}
