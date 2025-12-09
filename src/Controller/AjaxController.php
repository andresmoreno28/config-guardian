<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\config_guardian\Service\ConfigAnalyzerService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for AJAX endpoints.
 */
class AjaxController extends ControllerBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The config analyzer.
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * Constructs an AjaxController object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ConfigAnalyzerService $config_analyzer,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->configAnalyzer = $config_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.config_analyzer')
    );
  }

  /**
   * Returns the current sync status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function status(): JsonResponse {
    $pending = $this->configAnalyzer->getPendingChanges();
    $total = count($pending['create']) + count($pending['update']) + count($pending['delete']);

    if ($total === 0) {
      $status = 'synced';
      $message = $this->t('Configuration is in sync.');
    }
    else {
      $all_changes = array_merge($pending['create'], $pending['update']);
      $conflicts = $this->configAnalyzer->findConflicts($all_changes);

      if (!empty($conflicts)) {
        $status = 'conflict';
        $message = $this->t('@count conflicts detected.', ['@count' => count($conflicts)]);
      }
      else {
        $status = 'pending';
        $message = $this->t('@count pending changes.', ['@count' => $total]);
      }
    }

    return new JsonResponse([
      'status' => $status,
      'message' => (string) $message,
      'counts' => [
        'create' => count($pending['create']),
        'update' => count($pending['update']),
        'delete' => count($pending['delete']),
      ],
    ]);
  }

  /**
   * Returns the dependency graph data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with graph data.
   */
  public function dependencyGraph(): JsonResponse {
    $graph = $this->configAnalyzer->buildDependencyGraph();
    return new JsonResponse($graph);
  }

  /**
   * Returns pending changes with analysis.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with pending changes.
   */
  public function pendingChanges(): JsonResponse {
    $pending = $this->configAnalyzer->getPendingChanges();

    $changes = [];

    foreach ($pending['create'] as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $changes[] = [
        'name' => $name,
        'type' => 'new',
        'risk' => $analysis->getRiskLevel(),
        'dependents' => count($analysis->dependents),
      ];
    }

    foreach ($pending['update'] as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $changes[] = [
        'name' => $name,
        'type' => 'modified',
        'risk' => $analysis->getRiskLevel(),
        'dependents' => count($analysis->dependents),
      ];
    }

    foreach ($pending['delete'] as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $changes[] = [
        'name' => $name,
        'type' => 'deleted',
        'risk' => $analysis->getRiskLevel(),
        'dependents' => count($analysis->dependents),
      ];
    }

    return new JsonResponse($changes);
  }

}
