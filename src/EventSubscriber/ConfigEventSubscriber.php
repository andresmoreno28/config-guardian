<?php

declare(strict_types=1);

namespace Drupal\config_guardian\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\SettingsService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to configuration events.
 */
class ConfigEventSubscriber implements EventSubscriberInterface {

  /**
   * Static flag to track if pre-import snapshot was already created.
   *
   * Prevents creating multiple snapshots during a single import process
   * since IMPORT_VALIDATE can fire multiple times.
   */
  protected static bool $preImportSnapshotCreated = FALSE;

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * The settings service.
   */
  protected SettingsService $settings;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a ConfigEventSubscriber object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ActivityLoggerService $activity_logger,
    SettingsService $settings,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->activityLogger = $activity_logger;
    $this->settings = $settings;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // We use IMPORT_VALIDATE to capture state BEFORE import starts.
      // ConfigEvents::IMPORT fires AFTER the import is complete, which is too
      // late for creating a pre-import snapshot. The static flag prevents
      // multiple snapshots when IMPORT_VALIDATE fires during preview/validation.
      ConfigEvents::IMPORT_VALIDATE => ['onConfigImportValidate', 100],
      // We also listen to IMPORT to log the changes after import completes.
      ConfigEvents::IMPORT => ['onConfigImport', 100],
    ];
  }

  /**
   * Creates automatic snapshot before config import starts.
   *
   * This fires during IMPORT_VALIDATE, which happens before the actual import.
   * The static flag ensures we only create one snapshot per import operation.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The config import event.
   */
  public function onConfigImportValidate(ConfigImporterEvent $event): void {
    // Create automatic pre-import snapshot if enabled in settings.
    // This runs BEFORE the import happens, capturing the current state.
    if ($this->settings->isAutoSnapshotBeforeImport() && !self::$preImportSnapshotCreated) {
      try {
        $this->snapshotManager->createSnapshot(
          'Auto pre-import ' . date('Y-m-d H:i:s'),
          'pre_import'
        );
        self::$preImportSnapshotCreated = TRUE;
      }
      catch (\Exception $e) {
        // Log but don't prevent import.
        \Drupal::logger('config_guardian')->warning('Failed to create pre-import snapshot: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Logs the configuration import after it completes.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The config import event.
   */
  public function onConfigImport(ConfigImporterEvent $event): void {
    // Log the configuration import after it completes.
    $importer = $event->getConfigImporter();
    $processed = $importer->getProcessedConfiguration();

    $changes = [];
    if (!empty($processed['create'])) {
      $changes['create'] = $processed['create'];
    }
    if (!empty($processed['update'])) {
      $changes['update'] = $processed['update'];
    }
    if (!empty($processed['delete'])) {
      $changes['delete'] = $processed['delete'];
    }

    if (!empty($changes)) {
      $all_configs = array_merge(
        $processed['create'] ?? [],
        $processed['update'] ?? [],
        $processed['delete'] ?? []
      );

      $this->activityLogger->log(
        'config_import',
        $changes,
        $all_configs
      );
    }

    // Reset the flag for the next import operation.
    self::$preImportSnapshotCreated = FALSE;
  }

}
