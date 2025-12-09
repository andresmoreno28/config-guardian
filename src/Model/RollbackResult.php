<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Model;

/**
 * Represents the result of a rollback operation.
 */
class RollbackResult {

  /**
   * The snapshot ID that was rolled back to.
   *
   * @var int
   */
  public int $snapshotId = 0;

  /**
   * Whether the rollback was successful.
   *
   * @var bool
   */
  public bool $success = FALSE;

  /**
   * Success or error message.
   *
   * @var string
   */
  public string $message = '';

  /**
   * Error message if failed.
   *
   * @var string
   */
  public string $error = '';

  /**
   * List of errors encountered.
   *
   * @var array
   */
  public array $errors = [];

  /**
   * Changes that were applied.
   *
   * @var array
   */
  public array $changesApplied = [];

  /**
   * ID of the pre-rollback snapshot created.
   *
   * @var int|null
   */
  public ?int $preRollbackSnapshotId = NULL;

  /**
   * Start timestamp.
   *
   * @var int
   */
  public int $startTime = 0;

  /**
   * End timestamp.
   *
   * @var int
   */
  public int $endTime = 0;

  /**
   * Duration in seconds.
   *
   * @var int
   */
  public int $duration = 0;

  /**
   * Gets the total number of changes applied.
   *
   * @return int
   *   The total changes.
   */
  public function getTotalChanges(): int {
    $total = 0;
    foreach ($this->changesApplied as $changes) {
      $total += count($changes);
    }
    return $total;
  }

}
