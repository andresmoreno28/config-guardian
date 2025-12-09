<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Model;

/**
 * Represents a risk assessment for configuration changes.
 */
class RiskAssessment {

  /**
   * The risk score (0-100).
   *
   * @var int
   */
  public int $score = 0;

  /**
   * The risk level: low, medium, high, critical.
   *
   * @var string
   */
  public string $level = 'low';

  /**
   * List of risk factors identified.
   *
   * @var array
   */
  public array $riskFactors = [];

  /**
   * Gets a human-readable description.
   *
   * @return string
   *   The description.
   */
  public function getDescription(): string {
    $descriptions = [
      'low' => 'Low risk - Changes are safe to apply.',
      'medium' => 'Medium risk - Review changes before applying.',
      'high' => 'High risk - Carefully review all changes.',
      'critical' => 'Critical risk - Consider creating a backup first.',
    ];

    return $descriptions[$this->level] ?? '';
  }

}
