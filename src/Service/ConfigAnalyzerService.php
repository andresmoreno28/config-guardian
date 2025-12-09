<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\config_guardian\Model\ConfigAnalysis;
use Drupal\config_guardian\Model\RiskAssessment;

/**
 * Service for analyzing configuration dependencies and impact.
 */
class ConfigAnalyzerService {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The active config storage.
   */
  protected StorageInterface $activeStorage;

  /**
   * The sync config storage.
   */
  protected StorageInterface $syncStorage;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The config manager.
   */
  protected ConfigManagerInterface $configManager;

  /**
   * Constructs a ConfigAnalyzerService object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StorageInterface $active_storage,
    StorageInterface $sync_storage,
    ModuleHandlerInterface $module_handler,
    ConfigManagerInterface $config_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->activeStorage = $active_storage;
    $this->syncStorage = $sync_storage;
    $this->moduleHandler = $module_handler;
    $this->configManager = $config_manager;
  }

  /**
   * Analyzes a configuration and returns detailed information.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return \Drupal\config_guardian\Model\ConfigAnalysis
   *   The analysis result.
   */
  public function analyzeConfig(string $config_name): ConfigAnalysis {
    $analysis = new ConfigAnalysis();
    $analysis->configName = $config_name;

    // Get direct dependencies.
    $analysis->dependencies = $this->getDependencies($config_name);

    // Get dependents (what depends on this).
    $analysis->dependents = $this->getDependents($config_name);

    // Determine config type.
    $analysis->configType = $this->getConfigType($config_name);

    // Calculate impact score.
    $analysis->impactScore = $this->calculateImpactScore($config_name);

    // Get owner module.
    $analysis->ownerModule = $this->getOwnerModule($config_name);

    return $analysis;
  }

  /**
   * Gets all dependencies of a configuration.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return array
   *   The dependencies grouped by type.
   */
  public function getDependencies(string $config_name): array {
    $data = $this->activeStorage->read($config_name);

    $dependencies = [
      'config' => [],
      'module' => [],
      'theme' => [],
      'content' => [],
    ];

    if ($data && isset($data['dependencies'])) {
      $dependencies = array_merge($dependencies, $data['dependencies']);
    }

    return $dependencies;
  }

  /**
   * Gets all configurations that depend on the specified one.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return array
   *   List of dependent configuration names.
   */
  public function getDependents(string $config_name): array {
    $all_config = $this->activeStorage->listAll();
    $dependents = [];

    foreach ($all_config as $name) {
      $deps = $this->getDependencies($name);
      if (in_array($config_name, $deps['config'] ?? [])) {
        $dependents[] = $name;
      }
    }

    return $dependents;
  }

  /**
   * Gets the configuration type.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return string
   *   The configuration type.
   */
  public function getConfigType(string $config_name): string {
    $parts = explode('.', $config_name);

    if (count($parts) >= 2) {
      $prefix = $parts[0] . '.' . $parts[1];

      $types = [
        'field.storage' => 'Field Storage',
        'field.field' => 'Field Instance',
        'node.type' => 'Content Type',
        'taxonomy.vocabulary' => 'Vocabulary',
        'views.view' => 'View',
        'block.block' => 'Block',
        'system.menu' => 'Menu',
        'user.role' => 'User Role',
        'image.style' => 'Image Style',
        'filter.format' => 'Text Format',
      ];

      if (isset($types[$prefix])) {
        return $types[$prefix];
      }
    }

    if (str_starts_with($config_name, 'core.')) {
      return 'Core';
    }

    if (str_starts_with($config_name, 'system.')) {
      return 'System';
    }

    return 'Configuration';
  }

  /**
   * Gets the owner module of a configuration.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return string
   *   The module name.
   */
  public function getOwnerModule(string $config_name): string {
    $parts = explode('.', $config_name);
    $module = $parts[0] ?? 'unknown';

    // Special handling for core configurations.
    if (in_array($module, ['core', 'system', 'field', 'node', 'user', 'taxonomy', 'views', 'block'])) {
      return 'core';
    }

    return $this->moduleHandler->moduleExists($module) ? $module : 'unknown';
  }

