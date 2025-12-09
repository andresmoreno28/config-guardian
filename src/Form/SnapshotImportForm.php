<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\RollbackEngineService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing/restoring a snapshot.
 */
class SnapshotImportForm extends FormBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * The rollback engine.
   */
  protected RollbackEngineService $rollbackEngine;

  /**
   * Constructs a SnapshotImportForm object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ActivityLoggerService $activity_logger,
    RollbackEngineService $rollback_engine,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->activityLogger = $activity_logger;
    $this->rollbackEngine = $rollback_engine;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.activity_logger'),
      $container->get('config_guardian.rollback_engine')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_snapshot_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'config_guardian/admin';

    // Get existing snapshots for the selector.
    $snapshots = $this->snapshotManager->getSnapshotList([], 100);
    $snapshot_options = [];
    foreach ($snapshots as $snapshot) {
      $type_labels = [
        'auto' => $this->t('Automatic'),
        'manual' => $this->t('Manual'),
        'pre_import' => $this->t('Pre-import'),
        'pre_export' => $this->t('Pre-export'),
        'pre_rollback' => $this->t('Pre-rollback'),
      ];
      $type_label = $type_labels[$snapshot['type']] ?? $snapshot['type'];
      $date = date('Y-m-d H:i', (int) $snapshot['created']);
      $snapshot_options[$snapshot['id']] = "{$snapshot['name']} ({$type_label}, {$snapshot['config_count']} configs, {$date})";
    }

    // Import source selection.
    $form['import_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Import source'),
      '#options' => [
        'existing' => $this->t('From existing snapshot'),
        'file' => $this->t('From file'),
      ],
      '#default_value' => 'existing',
      '#required' => TRUE,
    ];

    // Existing snapshot selector.
    $form['existing_snapshot'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="import_source"]' => ['value' => 'existing'],
        ],
      ],
    ];

    if (!empty($snapshot_options)) {
      $form['existing_snapshot']['snapshot_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Select snapshot'),
        '#description' => $this->t('Choose an existing snapshot to restore. This will apply the configuration from the selected snapshot.'),
        '#options' => $snapshot_options,
        '#empty_option' => $this->t('- Select a snapshot -'),
      ];

      $form['existing_snapshot']['info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#markup' => $this->t('This will restore your configuration to the state saved in the selected snapshot. A backup will be created automatically before applying changes.'),
      ];
    }
    else {
      $form['existing_snapshot']['no_snapshots'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        '#markup' => $this->t('No snapshots available. Create a snapshot first or import from a file.'),
      ];
    }

    // File upload option.
    $form['file_upload'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="import_source"]' => ['value' => 'file'],
        ],
      ],
    ];

    $form['file_upload']['import_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Snapshot file'),
      '#description' => $this->t('Upload a JSON file exported from Config Guardian. This will create a new snapshot from the file.'),
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Snapshot'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['button', 'button--primary', 'button--action'],
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('config_guardian.snapshots'),
      '#attributes' => [
        'class' => ['button', 'button--action'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $source = $form_state->getValue('import_source');

    if ($source === 'existing') {
      $snapshot_id = $form_state->getValue('snapshot_id');
      if (empty($snapshot_id)) {
        $form_state->setErrorByName('snapshot_id', $this->t('Please select a snapshot.'));
        return;
      }

      // Validate snapshot exists.
      $snapshot = $this->snapshotManager->loadSnapshot((int) $snapshot_id);
      if (!$snapshot) {
        $form_state->setErrorByName('snapshot_id', $this->t('Selected snapshot not found.'));
      }
    }
    elseif ($source === 'file') {
      $validators = ['file_validate_extensions' => ['json']];
      $file = file_save_upload('import_file', $validators, FALSE, 0);

      if (!$file) {
        $form_state->setErrorByName('import_file', $this->t('Please upload a valid JSON file.'));
        return;
      }

      // Read and validate JSON.
      $content = file_get_contents($file->getFileUri());
      $data = json_decode($content, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('import_file', $this->t('Invalid JSON file.'));
        return;
      }

      if (!isset($data['meta']) || !isset($data['config'])) {
        $form_state->setErrorByName('import_file', $this->t('Invalid snapshot format. Missing required fields.'));
        return;
      }

      $form_state->set('import_data', $data);
      $form_state->set('import_file', $file);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source = $form_state->getValue('import_source');

    if ($source === 'existing') {
      $this->importFromExisting($form_state);
    }
    else {
      $this->importFromFile($form_state);
    }

    $form_state->setRedirect('config_guardian.snapshots');
  }

  /**
   * Imports configuration from an existing snapshot.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function importFromExisting(FormStateInterface $form_state): void {
    $snapshot_id = (int) $form_state->getValue('snapshot_id');

    try {
      // Use the rollback engine to restore the snapshot.
      $result = $this->rollbackEngine->executeRollback($snapshot_id);

      if ($result->success) {
        $this->messenger()->addStatus($this->t('Snapshot restored successfully. @changes changes applied.', [
          '@changes' => $result->changesApplied,
        ]));

        if ($result->backupSnapshotId) {
          $this->messenger()->addStatus($this->t('A backup snapshot (#@id) was created before restoring.', [
            '@id' => $result->backupSnapshotId,
          ]));
        }
      }
      else {
        $this->messenger()->addError($this->t('Failed to restore snapshot: @error', [
          '@error' => $result->errorMessage ?? $this->t('Unknown error'),
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to restore snapshot: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Imports a snapshot from a file.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function importFromFile(FormStateInterface $form_state): void {
    $data = $form_state->get('import_data');

    try {
      $snapshot = $this->snapshotManager->importSnapshot($data);

      $this->activityLogger->log(
        'snapshot_imported',
        [
          'original_name' => $data['meta']['name'] ?? '',
          'new_id' => $snapshot['id'],
          'config_count' => count($data['config']),
        ],
        [],
        (int) $snapshot['id']
      );

      $this->messenger()->addStatus($this->t('Snapshot imported successfully with @count configurations. You can now restore it from the snapshots list.', [
        '@count' => count($data['config']),
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to import snapshot: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    // Clean up uploaded file.
    $file = $form_state->get('import_file');
    if ($file) {
      $file->delete();
    }
  }

}
