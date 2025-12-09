<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for snapshot operations.
 */
class SnapshotController extends ControllerBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * Constructs a SnapshotController object.
   */
  public function __construct(SnapshotManagerService $snapshot_manager) {
    $this->snapshotManager = $snapshot_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager')
    );
  }

  /**
   * Lists all snapshots.
   *
   * @return array
   *   A render array.
   */
  public function list(): array {
    $snapshots = $this->snapshotManager->getSnapshotList([], 50);

    $header = [
      ['data' => $this->t('ID'), 'field' => 'id'],
      ['data' => $this->t('Name'), 'field' => 'name'],
      ['data' => $this->t('Type'), 'field' => 'type'],
      ['data' => $this->t('Configs'), 'field' => 'config_count'],
      ['data' => $this->t('Created'), 'field' => 'created'],
      ['data' => $this->t('Operations')],
    ];

    $rows = [];
    foreach ($snapshots as $snapshot) {
      $operations = [
        '#type' => 'operations',
        '#links' => [
          'view' => [
            'title' => $this->t('View'),
            'url' => Url::fromRoute('config_guardian.snapshot.view', [
              'config_snapshot' => $snapshot['id'],
            ]),
          ],
          'rollback' => [
            'title' => $this->t('Rollback'),
            'url' => Url::fromRoute('config_guardian.snapshot.rollback', [
              'config_snapshot' => $snapshot['id'],
            ]),
          ],
          'export' => [
            'title' => $this->t('Export'),
            'url' => Url::fromRoute('config_guardian.snapshot.export', [
              'config_snapshot' => $snapshot['id'],
            ]),
          ],
          'delete' => [
            'title' => $this->t('Delete'),
            'url' => Url::fromRoute('config_guardian.snapshot.delete', [
              'config_snapshot' => $snapshot['id'],
            ]),
          ],
        ],
      ];

      $type_labels = [
        'auto' => $this->t('Automatic'),
        'manual' => $this->t('Manual'),
        'pre_import' => $this->t('Pre-import'),
        'pre_export' => $this->t('Pre-export'),
        'pre_rollback' => $this->t('Pre-rollback'),
      ];

      $rows[] = [
        'data' => [
          $snapshot['id'],
          $snapshot['name'],
          $type_labels[$snapshot['type']] ?? $snapshot['type'],
          $snapshot['config_count'],
          date('Y-m-d H:i:s', (int) $snapshot['created']),
          ['data' => $operations],
        ],
      ];
    }

    $build = [];

    // Action buttons at top of page.
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cg-snapshot-actions']],
    ];

    $build['actions']['create'] = [
      '#type' => 'link',
      '#title' => $this->t('Create Snapshot'),
      '#url' => Url::fromRoute('config_guardian.snapshot.add'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'button--action'],
      ],
    ];

    $build['actions']['import'] = [
      '#type' => 'link',
      '#title' => $this->t('Import Snapshot'),
      '#url' => Url::fromRoute('config_guardian.snapshot.import'),
      '#attributes' => [
        'class' => ['button', 'button--action'],
      ],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No snapshots have been created yet.'),
    ];

    $build['#attached'] = [
      'library' => ['config_guardian/admin'],
    ];

    return $build;
  }

  /**
   * Views a single snapshot.
   *
   * @param int $config_snapshot
   *   The snapshot ID.
   *
   * @return array
   *   A render array.
   */
  public function view(int $config_snapshot): array {
    $snapshot = $this->snapshotManager->loadSnapshot($config_snapshot);

    if (!$snapshot) {
      throw new NotFoundHttpException();
    }

    $config_data = $this->snapshotManager->getSnapshotConfigData($config_snapshot);
    $config_list = array_keys($config_data);
    sort($config_list);

    // Group configs by type.
    $grouped_configs = [];
    foreach ($config_list as $config_name) {
      $parts = explode('.', $config_name);
      $group = $parts[0] ?? 'other';
      $grouped_configs[$group][] = $config_name;
    }
    ksort($grouped_configs);

    return [
      '#theme' => 'config_guardian_snapshot_view',
      '#snapshot' => $snapshot,
      '#config_list' => $grouped_configs,
      '#attached' => [
        'library' => ['config_guardian/admin'],
      ],
    ];
  }

  /**
   * Title callback for viewing a snapshot.
   *
   * @param int $config_snapshot
   *   The snapshot ID.
   *
   * @return string
   *   The title.
   */
  public function viewTitle(int $config_snapshot): string {
    $snapshot = $this->snapshotManager->loadSnapshot($config_snapshot);
    return $snapshot ? $snapshot['name'] : $this->t('Snapshot');
  }

  /**
   * Compares two snapshots.
   *
   * @param int $snapshot1
   *   First snapshot ID.
   * @param int $snapshot2
   *   Second snapshot ID.
   *
   * @return array
   *   A render array.
   */
  public function compare(int $snapshot1, int $snapshot2): array {
    $snap1 = $this->snapshotManager->loadSnapshot($snapshot1);
    $snap2 = $this->snapshotManager->loadSnapshot($snapshot2);

    if (!$snap1 || !$snap2) {
      throw new NotFoundHttpException();
    }

    $diff = $this->snapshotManager->compareSnapshots($snapshot1, $snapshot2);

    return [
      '#theme' => 'config_guardian_compare',
      '#snapshot1' => $snap1,
      '#snapshot2' => $snap2,
      '#diff' => [
        'added' => $diff->added,
        'removed' => $diff->removed,
        'modified' => array_keys($diff->modified),
        'modified_details' => $diff->modified,
      ],
      '#attached' => [
        'library' => ['config_guardian/admin'],
      ],
    ];
  }

  /**
   * Exports a snapshot as JSON.
   *
   * @param int $config_snapshot
   *   The snapshot ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The download response.
   */
  public function export(int $config_snapshot): Response {
    $export_data = $this->snapshotManager->exportSnapshot($config_snapshot);

    if (!$export_data) {
      throw new NotFoundHttpException();
    }

    $filename = 'config_guardian_snapshot_' . $config_snapshot . '_' . date('Y-m-d_His') . '.json';

    $response = new Response(
      json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    $response->headers->set('Content-Type', 'application/json');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