  /**
   * Calculates the impact score for a configuration.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return int
   *   The impact score (0-100).
   */
  protected function calculateImpactScore(string $config_name): int {
    $score = 0;

    // Factor: number of dependents.
    $dependents = $this->getDependents($config_name);
    $dependent_count = count($dependents);

    if ($dependent_count > 20) {
      $score += 30;
    }
    elseif ($dependent_count > 10) {
      $score += 20;
    }
    elseif ($dependent_count > 5) {
      $score += 10;
    }
    elseif ($dependent_count > 0) {
      $score += 5;
    }

    // Factor: configuration type.
    if (str_starts_with($config_name, 'field.storage.')) {
      $score += 25;
    }
    elseif (str_starts_with($config_name, 'core.extension')) {
      $score += 40;
    }
    elseif (str_starts_with($config_name, 'node.type.')) {
      $score += 15;
    }
    elseif (str_starts_with($config_name, 'user.role.')) {
      $score += 15;
    }

    // Factor: core module.
    if ($this->getOwnerModule($config_name) === 'core') {
      $score += 10;
    }

    return min(100, $score);
  }

  /**
   * Calculates the risk score for a set of configurations.
   *
   * Uses a weighted average approach:
   * - Base score is the average impact score of all configurations
   * - Critical configurations (core.extension, field.storage) add bonus points
   * - Volume of changes adds a small modifier.
   *
   * @param array $config_names
   *   List of configuration names.
   *
   * @return \Drupal\config_guardian\Model\RiskAssessment
   *   The risk assessment.
   */
  public function calculateRiskScore(array $config_names): RiskAssessment {
    $assessment = new RiskAssessment();

    if (empty($config_names)) {
      $assessment->score = 0;
      $assessment->level = 'low';
      return $assessment;
    }

    $risk_factors = [];
    $individual_scores = [];
    $critical_configs = 0;
    $high_impact_configs = 0;

    foreach ($config_names as $config_name) {
      $analysis = $this->analyzeConfig($config_name);
      $config_score = 0;

      // Base score from dependents (0-40 points).
      $dependent_count = count($analysis->dependents);
      if ($dependent_count > 20) {
        $config_score += 40;
        $risk_factors[] = "$config_name has $dependent_count dependents";
      }
      elseif ($dependent_count > 10) {
        $config_score += 30;
        $risk_factors[] = "$config_name has $dependent_count dependents";
      }
      elseif ($dependent_count > 5) {
        $config_score += 20;
      }
      elseif ($dependent_count > 0) {
        $config_score += 10;
      }

      // Critical configuration types (add to score and track).
      if ($config_name === 'core.extension') {
        $config_score += 50;
        $critical_configs++;
        $risk_factors[] = "Modifying core.extension (module list)";
      }
      elseif (str_starts_with($config_name, 'field.storage.')) {
        $config_score += 40;
        $high_impact_configs++;
        $risk_factors[] = "Modifying field storage: $config_name";
      }
      elseif (str_starts_with($config_name, 'node.type.')) {
        $config_score += 25;
        $high_impact_configs++;
        $risk_factors[] = "Modifying content type: $config_name";
      }
      elseif (str_starts_with($config_name, 'user.role.')) {
        $config_score += 20;
        $risk_factors[] = "Modifying user role: $config_name";
      }
      elseif (str_starts_with($config_name, 'system.') || str_starts_with($config_name, 'core.')) {
        $config_score += 10;
      }

      $individual_scores[] = min(100, $config_score);
    }

    // Calculate weighted average of individual scores.
    $avg_score = array_sum($individual_scores) / count($individual_scores);

    // Apply volume modifier (many changes = slightly higher risk).
    $count = count($config_names);
    $volume_modifier = 1.0;
    if ($count > 50) {
      $volume_modifier = 1.15;
      $risk_factors[] = "Large number of changes ($count configurations)";
    }
    elseif ($count > 20) {
      $volume_modifier = 1.10;
      $risk_factors[] = "Significant number of changes ($count configurations)";
    }
    elseif ($count > 10) {
      $volume_modifier = 1.05;
    }

    // Apply critical config bonus (having critical configs increases overall risk).
    $critical_bonus = 0;
    if ($critical_configs > 0) {
      $critical_bonus = min(20, $critical_configs * 15);
    }
    if ($high_impact_configs > 0) {
      $critical_bonus += min(15, $high_impact_configs * 5);
    }

    // Final score calculation.
    $final_score = ($avg_score * $volume_modifier) + $critical_bonus;
    $assessment->score = (int) min(100, max(0, round($final_score)));
    $assessment->riskFactors = array_unique($risk_factors);

    // Determine risk level based on final score.
    if ($assessment->score >= 70) {
      $assessment->level = 'critical';
    }
    elseif ($assessment->score >= 45) {
      $assessment->level = 'high';
    }
    elseif ($assessment->score >= 20) {
      $assessment->level = 'medium';
    }
    else {
      $assessment->level = 'low';
    }

    return $assessment;
  }

