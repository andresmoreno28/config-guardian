<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\config_guardian\Batch\ConfigImportBatch;
use Drupal\config_guardian\Service\ConfigSyncService;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing configuration from sync directory.
 */
class ConfigImportForm extends ConfirmFormBase {

  /**
   * The config sync service.
   */
  protected ConfigSyncService $configSync;

  /**
   * The import preview data.
   */
  protected array $preview;

  /**
   * Constructs a ConfigImportForm object.
   */
  public function __construct(ConfigSyncService $config_sync) {
    $this->configSync = $config_sync;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.config_sync')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Import configuration from sync directory?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('config_guardian.sync');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Import Configuration');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will import configuration from the sync directory. This is equivalent to running <code>drush config:import</code>.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->preview = $this->configSync->getImportPreview();

    $form['#attached']['library'][] = 'config_guardian/config-sync';

    // No changes message.
    if (!$this->preview['has_changes']) {
      $form['no_changes'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
      ];
      $form['no_changes']['message'] = [
        '#markup' => $this->t('There are no configuration changes to import. The active configuration is synchronized with the sync directory.'),
      ];

      $form['actions'] = [
        '#type' => 'actions',
      ];
      $form['actions']['back'] = [
        '#type' => 'link',
        '#title' => $this->t('Back to Sync'),
        '#url' => Url::fromRoute('config_guardian.sync'),
        '#attributes' => ['class' => ['button', 'button--primary', 'button--action']],
      ];

      return $form;
    }

