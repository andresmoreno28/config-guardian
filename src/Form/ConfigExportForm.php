<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\config_guardian\Batch\ConfigExportBatch;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\ConfigSyncService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for exporting configuration to sync directory.
 */
class ConfigExportForm extends FormBase {

  /**
   * The config sync service.
   */
  protected ConfigSyncService $configSync;

  /**
   * The snapshot manager service.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The activity logger service.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * Constructs a ConfigExportForm object.
   */
  public function __construct(
    ConfigSyncService $config_sync,
    SnapshotManagerService $snapshot_manager,
    ActivityLoggerService $activity_logger,
  ) {
    $this->configSync = $config_sync;
    $this->snapshotManager = $snapshot_manager;
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.config_sync'),
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.activity_logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $preview = $this->configSync->getExportPreview();

    $form['#attached']['library'][] = 'config_guardian/config-sync';

    // Header section.
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-sync-header']],
    ];

    $form['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Export Configuration'),
    ];

    $form['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Export active configuration to the sync directory. This is equivalent to running <code>drush config:export</code>.'),
    ];

    // Summary section.
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-export-summary']],
    ];

    $form['summary']['stats'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Export Summary'),
      '#items' => [
        $this->t('@count total configurations', ['@count' => $preview['total']]),
        $this->t('@count new (not in sync)', ['@count' => count($preview['new'])]),
        $this->t('@count modified', ['@count' => count($preview['modified'])]),
        $this->t('@count unchanged', ['@count' => count($preview['unchanged'])]),
        $this->t('@count to remove from sync', ['@count' => count($preview['to_delete'])]),
      ],
    ];

    // Changes preview.
    if (!empty($preview['new']) || !empty($preview['modified']) || !empty($preview['to_delete'])) {
      $form['changes'] = [
        '#type' => 'details',
        '#title' => $this->t('Changes Preview'),
        '#open' => TRUE,
        '#attributes' => ['class' => ['cg-changes-preview']],
      ];

      if (!empty($preview['new'])) {
        $form['changes']['new'] = [
          '#type' => 'details',
          '#title' => $this->t('New Configurations (@count)', ['@count' => count($preview['new'])]),
          '#open' => FALSE,
          '#attributes' => ['class' => ['cg-changes-section', 'cg-changes-section--create']],
        ];
        $form['changes']['new']['list'] = [
          '#theme' => 'item_list',
          '#items' => array_map(fn($name) => ['#markup' => '<code>' . $name . '</code>'], $preview['new']),
        ];
      }

      if (!empty($preview['modified'])) {
        $form['changes']['modified'] = [
          '#type' => 'details',
          '#title' => $this->t('Modified Configurations (@count)', ['@count' => count($preview['modified'])]),
          '#open' => FALSE,
          '#attributes' => ['class' => ['cg-changes-section', 'cg-changes-section--update']],
        ];
        $form['changes']['modified']['list'] = [
          '#theme' => 'item_list',
          '#items' => array_map(fn($name) => ['#markup' => '<code>' . $name . '</code>'], $preview['modified']),
        ];
      }

      if (!empty($preview['to_delete'])) {
        $form['changes']['to_delete'] = [
          '#type' => 'details',
          '#title' => $this->t('To Remove from Sync (@count)', ['@count' => count($preview['to_delete'])]),
          '#open' => FALSE,
          '#attributes' => ['class' => ['cg-changes-section', 'cg-changes-section--delete']],
        ];
        $form['changes']['to_delete']['list'] = [
          '#theme' => 'item_list',
          '#items' => array_map(fn($name) => ['#markup' => '<code>' . $name . '</code>'], $preview['to_delete']),
        ];
      }
    }

    // Full config list (collapsible).
    $form['all_configs'] = [
      '#type' => 'details',
      '#title' => $this->t('All Configurations by Module (@count)', ['@count' => $preview['total']]),
      '#open' => FALSE,
      '#attributes' => ['class' => ['cg-all-configs']],
    ];

    // Search box.
    $form['all_configs']['search'] = [
      '#type' => 'textfield',
      '#placeholder' => $this->t('Search configurations...'),
      '#attributes' => [
        'class' => ['cg-config-search'],
        'data-search-target' => 'config-groups',
      ],
    ];

    $form['all_configs']['groups'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'config-groups', 'class' => ['cg-config-groups']],
    ];

    foreach ($preview['grouped'] as $group => $configs) {
      $form['all_configs']['groups'][$group] = [
        '#type' => 'details',
        '#title' => $group . ' (' . count($configs) . ')',
        '#open' => FALSE,
        '#attributes' => ['class' => ['cg-config-group']],
      ];
      $form['all_configs']['groups'][$group]['list'] = [
        '#theme' => 'item_list',
        '#items' => array_map(fn($name) => ['#markup' => '<code>' . $name . '</code>'], $configs),
        '#attributes' => ['class' => ['cg-config-list']],
      ];
    }

    // Backup option.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Export Options'),
    ];

    $form['options']['create_backup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create backup snapshot before export'),
      '#description' => $this->t('Recommended. Creates a snapshot of current sync directory state before exporting. This allows you to restore if needed.'),
      '#default_value' => TRUE,
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export Configuration'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('config_guardian.sync'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $backup_snapshot_id = NULL;

    // Create backup snapshot BEFORE export if requested.
    // Snapshots now capture BOTH active config AND sync directory,
    // allowing complete restoration to the pre-export state.
    if ($form_state->getValue('create_backup')) {
      try {
        $snapshot = $this->snapshotManager->createSnapshot(
          'Pre-export backup - ' . date('Y-m-d H:i:s'),
          'pre_export'
        );
        $backup_snapshot_id = (int) $snapshot['id'];

        $this->activityLogger->log(
          'snapshot_created',
          [
            'name' => $snapshot['name'],
            'type' => 'pre_export',
            'reason' => 'Automatic backup before configuration export',
          ]
        );
      }
      catch (\Exception $e) {
        $this->messenger()->addWarning($this->t('Could not create backup snapshot: @error', [
          '@error' => $e->getMessage(),
        ]));
      }
    }

    $all_configs = $this->configSync->getActiveConfigList();
    $preview = $this->configSync->getExportPreview();

    // Build batch operations.
    $batch_builder = (new BatchBuilder())
      ->setTitle($this->t('Exporting configuration'))
      ->setFinishCallback([ConfigExportBatch::class, 'finish'])
      ->setInitMessage($this->t('Starting configuration export...'))
      ->setProgressMessage($this->t('Exported @current of @total configurations.'))
      ->setErrorMessage($this->t('Configuration export encountered an error.'));

    // Add initialization operation to store backup snapshot ID.
    $batch_builder->addOperation(
      [ConfigExportBatch::class, 'initialize'],
      [$backup_snapshot_id]
    );

    // Split configs into chunks for granular progress.
    $chunk_size = 25;
    $chunks = array_chunk($all_configs, $chunk_size);

    foreach ($chunks as $chunk) {
      $batch_builder->addOperation(
        [ConfigExportBatch::class, 'process'],
        [$chunk]
      );
    }

    // Add operation to delete obsolete configs from sync.
    if (!empty($preview['to_delete'])) {
      $batch_builder->addOperation(
        [ConfigExportBatch::class, 'deleteObsolete'],
        [$preview['to_delete']]
      );
    }

    batch_set($batch_builder->toArray());

    $form_state->setRedirect('config_guardian.sync');
  }

}