  /**
   * Detects potential conflicts in the configuration to import.
   *
   * @param array $config_names
   *   List of configuration names.
   *
   * @return array
   *   List of conflicts.
   */
  public function findConflicts(array $config_names): array {
    $conflicts = [];

    foreach ($config_names as $config_name) {
      // Skip if it doesn't exist in active storage.
      if (!$this->activeStorage->exists($config_name)) {
        continue;
      }

      // Get dependencies.
      $sync_data = $this->syncStorage->read($config_name);
      if (!$sync_data) {
        continue;
      }

      $deps = $sync_data['dependencies'] ?? [];

      // Check for missing modules.
      foreach ($deps['module'] ?? [] as $module) {
        if (!$this->moduleHandler->moduleExists($module)) {
          $conflicts[] = [
            'config' => $config_name,
            'type' => 'missing_module',
            'details' => "Requires module '$module' which is not installed",
            'severity' => 'error',
          ];
        }
      }

      // Check for missing config dependencies.
      foreach ($deps['config'] ?? [] as $dep_config) {
        if (!in_array($dep_config, $config_names) && !$this->activeStorage->exists($dep_config)) {
          $conflicts[] = [
            'config' => $config_name,
            'type' => 'missing_dependency',
            'details' => "Depends on '$dep_config' which does not exist",
            'severity' => 'warning',
          ];
        }
      }
    }

    return $conflicts;
  }

  /**
   * Gets pending configuration changes (import direction only).
   *
   * @return array
   *   Array with create, update, delete keys.
   *
   * @deprecated in config_guardian:1.1.0 and is removed from config_guardian:2.0.0.
   *   Use getEnvironmentStatus() for complete analysis.
   * @see \Drupal\config_guardian\Service\ConfigAnalyzerService::getEnvironmentStatus()
   */
  public function getPendingChanges(): array {
    $changes = [
      'create' => [],
      'update' => [],
      'delete' => [],
    ];

    $active_names = $this->activeStorage->listAll();
    $sync_names = $this->syncStorage->listAll();

    // Find new configs (in sync but not in active).
    $changes['create'] = array_diff($sync_names, $active_names);

    // Find deleted configs (in active but not in sync).
    $changes['delete'] = array_diff($active_names, $sync_names);

    // Find modified configs.
    $common = array_intersect($active_names, $sync_names);
    foreach ($common as $name) {
      $active_data = $this->activeStorage->read($name);
      $sync_data = $this->syncStorage->read($name);
      if ($active_data !== $sync_data) {
        $changes['update'][] = $name;
      }
    }

    return $changes;
  }

