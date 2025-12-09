<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\config_guardian\Service\ConfigAnalyzerService;
use Drupal\config_guardian\Service\RollbackEngineService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Config Guardian.
 */
class ConfigGuardianCommands extends DrushCommands {

  /**
   * The snapshot manager service.
   *
   * @var \Drupal\config_guardian\Service\SnapshotManagerService
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The config analyzer service.
   *
   * @var \Drupal\config_guardian\Service\ConfigAnalyzerService
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * The rollback engine service.
   *
   * @var \Drupal\config_guardian\Service\RollbackEngineService
   */
  protected RollbackEngineService $rollbackEngine;

  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ConfigAnalyzerService $config_analyzer,
    RollbackEngineService $rollback_engine,
  ) {
    parent::__construct();
    $this->snapshotManager = $snapshot_manager;
    $this->configAnalyzer = $config_analyzer;
    $this->rollbackEngine = $rollback_engine;
  }

  /**
   * Creates a new configuration snapshot.
   *
   * @command config-guardian:snapshot
   * @aliases cg-snap,cgsnap
   */
  public function createSnapshot(string $name, array $options = ['type' => 'manual', 'description' => NULL]): void {
    try {
      $snapshot = $this->snapshotManager->createSnapshot($name, $options['type'], ['description' => $options['description']]);
      $this->logger()->success(dt('Snapshot "@name" created with ID @id (@count configurations)', [
        '@name' => $name,
        '@id' => $snapshot['id'],
        '@count' => $snapshot['config_count'],
      ]));
    }
    catch (\Exception $e) {
      throw new \Exception('Failed to create snapshot: ' . $e->getMessage());
    }
  }

  /**
   * Lists all available snapshots.
   *
   * @command config-guardian:list
   * @aliases cg-list,cglist
   * @field-labels
   *   id: ID
   *   name: Name
   *   type: Type
   *   created: Created
   *   configs: Configs
   * @default-fields id,name,type,created,configs
   */
  public function listSnapshots(array $options = ['limit' => 20, 'type' => NULL, 'format' => 'table']): RowsOfFields {
    $filters = [];
    if ($options['type']) {
      $filters['type'] = $options['type'];
    }
    $snapshots = $this->snapshotManager->getSnapshotList($filters, (int) $options['limit']);
    $rows = [];
    foreach ($snapshots as $snapshot) {
      $ts = (int) $snapshot['created'];
      $rows[] = [
        'id' => $snapshot['id'],
        'name' => $snapshot['name'],
        'type' => $snapshot['type'],
        'created' => date('Y-m-d H:i', $ts),
        'configs' => $snapshot['config_count'],
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Rolls back to a specific snapshot.
   *
   * @command config-guardian:rollback
   * @aliases cg-rollback,cgroll
   * @option no-backup Skip creating a backup snapshot before rollback.
   */
  public function rollback(
    int $snapshot_id,
    array $options = [
      'dry-run' => FALSE,
      'force' => FALSE,
      'no-backup' => FALSE,
    ],
  ): void {
    $simulation = $this->rollbackEngine->simulateRollback($snapshot_id);
    if (!$simulation->valid) {
      throw new \Exception($simulation->error);
    }
    $this->io()->section('Rollback Summary');
    $this->io()->text([
      'Configs to create: ' . count($simulation->toCreate),
      'Configs to update: ' . count($simulation->toUpdate),
      'Configs to delete: ' . count($simulation->toDelete),
    ]);
    if ($simulation->riskAssessment) {
      $this->io()->text([
        'Risk level: ' . strtoupper($simulation->riskAssessment->level),
        'Risk score: ' . $simulation->riskAssessment->score . '/100',
      ]);
    }
    if ($options['dry-run']) {
      $this->logger()->notice('Dry-run mode - no changes applied');
      return;
    }
    if ($simulation->totalChanges === 0) {
      $this->logger()->notice('No changes needed - already at snapshot state');
      return;
    }
    if ($options['no-backup']) {
      $this->io()->warning('Backup snapshot will NOT be created. You will not be able to undo this rollback.');
    }
    if (!$options['force'] && !$this->io()->confirm('Proceed with rollback?')) {
      throw new \Exception('Rollback cancelled by user');
    }
    $create_backup = !$options['no-backup'];
    $result = $this->rollbackEngine->rollbackToSnapshot($snapshot_id, ['create_backup' => $create_backup]);
    if ($result->success) {
      $this->logger()->success(dt('Rollback completed in @time seconds. @changes changes applied.', [
        '@time' => $result->duration,
        '@changes' => $result->getTotalChanges(),
      ]));
      if ($result->preRollbackSnapshotId) {
        $this->logger()->notice(dt('Backup snapshot created with ID @id', ['@id' => $result->preRollbackSnapshotId]));
      }
    }
    else {
      throw new \Exception('Rollback failed: ' . $result->error);
    }
  }

  /**
   * Analyzes impact of pending configuration changes.
   *
   * @command config-guardian:analyze
   * @aliases cg-analyze,cganal
   */
  public function analyzeImpact(array $options = ['format' => 'table']): void {
    $pending = $this->configAnalyzer->getPendingChanges();
    $changes = array_merge($pending['create'], $pending['update'], $pending['delete']);
    if (empty($changes)) {
      $this->logger()->notice('No pending configuration changes');
      return;
    }
    $risk = $this->configAnalyzer->calculateRiskScore($changes);
    $this->io()->section('Impact Analysis');
    $this->io()->text([
      'Total changes: ' . count($changes),
      '  - New: ' . count($pending['create']),
      '  - Modified: ' . count($pending['update']),
      '  - Deleted: ' . count($pending['delete']),
      '',
      'Risk score: ' . $risk->score . '/100',
      'Risk level: ' . strtoupper($risk->level),
    ]);
    if (!empty($risk->riskFactors)) {
      $this->io()->section('Risk Factors');
      foreach ($risk->riskFactors as $factor) {
        $this->io()->text('  - ' . $factor);
      }
    }
    $conflicts = $this->configAnalyzer->findConflicts($changes);
    if (!empty($conflicts)) {
      $this->io()->section('Conflicts Detected');
      foreach ($conflicts as $conflict) {
        $this->io()->warning($conflict['config'] . ': ' . $conflict['details']);
      }
    }
  }

  /**
   * Shows differences between two snapshots.
   *
   * @command config-guardian:diff
   * @aliases cg-diff,cgdiff
   */
  public function diffSnapshots(int $id1, int $id2): void {
    $diff = $this->snapshotManager->compareSnapshots($id1, $id2);
    $this->io()->section("Diff: Snapshot #$id1 -> Snapshot #$id2");
    if (!$diff->hasDifferences()) {
      $this->io()->text('No differences found');
      return;
    }
    if (!empty($diff->added)) {
      $this->io()->text('Added (' . count($diff->added) . '):');
      foreach ($diff->added as $name) {
        $this->io()->text("  + $name");
      }
    }
    if (!empty($diff->removed)) {
      $this->io()->text('Removed (' . count($diff->removed) . '):');
      foreach ($diff->removed as $name) {
        $this->io()->text("  - $name");
      }
    }
    if (!empty($diff->modified)) {
      $this->io()->text('Modified (' . count($diff->modified) . '):');
      foreach (array_keys($diff->modified) as $name) {
        $this->io()->text("  ~ $name");
      }
    }
  }

  /**
   * Exports a snapshot to a JSON file.
   *
   * @command config-guardian:export
   * @aliases cg-export
   */
  public function exportSnapshot(int $snapshot_id, string $path): void {
    $data = $this->snapshotManager->exportSnapshot($snapshot_id);
    if (!$data) {
      throw new \Exception('Snapshot not found');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path, $json) === FALSE) {
      throw new \Exception('Failed to write file');
    }
    $this->logger()->success(dt('Snapshot exported to @path', ['@path' => $path]));
  }

  /**
   * Deletes a snapshot.
   *
   * @command config-guardian:delete
   * @aliases cg-delete
   */
  public function deleteSnapshot(int $snapshot_id, array $options = ['force' => FALSE]): void {
    $snapshot = $this->snapshotManager->loadSnapshot($snapshot_id);
    if (!$snapshot) {
      throw new \Exception('Snapshot not found');
    }
    if (!$options['force'] && !$this->io()->confirm(dt('Delete snapshot "@name" (#@id)?', [
      '@name' => $snapshot['name'],
      '@id' => $snapshot_id,
    ]))) {
      throw new \Exception('Deletion cancelled');
    }
    if ($this->snapshotManager->deleteSnapshot($snapshot_id)) {
      $this->logger()->success(dt('Snapshot #@id deleted', ['@id' => $snapshot_id]));
    }
    else {
      throw new \Exception('Failed to delete snapshot');
    }
  }

}
