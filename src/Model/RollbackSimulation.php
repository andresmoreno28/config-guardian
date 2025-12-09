<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Model;

/**
 * Represents a simulation of a rollback operation.
 */
class RollbackSimulation {

  /**
   * Whether the simulation is valid.
   *
   * @var bool
   */
  public bool $valid = FALSE;

  /**
   * Error message if not valid.
   *
   * @var string
   */
  public string $error = '';

  /**
   * Configurations to be created.
   *
   * @var array
   */
  public array $toCreate = [];

  /**
   * Configurations to be updated.
   *
   * @var array
   */
  public array $toUpdate = [];

  /**
   * Configurations to be deleted.
   *
   * @var array
   */
  public array $toDelete = [];

  /**
   * Configurations to be renamed.
   *
   * @var array
   */
  public array $toRename = [];

  /**
   * Total number of changes (active + sync).
   *
   * @var int
   */
  public int $totalChanges = 0;

  /**
   * Number of active config changes.
   *
   * @var int
   */
  public int $activeChanges = 0;

  /**
   * Number of sync directory changes.
   *
   * @var int
   */
  public int $syncChanges = 0;

  /**
   * Sync configurations to be created.
   *
   * @var array
   */
  public array $syncToCreate = [];

  /**
   * Sync configurations to be updated.
   *
   * @var array
   */
  public array $syncToUpdate = [];

  /**
   * Sync configurations to be deleted.
   *
   * @var array
   */
  public array $syncToDelete = [];

  /**
   * Risk assessment.
   *
   * @var \Drupal\config_guardian\Model\RiskAssessment|null
   */
  public ?RiskAssessment $riskAssessment = NULL;

  /**
   * Checks if there are any changes.
   *
   * @return bool
   *   TRUE if there are changes.
   */
  public function hasChanges(): bool {
    return $this->totalChanges > 0;
  }

}
