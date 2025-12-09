<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\config_guardian\Batch\RollbackBatch;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\RollbackEngineService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for rolling back to a snapshot.
 */
class RollbackForm extends ConfirmFormBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The rollback engine.
   */
  protected RollbackEngineService $rollbackEngine;

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * The snapshot data.
   */
  protected ?array $snapshot = NULL;

  /**
   * Constructs a RollbackForm object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    RollbackEngineService $rollback_engine,
    ActivityLoggerService $activity_logger,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->rollbackEngine = $rollback_engine;
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.rollback_engine'),
      $container->get('config_guardian.activity_logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_rollback_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to rollback to this snapshot?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('config_guardian.snapshots');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will restore <strong>both</strong> the active configuration and sync directory to the exact state saved in this snapshot. A backup snapshot will be created automatically before the rollback.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Restore Snapshot');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $config_snapshot = NULL): array {
    $this->snapshot = $this->snapshotManager->loadSnapshot((int) $config_snapshot);

    if (!$this->snapshot) {
      $this->messenger()->addError($this->t('Snapshot not found.'));
      return [];
    }

    $form = parent::buildForm($form, $form_state);

    // Check if this is a v2 snapshot (captures both active and sync).
    $is_v2 = $this->snapshotManager->isSnapshotV2((int) $config_snapshot);
    if (!$is_v2) {
      $form['legacy_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#weight' => -20,
      ];
      $form['legacy_notice']['message'] = [
        '#markup' => '<strong>' . $this->t('Legacy Snapshot') . '</strong><br>' .
        $this->t('This snapshot was created with an older version and only contains active configuration. Sync directory data is not available.'),
      ];
    }

    // Show snapshot details.
    $form['snapshot_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Snapshot Details'),
      '#open' => TRUE,
      '#weight' => -10,
    ];

    $form['snapshot_info']['name'] = [
      '#type' => 'item',
      '#title' => $this->t('Name'),
      '#markup' => $this->snapshot['name'],
    ];

    $form['snapshot_info']['type'] = [
      '#type' => 'item',
      '#title' => $this->t('Type'),
      '#markup' => ucfirst(str_replace('_', ' ', $this->snapshot['type'])),
    ];

    $form['snapshot_info']['created'] = [
      '#type' => 'item',
      '#title' => $this->t('Created'),
      '#markup' => date('Y-m-d H:i:s', (int) $this->snapshot['created']),
    ];

    $form['snapshot_info']['config_count'] = [
      '#type' => 'item',
      '#title' => $this->t('Total Configurations'),
      '#markup' => $this->snapshot['config_count'],
    ];

    // Simulate rollback to show changes.
    $simulation = $this->rollbackEngine->simulateRollback((int) $config_snapshot);

    if ($simulation->valid) {
      $form['simulation'] = [
        '#type' => 'details',
        '#title' => $this->t('Restore Preview'),
        '#open' => TRUE,
        '#weight' => -5,
      ];

      if ($simulation->totalChanges === 0) {
        $form['simulation']['no_changes'] = [
          '#markup' => '<p>' . $this->t('No changes needed - environment is already at this snapshot state.') . '</p>',
        ];
      }
      else {
        $form['simulation']['summary'] = [
          '#markup' => '<p>' . $this->t('This restore will make <strong>@total</strong> total changes:', ['@total' => $simulation->totalChanges]) . '</p>',
        ];

        // Active config changes.
        if ($simulation->activeChanges > 0) {
          $form['simulation']['active_header'] = [
            '#markup' => '<h4>' . $this->t('Active Configuration (@count changes)', ['@count' => $simulation->activeChanges]) . '</h4>',
          ];

          $active_items = [];
          if (!empty($simulation->toCreate)) {
            $active_items[] = $this->t('@count to create', ['@count' => count($simulation->toCreate)]);
          }
          if (!empty($simulation->toUpdate)) {
            $active_items[] = $this->t('@count to update', ['@count' => count($simulation->toUpdate)]);
          }
          if (!empty($simulation->toDelete)) {
            $active_items[] = $this->t('@count to delete', ['@count' => count($simulation->toDelete)]);
          }

          $form['simulation']['active_changes'] = [
            '#theme' => 'item_list',
            '#items' => $active_items,
          ];
        }

        // Sync directory changes.
        if ($simulation->syncChanges > 0) {
          $form['simulation']['sync_header'] = [
            '#markup' => '<h4>' . $this->t('Sync Directory (@count changes)', ['@count' => $simulation->syncChanges]) . '</h4>',
          ];

          $sync_items = [];
          if (!empty($simulation->syncToCreate)) {
            $sync_items[] = $this->t('@count to create', ['@count' => count($simulation->syncToCreate)]);
          }
          if (!empty($simulation->syncToUpdate)) {
            $sync_items[] = $this->t('@count to update', ['@count' => count($simulation->syncToUpdate)]);
          }
          if (!empty($simulation->syncToDelete)) {
            $sync_items[] = $this->t('@count to delete', ['@count' => count($simulation->syncToDelete)]);
          }

          $form['simulation']['sync_changes'] = [
            '#theme' => 'item_list',
            '#items' => $sync_items,
          ];
        }

        // Risk assessment (based on active config changes).
        if ($simulation->riskAssessment) {
          $risk_class = 'messages messages--status';
          if ($simulation->riskAssessment->level === 'critical') {
            $risk_class = 'messages messages--error';
          }
          elseif ($simulation->riskAssessment->level === 'high') {
            $risk_class = 'messages messages--warning';
          }
          elseif ($simulation->riskAssessment->level === 'medium') {
            $risk_class = 'messages messages--warning';
          }

          // Translate risk descriptions.
          $risk_descriptions = [
            'low' => $this->t('Low risk - Changes are safe to apply.'),
            'medium' => $this->t('Medium risk - Review changes before applying.'),
            'high' => $this->t('High risk - Carefully review all changes.'),
            'critical' => $this->t('Critical risk - Consider creating a backup first.'),
          ];

          // Translate level names.
          $level_names = [
            'low' => $this->t('LOW'),
            'medium' => $this->t('MEDIUM'),
            'high' => $this->t('HIGH'),
            'critical' => $this->t('CRITICAL'),
          ];

          $form['simulation']['risk'] = [
            '#markup' => '<div class="' . $risk_class . '">' .
            $this->t('Risk Assessment: @level (@score/100) - @description', [
              '@level' => $level_names[$simulation->riskAssessment->level] ?? strtoupper($simulation->riskAssessment->level),
              '@score' => $simulation->riskAssessment->score,
              '@description' => $risk_descriptions[$simulation->riskAssessment->level] ?? '',
            ]) . '</div>',
          ];
        }
      }
    }
    else {
      $form['simulation_error'] = [
        '#markup' => '<div class="messages messages--error">' .
        $this->t('Could not simulate rollback: @error', ['@error' => $simulation->error]) . '</div>',
      ];
    }

    $form['snapshot_id'] = [
      '#type' => 'hidden',
      '#value' => $config_snapshot,
    ];

    // Options section.
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Restore Options'),
      '#open' => TRUE,
      '#weight' => 5,
    ];

    $form['options']['create_backup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create backup snapshot before restore'),
      '#default_value' => TRUE,
      '#description' => $this->t('Creates a snapshot of the current state before restoring, allowing you to undo this operation if needed. <strong>Recommended.</strong>'),
    ];

    $form['options']['backup_warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#states' => [
        'visible' => [
          ':input[name="create_backup"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['options']['backup_warning']['message'] = [
      '#markup' => '<strong>' . $this->t('Warning') . ':</strong> ' .
      $this->t('Without a backup snapshot, you will not be able to undo this restore operation. Only disable this if you are certain about the changes.'),
    ];

    // Custom button styling.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'button';
      $form['actions']['submit']['#attributes']['class'][] = 'button--primary';
    }
    if (isset($form['actions']['cancel'])) {
      $form['actions']['cancel']['#attributes']['class'][] = 'button';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $snapshot_id = (int) $form_state->getValue('snapshot_id');
    $create_backup = (bool) $form_state->getValue('create_backup');

    // Use batch processing to restore BOTH active config AND sync directory.
    $batch_data = $this->rollbackEngine->prepareRollbackBatch($snapshot_id, [
      'create_backup' => $create_backup,
    ]);

    if (!$batch_data['valid']) {
      $this->messenger()->addError($this->t('Rollback failed: @error', [
        '@error' => $batch_data['error'],
      ]));
      $form_state->setRedirect('config_guardian.snapshots');
      return;
    }

    // Check for no changes.
    if (!empty($batch_data['no_changes'])) {
      $this->messenger()->addStatus($this->t('No changes needed - environment is already at snapshot state.'));
      $form_state->setRedirect('config_guardian.snapshots');
      return;
    }

    // Build batch.
    $batch_builder = (new BatchBuilder())
      ->setTitle($this->t('Restoring snapshot'))
      ->setFinishCallback([RollbackBatch::class, 'finish'])
      ->setInitMessage($this->t('Starting restore...'))
      ->setProgressMessage($this->t('Processing configurations...'))
      ->setErrorMessage($this->t('Restore encountered an error.'));

    // Add initialization operation.
    $batch_builder->addOperation(
      [RollbackBatch::class, 'initialize'],
      [
        $snapshot_id,
        $batch_data['pre_rollback_snapshot_id'],
        $batch_data['active_changelist'],
        $batch_data['sync_changelist'],
      ]
    );

    // ============ ACTIVE CONFIG OPERATIONS ============
    // Process active creates in batches.
    $active_creates = $batch_data['active_changelist']['create'] ?? [];
    foreach (array_chunk($active_creates, RollbackBatch::BATCH_SIZE) as $chunk) {
      $batch_builder->addOperation(
        [RollbackBatch::class, 'processActiveCreates'],
        [$snapshot_id, $chunk]
      );
    }

    // Process active updates in batches.
    $active_updates = $batch_data['active_changelist']['update'] ?? [];
    foreach (array_chunk($active_updates, RollbackBatch::BATCH_SIZE) as $chunk) {
      $batch_builder->addOperation(
        [RollbackBatch::class, 'processActiveUpdates'],
        [$snapshot_id, $chunk]
      );
    }

    // Process active deletes in batches.
    $active_deletes = $batch_data['active_changelist']['delete'] ?? [];
    foreach (array_chunk($active_deletes, RollbackBatch::BATCH_SIZE) as $chunk) {
      $batch_builder->addOperation(
        [RollbackBatch::class, 'processActiveDeletes'],
        [$snapshot_id, $chunk]
      );
    }

    // ============ SYNC DIRECTORY OPERATIONS ============
    // Process sync creates in batches.
    $sync_creates = $batch_data['sync_changelist']['create'] ?? [];
    foreach (array_chunk($sync_creates, RollbackBatch::BATCH_SIZE) as $chunk) {
      $batch_builder->addOperation(
        [RollbackBatch::class, 'processSyncCreates'],
        [$snapshot_id, $chunk]
      );
    }

    // Process sync updates in batches.
    $sync_updates = $batch_data['sync_changelist']['update'] ?? [];
    foreach (array_chunk($sync_updates, RollbackBatch::BATCH_SIZE) as $chunk) {
      $batch_builder->addOperation(
        [RollbackBatch::class, 'processSyncUpdates'],
        [$snapshot_id, $chunk]
      );
    }

    // Process sync deletes in batches.
    $sync_deletes = $batch_data['sync_changelist']['delete'] ?? [];
    foreach (array_chunk($sync_deletes, RollbackBatch::BATCH_SIZE) as $chunk) {
      $batch_builder->addOperation(
        [RollbackBatch::class, 'processSyncDeletes'],
        [$snapshot_id, $chunk]
      );
    }

    // Clear caches after rollback.
    $batch_builder->addOperation([RollbackBatch::class, 'clearCaches'], []);

    batch_set($batch_builder->toArray());

    $form_state->setRedirect('config_guardian.snapshots');
  }

}
