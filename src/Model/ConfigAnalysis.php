<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Model;

/**
 * Represents the analysis of a configuration item.
 */
class ConfigAnalysis {

  /**
   * The configuration name.
   *
   * @var string
   */
  public string $configName = '';

  /**
   * Dependencies grouped by type.
   *
   * @var array
   */
  public array $dependencies = [];

  /**
   * Configurations that depend on this one.
   *
   * @var array
   */
  public array $dependents = [];

  /**
   * The configuration type.
   *
   * @var string
   */
  public string $configType = '';

  /**
   * The impact score (0-100).
   *
   * @var int
   */
  public int $impactScore = 0;

  /**
   * The owner module.
   *
   * @var string
   */
  public string $ownerModule = '';

  /**
   * Gets the risk level based on impact score.
   *
   * @return string
   *   The risk level: low, medium, high, or critical.
   */
  public function getRiskLevel(): string {
    if ($this->impactScore >= 75) {
      return 'critical';
    }
    if ($this->impactScore >= 50) {
      return 'high';
    }
    if ($this->impactScore >= 25) {
      return 'medium';
    }
    return 'low';
  }

}