  /**
   * Gets a comprehensive environment status with bidirectional analysis.
   *
   * This method analyzes both import (sync→active) and export (active→sync)
   * directions and provides intelligent recommendations.
   *
   * @return array
   *   Comprehensive status with:
   *   - status: 'synced', 'needs_export', 'needs_import', 'diverged'
   *   - active_count: Number of active configs
   *   - sync_count: Number of sync configs
   *   - import: What importing would do (sync→active)
   *   - export: What exporting would do (active→sync)
   *   - recommendation: 'import', 'export', 'review', 'none'
   *   - warnings: Array of warning messages
   *   - modified: Configs that differ between active and sync
   */
  public function getEnvironmentStatus(): array {
    $active_names = $this->activeStorage->listAll();
    $sync_names = $this->syncStorage->listAll();
    $active_count = count($active_names);
    $sync_count = count($sync_names);

    // Calculate what's only in each storage.
    $only_in_active = array_values(array_diff($active_names, $sync_names));
    $only_in_sync = array_values(array_diff($sync_names, $active_names));

    // Find modified configs (exist in both but differ).
    $modified = [];
    $common = array_intersect($active_names, $sync_names);
    foreach ($common as $name) {
      $active_data = $this->activeStorage->read($name);
      $sync_data = $this->syncStorage->read($name);
      if ($active_data !== $sync_data) {
        $modified[] = $name;
      }
    }

    // Build import analysis (sync → active).
    $import = [
      'create' => $only_in_sync,
      'update' => $modified,
      'delete' => $only_in_active,
      'total' => count($only_in_sync) + count($modified) + count($only_in_active),
    ];

    // Build export analysis (active → sync).
    $export = [
      'create' => $only_in_active,
      'update' => $modified,
      'delete' => $only_in_sync,
      'total' => count($only_in_active) + count($modified) + count($only_in_sync),
    ];

    // Determine status and recommendation.
    $warnings = [];
    $status = 'synced';
    $recommendation = 'none';

    if ($import['total'] === 0 && $export['total'] === 0) {
      $status = 'synced';
      $recommendation = 'none';
    }
    elseif ($sync_count === 0 && $active_count > 0) {
      // Sync directory is empty but active has configs.
      $status = 'needs_export';
      $recommendation = 'export';
      $warnings[] = [
        'type' => 'info',
        'message' => 'El directorio sync está vacío. Exporta la configuración activa para inicializarlo.',
      ];
    }
    elseif ($active_count === 0 && $sync_count > 0) {
      // Active is empty but sync has configs (fresh install).
      $status = 'needs_import';
      $recommendation = 'import';
      $warnings[] = [
        'type' => 'info',
        'message' => 'El sitio no tiene configuración activa. Importa desde sync para configurar el sitio.',
      ];
    }
    elseif (count($only_in_active) > 0 && count($only_in_sync) === 0 && empty($modified)) {
      // Only active has extra configs, sync is subset.
      $status = 'needs_export';
      $recommendation = 'export';
      $warnings[] = [
        'type' => 'info',
        'message' => 'Hay ' . count($only_in_active) . ' configuraciones nuevas en active que no están en sync.',
      ];
    }
    elseif (count($only_in_sync) > 0 && count($only_in_active) === 0 && empty($modified)) {
      // Only sync has extra configs.
      $status = 'needs_import';
      $recommendation = 'import';
      $warnings[] = [
        'type' => 'info',
        'message' => 'Hay ' . count($only_in_sync) . ' configuraciones en sync pendientes de importar.',
      ];
    }
    elseif (!empty($modified) && count($only_in_active) === 0 && count($only_in_sync) === 0) {
      // Only modifications, no new/deleted.
      $status = 'diverged';
      $recommendation = 'review';
      $warnings[] = [
        'type' => 'warning',
        'message' => 'Hay ' . count($modified) . ' configuraciones modificadas. Revisa los cambios antes de sincronizar.',
      ];
    }
    else {
      // Complex situation: changes in both directions.
      $status = 'diverged';
      $recommendation = 'review';
      $warnings[] = [
        'type' => 'warning',
        'message' => 'El entorno ha divergido: hay cambios en ambas direcciones.',
      ];

      // Add specific warnings for destructive operations.
      if (count($import['delete']) > 10) {
        $warnings[] = [
          'type' => 'danger',
          'message' => 'CUIDADO: Importar eliminaría ' . count($import['delete']) . ' configuraciones activas.',
        ];
      }
      if (count($export['delete']) > 10) {
        $warnings[] = [
          'type' => 'danger',
          'message' => 'CUIDADO: Exportar eliminaría ' . count($export['delete']) . ' archivos de sync.',
        ];
      }
    }

    return [
      'status' => $status,
      'active_count' => $active_count,
      'sync_count' => $sync_count,
      'import' => $import,
      'export' => $export,
      'modified' => $modified,
      'recommendation' => $recommendation,
      'warnings' => $warnings,
    ];
  }

  /**
   * Builds a dependency graph for visualization.
   *
   * @param array $config_names
   *   Optional list of config names to focus on.
   *
   * @return array
   *   Graph data with nodes and links.
   */
  public function buildDependencyGraph(array $config_names = []): array {
    if (empty($config_names)) {
      // Get pending changes.
      $changes = $this->getPendingChanges();
      $config_names = array_merge(
        $changes['create'],
        $changes['update'],
        $changes['delete']
      );
    }

    $nodes = [];
    $links = [];
    $node_ids = [];

    // Add nodes for specified configs.
    foreach ($config_names as $name) {
      $analysis = $this->analyzeConfig($name);
      $node_id = count($nodes);
      $node_ids[$name] = $node_id;

      $risk = 'low';
      if ($analysis->impactScore >= 75) {
        $risk = 'critical';
      }
      elseif ($analysis->impactScore >= 50) {
        $risk = 'high';
      }
      elseif ($analysis->impactScore >= 25) {
        $risk = 'medium';
      }

      $nodes[] = [
        'id' => $node_id,
        'name' => $name,
        'type' => $analysis->configType,
        'risk' => $risk,
        'impactScore' => $analysis->impactScore,
      ];

      // Add dependent nodes and links.
      foreach ($analysis->dependents as $dependent) {
        if (!isset($node_ids[$dependent])) {
          $dep_analysis = $this->analyzeConfig($dependent);
          $dep_node_id = count($nodes);
          $node_ids[$dependent] = $dep_node_id;

          $nodes[] = [
            'id' => $dep_node_id,
            'name' => $dependent,
            'type' => $dep_analysis->configType,
            'risk' => 'low',
            'impactScore' => $dep_analysis->impactScore,
          ];
        }

        $links[] = [
          'source' => $node_ids[$name],
          'target' => $node_ids[$dependent],
        ];
      }
    }

    return [
      'nodes' => $nodes,
      'links' => $links,
    ];
  }

}
