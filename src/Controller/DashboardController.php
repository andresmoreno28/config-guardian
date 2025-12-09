<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\ConfigAnalyzerService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Config Guardian dashboard.
 */
class DashboardController extends ControllerBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The config analyzer.
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * Constructs a DashboardController object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ConfigAnalyzerService $config_analyzer,
    ActivityLoggerService $activity_logger,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->configAnalyzer = $config_analyzer;
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.config_analyzer'),
      $container->get('config_guardian.activity_logger')
    );
  }

  /**
   * Displays the dashboard.
   *
   * @return array
   *   A render array.
   */
  public function dashboard(): array {
    // Get comprehensive environment status (bidirectional analysis).
    $env_status = $this->configAnalyzer->getEnvironmentStatus();

    // Check for conflicts if there are import changes.
    $conflicts = [];
    if ($env_status['import']['total'] > 0) {
      $all_import_changes = array_merge(
        $env_status['import']['create'],
        $env_status['import']['update']
      );
      $conflicts = $this->configAnalyzer->findConflicts($all_import_changes);
    }

    // Determine sync status for the banner.
    $sync_status = $env_status['status'];
    if (!empty($conflicts)) {
      $sync_status = 'conflict';
    }

    // Get stats showing both storages.
    $stats = [
      'active_count' => $env_status['active_count'],
      'sync_count' => $env_status['sync_count'],
      'modified_count' => count($env_status['modified']),
      'total_snapshots' => $this->snapshotManager->getSnapshotCount(),
    ];

    // Get recent snapshots.
    $recent_snapshots = $this->snapshotManager->getSnapshotList([], 5);

    // Get recent activity.
    $recent_activity = $this->activityLogger->getRecentActivities(10);

    // Build list of notable changes (modified configs with risk analysis).
    $notable_changes = [];
    $all_modified = $env_status['modified'];
    foreach (array_slice($all_modified, 0, 10) as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $notable_changes[] = [
        'name' => $name,
        'type' => 'modified',
        'risk' => $analysis->getRiskLevel(),
        'dependencies' => count($analysis->dependents),
        'config_type' => $analysis->configType,
      ];
    }

    return [
      '#theme' => 'config_guardian_dashboard',
      '#sync_status' => $sync_status,
      '#env_status' => $env_status,
      '#notable_changes' => $notable_changes,
      '#stats' => $stats,
      '#recent_snapshots' => $recent_snapshots,
      '#recent_activity' => $recent_activity,
      '#attached' => [
        'library' => ['config_guardian/config-guardian'],
      ],
    ];
  }

}
