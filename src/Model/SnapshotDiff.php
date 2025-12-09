<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Model;

/**
 * Represents the difference between two snapshots.
 */
class SnapshotDiff {

  /**
   * Added configuration names.
   *
   * @var array
   */
  public array $added = [];

  /**
   * Removed configuration names.
   *
   * @var array
   */
  public array $removed = [];

  /**
   * Modified configurations with before/after data.
   *
   * @var array
   */
  public array $modified = [];

  /**
   * Checks if there are any differences.
   *
   * @return bool
   *   TRUE if there are differences.
   */
  public function hasDifferences(): bool {
    return !empty($this->added) || !empty($this->removed) || !empty($this->modified);
  }

  /**
   * Gets the total number of changes.
   *
   * @return int
   *   The total number of changes.
   */
  public function getTotalChanges(): int {
    return count($this->added) + count($this->removed) + count($this->modified);
  }

}
