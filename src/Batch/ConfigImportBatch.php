<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Batch;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Batch operations for configuration import.
 */
class ConfigImportBatch {

  /**
   * Initializes batch results with backup snapshot and import stats.
   *
   * @param int|null $backup_snapshot_id
   *   The backup snapshot ID.
   * @param array $import_stats
   *   The import statistics (created, updated, deleted counts).
   * @param array $context
   *   The batch context.
   */
  public static function initialize(?int $backup_snapshot_id, array $import_stats, array &$context): void {
    $context['results']['backup_snapshot_id'] = $backup_snapshot_id;
    $context['results']['import_stats'] = $import_stats;
    $context['results']['start_time'] = microtime(TRUE);
    $context['results']['steps_completed'] = 0;
    $context['results']['errors'] = [];

    $context['message'] = new TranslatableMarkup('Initializing import...');

    // Small delay so user can see the progress bar.
    usleep(300000);
  }

  /**
   * Processes a configuration import step.
   *
   * @param \Drupal\Core\Config\ConfigImporter $config_importer
   *   The config importer.
   * @param string $sync_step
   *   The synchronization step to process.
   * @param array $context
   *   The batch context.
   */
  public static function process(ConfigImporter $config_importer, string $sync_step, array &$context): void {
    // Initialize sandbox for this operation.
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
    }

    // Initialize results only if not already done by initialize().
    if (!isset($context['results']['start_time'])) {
      $context['results']['start_time'] = microtime(TRUE);
      $context['results']['steps_completed'] = 0;
      $context['results']['errors'] = [];
    }

    // Get the changelist for detailed messages.
    $changelist = $config_importer->getStorageComparer()->getChangelist();

    // Map step names to human-readable labels.
    $step_labels = [
      'validateConfigurations' => new TranslatableMarkup('Validating configurations'),
      'processConfigurations' => new TranslatableMarkup('Processing configurations'),
      'processExtensions' => new TranslatableMarkup('Processing extensions'),
      'processMissingContent' => new TranslatableMarkup('Processing missing content'),
      'finish' => new TranslatableMarkup('Finishing import'),
    ];

    $step_label = $step_labels[$sync_step] ?? $sync_step;

    // Get current operation details.
    $current_configs = [];
    foreach (['create', 'update', 'delete', 'rename'] as $op) {
      if (!empty($changelist[$op])) {
        $current_configs = array_merge($current_configs, array_slice($changelist[$op], 0, 3));
      }
    }

    $config_preview = '';
    if (!empty($current_configs)) {
      $config_list = implode('</code>, <code>', array_slice($current_configs, 0, 3));
      if (count($current_configs) > 3) {
        $config_list .= '...';
      }
      $config_preview = '<br><strong>' . new TranslatableMarkup('Processing:') . '</strong> <code>' . $config_list . '</code>';
    }

    $context['message'] = new TranslatableMarkup(
      '@step_label...@config_preview',
      [
        '@step_label' => $step_label,
        '@config_preview' => $config_preview,
      ]
    );

    try {
      $config_importer->doSyncStep($sync_step, $context);
      $context['results']['steps_completed']++;
    }
    catch (\Exception $e) {
      $context['results']['errors'][] = $e->getMessage();
    }

    // Small delay so user can see the progress bar moving.
    usleep(400000);
  }

  /**
   * Batch finish callback for import.
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
      $messenger->addStatus(new TranslatableMarkup(
        'Configuration import completed successfully in @duration seconds.',
        ['@duration' => $duration]
      ));

      // Show backup snapshot info if available.
      if (!empty($results['backup_snapshot_id'])) {
        $messenger->addStatus(new TranslatableMarkup(
          'A backup snapshot (#@id) was created before the import. <a href="@url">View snapshot</a>',
          [
            '@id' => $results['backup_snapshot_id'],
            '@url' => '/admin/config/development/config-guardian/snapshot/' . $results['backup_snapshot_id'],
          ]
        ));
      }

      // Log the activity.
      /** @var \Drupal\config_guardian\Service\ConfigSyncService $config_sync */
      $config_sync = \Drupal::service('config_guardian.config_sync');
      $config_sync->logImportActivity(
        $results['import_stats'] ?? [],
        $duration,
        $results['backup_snapshot_id'] ?? NULL
      );
    }
    else {
      $messenger->addError(new TranslatableMarkup('Configuration import encountered errors.'));
    }

    // Show any errors.
    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        $messenger->addError($error);
      }
    }
  }

}
