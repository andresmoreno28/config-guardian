<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Batch;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Batch operations for configuration export.
 */
class ConfigExportBatch {

  /**
   * Initializes batch results with backup snapshot info.
   *
   * @param int|null $backup_snapshot_id
   *   The backup snapshot ID.
   * @param array $context
   *   The batch context.
   */
  public static function initialize(?int $backup_snapshot_id, array &$context): void {
    $context['results']['backup_snapshot_id'] = $backup_snapshot_id;
    $context['results']['start_time'] = microtime(TRUE);
    $context['results']['exported'] = 0;
    $context['results']['errors'] = [];

    $context['message'] = new TranslatableMarkup('Initializing export...');

    // Small delay so user can see the progress bar.
    usleep(300000);
  }

  /**
   * Processes a batch of configurations for export.
   *
   * @param array $config_names
   *   List of configuration names to export.
   * @param array $context
   *   The batch context.
   */
  public static function process(array $config_names, array &$context): void {
    // Initialize sandbox for this operation (each operation has its own sandbox).
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total'] = count($config_names);
    }

    // Initialize results only if not already done by initialize().
    if (!isset($context['results']['exported'])) {
      $context['results']['exported'] = 0;
      $context['results']['errors'] = [];
      $context['results']['start_time'] = microtime(TRUE);
    }

    /** @var \Drupal\Core\Config\StorageInterface $active_storage */
    $active_storage = \Drupal::service('config.storage');
    /** @var \Drupal\Core\Config\StorageInterface $sync_storage */
    $sync_storage = \Drupal::service('config.storage.sync');

    // Process each configuration.
    foreach ($config_names as $config_name) {
      try {
        $data = $active_storage->read($config_name);
        if ($data !== FALSE) {
          $sync_storage->write($config_name, $data);
          $context['results']['exported']++;
        }
      }
      catch (\Exception $e) {
        $context['results']['errors'][] = $config_name . ': ' . $e->getMessage();
      }

      $context['sandbox']['progress']++;

      // Update message to show current config being processed.
      $context['message'] = new TranslatableMarkup(
        'Exporting @current of @total configurations...<br><strong>Processing:</strong> <code>@config_name</code>',
        [
          '@current' => $context['sandbox']['progress'],
          '@total' => $context['sandbox']['total'],
          '@config_name' => $config_name,
        ]
      );
    }

    // Update finished status.
    if ($context['sandbox']['total'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }

    // Small delay so user can see the progress bar moving.
    usleep(150000);
  }

  /**
   * Deletes configurations from sync that don't exist in active.
   *
   * @param array $config_names
   *   List of configuration names to delete from sync.
   * @param array $context
   *   The batch context.
   */
  public static function deleteObsolete(array $config_names, array &$context): void {
    if (empty($config_names)) {
      return;
    }

    /** @var \Drupal\Core\Config\StorageInterface $sync_storage */
    $sync_storage = \Drupal::service('config.storage.sync');

    foreach ($config_names as $config_name) {
      try {
        $sync_storage->delete($config_name);
        $context['results']['deleted'] = ($context['results']['deleted'] ?? 0) + 1;

        $context['message'] = new TranslatableMarkup(
          'Removing obsolete configuration: <code>@config_name</code>',
          ['@config_name' => $config_name]
        );
      }
      catch (\Exception $e) {
        $context['results']['errors'][] = $config_name . ': ' . $e->getMessage();
      }
    }
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

    if ($success) {
      $exported = $results['exported'] ?? 0;
      $deleted = $results['deleted'] ?? 0;

      $messenger->addStatus(new TranslatableMarkup(
        'Configuration export completed successfully. @exported configurations exported in @duration seconds.',
        [
          '@exported' => $exported,
          '@duration' => $duration,
        ]
      ));

      if ($deleted > 0) {
        $messenger->addStatus(new TranslatableMarkup(
          '@deleted obsolete configurations removed from sync directory.',
          ['@deleted' => $deleted]
        ));
      }

      // Show backup snapshot info if available.
      if (!empty($results['backup_snapshot_id'])) {
        $messenger->addStatus(new TranslatableMarkup(
          'A backup snapshot (#@id) was created before the export. <a href="@url">View snapshot</a>',
          [
            '@id' => $results['backup_snapshot_id'],
            '@url' => '/admin/config/development/config-guardian/snapshot/' . $results['backup_snapshot_id'],
          ]
        ));
      }

      // Log the activity.
      /** @var \Drupal\config_guardian\Service\ConfigSyncService $config_sync */
      $config_sync = \Drupal::service('config_guardian.config_sync');
      $config_sync->logExportActivity($exported, $duration);
    }
    else {
      $messenger->addError(new TranslatableMarkup('Configuration export encountered errors.'));
    }

    // Show any errors.
    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        $messenger->addError($error);
      }
    }
  }

}
