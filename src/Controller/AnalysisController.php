<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\config_guardian\Service\ConfigAnalyzerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for impact analysis.
 */
class AnalysisController extends ControllerBase {

  /**
   * The config analyzer.
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * Constructs an AnalysisController object.
   */
  public function __construct(ConfigAnalyzerService $config_analyzer) {
    $this->configAnalyzer = $config_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.config_analyzer')
    );
  }

  /**
   * Displays the impact analysis page.
   *
   * @return array
   *   A render array.
   */
  public function analyze(): array {
    $pending_changes = $this->configAnalyzer->getPendingChanges();

    $all_changes = array_merge(
      $pending_changes['create'],
      $pending_changes['update'],
      $pending_changes['delete']
    );

    if (empty($all_changes)) {
      return [
        '#markup' => '<div class="messages messages--status">' .
        $this->t('No pending configuration changes to analyze.') . '</div>',
      ];
    }

    // Calculate risk assessment.
    $risk_assessment = $this->configAnalyzer->calculateRiskScore($all_changes);

    // Find conflicts.
    $conflicts = $this->configAnalyzer->findConflicts($all_changes);

    // Build dependency graph.
    $dependency_graph = $this->configAnalyzer->buildDependencyGraph($all_changes);

    // Analyze individual changes.
    $changes_with_analysis = [];

    foreach ($pending_changes['create'] as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $changes_with_analysis[] = [
        'name' => $name,
        'type' => 'create',
        'risk' => $analysis->getRiskLevel(),
        'config_type' => $analysis->configType,
        'dependents' => count($analysis->dependents),
        'impact_score' => $analysis->impactScore,
      ];
    }

    foreach ($pending_changes['update'] as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $changes_with_analysis[] = [
        'name' => $name,
        'type' => 'update',
        'risk' => $analysis->getRiskLevel(),
        'config_type' => $analysis->configType,
        'dependents' => count($analysis->dependents),
        'impact_score' => $analysis->impactScore,
      ];
    }

    foreach ($pending_changes['delete'] as $name) {
      $analysis = $this->configAnalyzer->analyzeConfig($name);
      $changes_with_analysis[] = [
        'name' => $name,
        'type' => 'delete',
        'risk' => $analysis->getRiskLevel(),
        'config_type' => $analysis->configType,
        'dependents' => count($analysis->dependents),
        'impact_score' => $analysis->impactScore,
      ];
    }

    // Sort by impact score descending.
    usort($changes_with_analysis, fn($a, $b) => $b['impact_score'] - $a['impact_score']);

    // Translate risk description.
    $risk_descriptions = [
      'low' => $this->t('Low risk - Changes are safe to apply.'),
      'medium' => $this->t('Medium risk - Review changes before applying.'),
      'high' => $this->t('High risk - Carefully review all changes.'),
      'critical' => $this->t('Critical risk - Consider creating a backup first.'),
    ];

    // Translate risk factors.
    $translated_factors = [];
    foreach ($risk_assessment->riskFactors as $factor) {
      $translated_factors[] = $this->translateRiskFactor($factor);
    }

    // Generate dynamic recommendations based on analysis.
    $recommendations = $this->generateRecommendations(
      $risk_assessment,
      $changes_with_analysis,
      $conflicts,
      $pending_changes
    );

    return [
      '#theme' => 'config_guardian_impact_analysis',
      '#risk_assessment' => [
        'score' => $risk_assessment->score,
        'level' => $risk_assessment->level,
        'description' => $risk_descriptions[$risk_assessment->level] ?? '',
        'factors' => $translated_factors,
      ],
      '#changes' => $changes_with_analysis,
      '#conflicts' => $conflicts,
      '#dependency_graph' => $dependency_graph,
      '#recommendations' => $recommendations,
      '#attached' => [
        'library' => ['config_guardian/config-guardian'],
        'drupalSettings' => [
          'configGuardian' => [
            'dependencyGraph' => $dependency_graph,
          ],
        ],
      ],
    ];
  }

  /**
   * Generates dynamic recommendations based on analysis.
   *
   * @param object $risk_assessment
   *   The risk assessment object.
   * @param array $changes
   *   The analyzed changes.
   * @param array $conflicts
   *   The detected conflicts.
   * @param array $pending_changes
   *   The pending changes by type.
   *
   * @return array
   *   Array of recommendations with priority and type.
   */
  protected function generateRecommendations($risk_assessment, array $changes, array $conflicts, array $pending_changes): array {
    $recommendations = [];
    $high_risk_changes = array_filter($changes, fn($c) => in_array($c['risk'], ['high', 'critical']));
    $delete_count = count($pending_changes['delete']);
    $total_changes = count($changes);

    // Critical: Always recommend snapshot for high/critical risk.
    if (in_array($risk_assessment->level, ['high', 'critical'])) {
      $recommendations[] = [
        'type' => 'critical',
        'icon' => 'shield',
        'message' => (string) $this->t('Create a backup snapshot before proceeding. This allows you to quickly rollback if something goes wrong.'),
        'action' => [
          'label' => (string) $this->t('Create Snapshot'),
          'url' => 'config_guardian.snapshot.add',
        ],
      ];
    }

    // Conflicts must be resolved first.
    if (!empty($conflicts)) {
      $recommendations[] = [
        'type' => 'error',
        'icon' => 'alert',
        'message' => (string) $this->t('You have @count conflict(s) that must be resolved before importing.', ['@count' => count($conflicts)]),
        'action' => NULL,
      ];
    }

    // Deletions warning.
    if ($delete_count > 0) {
      $recommendations[] = [
        'type' => 'warning',
        'icon' => 'trash',
        'message' => (string) $this->t('@count configuration(s) will be deleted. Verify these are intentional removals.', ['@count' => $delete_count]),
        'action' => NULL,
      ];
    }

    // High impact changes.
    if (count($high_risk_changes) > 0) {
      $config_names = array_slice(array_column($high_risk_changes, 'name'), 0, 3);
      $recommendations[] = [
        'type' => 'warning',
        'icon' => 'alert-triangle',
        'message' => (string) $this->t('@count high-risk change(s) detected: @configs. Review carefully before importing.', [
          '@count' => count($high_risk_changes),
          '@configs' => implode(', ', $config_names) . (count($high_risk_changes) > 3 ? '...' : ''),
        ]),
        'action' => NULL,
      ];
    }

    // Large batch recommendation.
    if ($total_changes > 50) {
      $recommendations[] = [
        'type' => 'info',
        'icon' => 'layers',
        'message' => (string) $this->t('Large import with @count changes. Consider testing on a staging environment first.', ['@count' => $total_changes]),
        'action' => NULL,
      ];
    }

    // Field storage changes.
    $field_storage_changes = array_filter($changes, fn($c) => str_starts_with($c['name'], 'field.storage.'));
    if (count($field_storage_changes) > 0) {
      $recommendations[] = [
        'type' => 'warning',
        'icon' => 'database',
        'message' => (string) $this->t('@count field storage change(s). These may affect existing content data.', ['@count' => count($field_storage_changes)]),
        'action' => NULL,
      ];
    }

    // Core extension changes.
    $core_ext_change = array_filter($changes, fn($c) => $c['name'] === 'core.extension');
    if (!empty($core_ext_change)) {
      $recommendations[] = [
        'type' => 'critical',
        'icon' => 'package',
        'message' => (string) $this->t('Module list will be modified. This may install or uninstall modules.'),
        'action' => NULL,
      ];
    }

    // If low risk and no issues, give positive feedback.
    if ($risk_assessment->level === 'low' && empty($conflicts) && $delete_count === 0) {
      $recommendations[] = [
        'type' => 'success',
        'icon' => 'check',
        'message' => (string) $this->t('All changes appear safe to import. No critical issues detected.'),
        'action' => NULL,
      ];
    }

    return $recommendations;
  }

  /**
   * Translates a risk factor message.
   *
   * @param string $factor
   *   The risk factor in English.
   *
   * @return string
   *   The translated risk factor.
   */
  protected function translateRiskFactor(string $factor): string {
    // Pattern-based translations.
    if (preg_match('/^(.+) has (\d+) dependents$/', $factor, $matches)) {
      return (string) $this->t('@config has @count dependents', [
        '@config' => $matches[1],
        '@count' => $matches[2],
      ]);
    }

    if (preg_match('/^Modifying core\.extension \(module list\)$/', $factor)) {
      return (string) $this->t('Modifying core.extension (module list)');
    }

    if (preg_match('/^Modifying field storage: (.+)$/', $factor, $matches)) {
      return (string) $this->t('Modifying field storage: @config', ['@config' => $matches[1]]);
    }

    if (preg_match('/^Modifying content type: (.+)$/', $factor, $matches)) {
      return (string) $this->t('Modifying content type: @config', ['@config' => $matches[1]]);
    }

    if (preg_match('/^Modifying user role: (.+)$/', $factor, $matches)) {
      return (string) $this->t('Modifying user role: @config', ['@config' => $matches[1]]);
    }

    if (preg_match('/^Large number of changes \((\d+) configurations\)$/', $factor, $matches)) {
      return (string) $this->t('Large number of changes (@count configurations)', ['@count' => $matches[1]]);
    }

    if (preg_match('/^Significant number of changes \((\d+) configurations\)$/', $factor, $matches)) {
      return (string) $this->t('Significant number of changes (@count configurations)', ['@count' => $matches[1]]);
    }

    // Return as-is if no pattern matches.
    return $factor;
  }

}
