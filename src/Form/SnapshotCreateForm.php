<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating a new snapshot.
 */
class SnapshotCreateForm extends FormBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * Constructs a SnapshotCreateForm object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ActivityLoggerService $activity_logger,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.activity_logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_snapshot_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Snapshot name'),
      '#description' => $this->t('A descriptive name for this snapshot.'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->t('Manual snapshot - @date', [
        '@date' => date('Y-m-d H:i:s'),
      ]),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Optional detailed description of this snapshot.'),
      '#rows' => 3,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Snapshot'),
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = $form_state->getValue('name');
    $description = $form_state->getValue('description');

    try {
      $snapshot = $this->snapshotManager->createSnapshot($name, 'manual', [
        'description' => $description,
      ]);

      $this->activityLogger->log(
        'snapshot_created',
        [
          'name' => $name,
          'id' => $snapshot['id'],
          'config_count' => $snapshot['config_count'],
        ],
        [],
        (int) $snapshot['id']
      );

      $this->messenger()->addStatus($this->t('Snapshot "@name" has been created with @count configurations.', [
        '@name' => $name,
        '@count' => $snapshot['config_count'],
      ]));

      $form_state->setRedirect('config_guardian.snapshots');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create snapshot: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

}