    // Risk assessment banner (compact design).
    if ($this->preview['risk_assessment']) {
      $risk = $this->preview['risk_assessment'];
      $risk_class = 'cg-risk-banner--' . $risk->level;

      $level_labels = [
        'low' => $this->t('Low'),
        'medium' => $this->t('Medium'),
        'high' => $this->t('High'),
        'critical' => $this->t('Critical'),
      ];

      $level_descriptions = [
        'low' => $this->t('Changes are safe to apply.'),
        'medium' => $this->t('Review changes before applying.'),
        'high' => $this->t('Carefully review all changes. Consider creating a backup.'),
        'critical' => $this->t('High-impact changes detected. Backup strongly recommended.'),
      ];

      $form['risk_banner'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cg-risk-banner-v2', $risk_class]],
      ];

      $form['risk_banner']['content'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cg-risk-banner-v2__content']],
      ];

      // Score circle.
      $form['risk_banner']['content']['score'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cg-risk-banner-v2__score']],
      ];

      $form['risk_banner']['content']['score']['circle'] = [
        '#markup' => '<div class="cg-score-display">
          <div class="cg-score-circle cg-score-circle--' . $risk->level . '">
            <span class="cg-score-circle__value">' . $risk->score . '</span>
          </div>
          <span class="cg-score-label">' . $this->t('Risk Score') . '</span>
        </div>',
      ];

      // Level and description.
      $form['risk_banner']['content']['info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cg-risk-banner-v2__info']],
      ];

      $form['risk_banner']['content']['info']['level'] = [
        '#markup' => '<div class="cg-risk-level">
          <span class="cg-risk-level__badge cg-risk-level__badge--' . $risk->level . '">' . ($level_labels[$risk->level] ?? $risk->level) . '</span>
          <span class="cg-risk-level__text">' . $this->t('Risk Level') . '</span>
        </div>',
      ];

      $form['risk_banner']['content']['info']['description'] = [
        '#markup' => '<p class="cg-risk-banner-v2__description">' . ($level_descriptions[$risk->level] ?? '') . '</p>',
      ];

      // Risk factors in a separate collapsible section.
      if (!empty($risk->riskFactors)) {
        $factors_count = count($risk->riskFactors);
        $form['risk_factors'] = [
          '#type' => 'details',
          '#title' => $this->t('Risk Factors (@count)', ['@count' => $factors_count]),
          '#open' => $factors_count <= 5,
          '#attributes' => ['class' => ['cg-risk-factors-panel']],
        ];

        $form['risk_factors']['list'] = [
          '#theme' => 'item_list',
          '#items' => $risk->riskFactors,
          '#attributes' => ['class' => ['cg-risk-factors-list']],
        ];
      }
    }

    // Summary cards.
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-import-summary']],
    ];

    $form['summary']['create'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-summary-item', 'cg-summary-item--added']],
    ];
    $form['summary']['create']['value'] = [
      '#markup' => '<span class="cg-summary-value">' . count($this->preview['changes']['create']) . '</span>',
    ];
    $form['summary']['create']['label'] = [
      '#markup' => '<span class="cg-summary-label">' . $this->t('To Create') . '</span>',
    ];

    $form['summary']['update'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-summary-item', 'cg-summary-item--modified']],
    ];
    $form['summary']['update']['value'] = [
      '#markup' => '<span class="cg-summary-value">' . count($this->preview['changes']['update']) . '</span>',
    ];
    $form['summary']['update']['label'] = [
      '#markup' => '<span class="cg-summary-label">' . $this->t('To Update') . '</span>',
    ];

    $form['summary']['delete'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-summary-item', 'cg-summary-item--removed']],
    ];
    $form['summary']['delete']['value'] = [
      '#markup' => '<span class="cg-summary-value">' . count($this->preview['changes']['delete']) . '</span>',
    ];
    $form['summary']['delete']['label'] = [
      '#markup' => '<span class="cg-summary-label">' . $this->t('To Delete') . '</span>',
    ];

    // Conflicts warning.
    if (!empty($this->preview['conflicts'])) {
      $form['conflicts'] = [
        '#type' => 'details',
        '#title' => $this->t('Conflicts Detected (@count)', ['@count' => count($this->preview['conflicts'])]),
        '#open' => TRUE,
        '#attributes' => ['class' => ['cg-conflicts', 'messages', 'messages--warning']],
      ];

      $conflict_items = [];
      foreach ($this->preview['conflicts'] as $conflict) {
        $conflict_items[] = [
          '#markup' => '<strong>' . $conflict['config'] . '</strong>: ' . $conflict['details'] .
          ' <span class="cg-conflict-severity cg-conflict-severity--' . $conflict['severity'] . '">(' . $conflict['severity'] . ')</span>',
        ];
      }

      $form['conflicts']['list'] = [
        '#theme' => 'item_list',
        '#items' => $conflict_items,
      ];
    }

    // Detailed changes.
    $form['changes'] = [
      '#type' => 'details',
      '#title' => $this->t('Detailed Changes'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['cg-changes-details']],
    ];

    // New configurations.
    if (!empty($this->preview['changes']['create'])) {
      $form['changes']['create'] = [
        '#type' => 'details',
        '#title' => $this->t('New Configurations (@count)', ['@count' => count($this->preview['changes']['create'])]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['cg-changes-section', 'cg-changes-section--create']],
      ];

      $create_items = [];
      foreach ($this->preview['changes']['create'] as $config) {
        $create_items[] = [
          '#markup' => '<code>' . $config['name'] . '</code> ' .
          '<span class="cg-config-type">(' . $config['type'] . ')</span> ' .
          '<span class="cg-risk-badge cg-risk-badge--' . $config['risk'] . '">' . $config['risk'] . '</span>',
        ];
      }

      $form['changes']['create']['list'] = [
        '#theme' => 'item_list',
        '#items' => $create_items,
        '#attributes' => ['class' => ['cg-config-list']],
      ];
    }

    // Updated configurations.
    if (!empty($this->preview['changes']['update'])) {
      $form['changes']['update'] = [
        '#type' => 'details',
        '#title' => $this->t('Updated Configurations (@count)', ['@count' => count($this->preview['changes']['update'])]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['cg-changes-section', 'cg-changes-section--update']],
      ];

      $update_items = [];
      foreach ($this->preview['changes']['update'] as $config) {
        $dependents_info = '';
        if (!empty($config['dependents_count'])) {
          $dependents_info = ' <span class="cg-dependents">(' . $this->t('@count dependents', ['@count' => $config['dependents_count']]) . ')</span>';
        }

        $update_items[] = [
          '#markup' => '<code>' . $config['name'] . '</code> ' .
          '<span class="cg-config-type">(' . $config['type'] . ')</span> ' .
          '<span class="cg-risk-badge cg-risk-badge--' . $config['risk'] . '">' . $config['risk'] . '</span>' .
          $dependents_info,
        ];
      }

      $form['changes']['update']['list'] = [
        '#theme' => 'item_list',
        '#items' => $update_items,
        '#attributes' => ['class' => ['cg-config-list']],
      ];
    }

    // Deleted configurations.
    if (!empty($this->preview['changes']['delete'])) {
      $form['changes']['delete'] = [
        '#type' => 'details',
        '#title' => $this->t('Configurations to Delete (@count)', ['@count' => count($this->preview['changes']['delete'])]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['cg-changes-section', 'cg-changes-section--delete']],
      ];

      $delete_items = [];
      foreach ($this->preview['changes']['delete'] as $config) {
        $dependents_info = '';
        if (!empty($config['dependents_count'])) {
          $dependents_info = ' <span class="cg-dependents cg-dependents--warning">(' . $this->t('@count dependents!', ['@count' => $config['dependents_count']]) . ')</span>';
        }

        $delete_items[] = [
          '#markup' => '<code>' . $config['name'] . '</code> ' .
          '<span class="cg-config-type">(' . $config['type'] . ')</span> ' .
          '<span class="cg-risk-badge cg-risk-badge--' . $config['risk'] . '">' . $config['risk'] . '</span>' .
          $dependents_info,
        ];
      }

      $form['changes']['delete']['list'] = [
        '#theme' => 'item_list',
        '#items' => $delete_items,
        '#attributes' => ['class' => ['cg-config-list']],
      ];
    }

    // Backup option.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import Options'),
    ];

    $form['options']['create_backup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create backup snapshot before import'),
      '#description' => $this->t('Recommended. Creates a snapshot of current configuration before applying changes.'),
      '#default_value' => TRUE,
    ];

    // Validation errors.
    $validation_errors = $this->configSync->validateImport();
    if (!empty($validation_errors)) {
      $form['validation_errors'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
      ];

      $form['validation_errors']['title'] = [
        '#markup' => '<h3>' . $this->t('Validation Errors') . '</h3>',
      ];

      $form['validation_errors']['list'] = [
        '#theme' => 'item_list',
        '#items' => $validation_errors,
      ];

      $form['validation_errors']['note'] = [
        '#markup' => '<p>' . $this->t('These errors must be resolved before importing. The import button has been disabled.') . '</p>',
      ];
    }

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $has_validation_errors = !empty($validation_errors);
    $submit_classes = ['cg-btn', 'cg-btn--primary'];
    if ($has_validation_errors) {
      $submit_classes[] = 'cg-btn--disabled';
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Configuration'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => $submit_classes],
      '#disabled' => $has_validation_errors,
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->getCancelUrl(),
      '#attributes' => ['class' => ['cg-btn', 'cg-btn--outline']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $backup_snapshot_id = NULL;

    // Create backup snapshot if requested.
    if ($form_state->getValue('create_backup')) {
      $backup_snapshot_id = $this->configSync->createBackupSnapshot();
    }

    // Create config importer.
    try {
      $config_importer = $this->configSync->createConfigImporter();

      // Validate first.
      $config_importer->validate();

      // Get sync steps.
      $sync_steps = $config_importer->initialize();

      // Get import stats before building batch.
      $changelist = $config_importer->getStorageComparer()->getChangelist();
      $import_stats = [
        'created' => count($changelist['create'] ?? []),
        'updated' => count($changelist['update'] ?? []),
        'deleted' => count($changelist['delete'] ?? []),
      ];

      // Build batch.
      $batch_builder = (new BatchBuilder())
        ->setTitle($this->t('Importing configuration'))
        ->setFinishCallback([ConfigImportBatch::class, 'finish'])
        ->setInitMessage($this->t('Starting configuration import...'))
        ->setProgressMessage($this->t('Completed step @current of @total.'))
        ->setErrorMessage($this->t('Configuration import encountered an error.'));

      // Add initialization operation to store backup snapshot ID and stats.
      $batch_builder->addOperation(
        [ConfigImportBatch::class, 'initialize'],
        [$backup_snapshot_id, $import_stats]
      );

      foreach ($sync_steps as $sync_step) {
        $batch_builder->addOperation(
          [ConfigImportBatch::class, 'process'],
          [$config_importer, $sync_step]
        );
      }

      batch_set($batch_builder->toArray());
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Import failed: @error', ['@error' => $e->getMessage()]));
    }

    $form_state->setRedirect('config_guardian.sync');
  }

}
