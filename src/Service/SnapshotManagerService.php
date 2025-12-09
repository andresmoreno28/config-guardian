<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\config_guardian\Model\SnapshotDiff;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service for managing configuration snapshots.
 */
class SnapshotManagerService {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The active config storage.
   */
  protected StorageInterface $configStorage;

  /**
   * The sync config storage.
   */
  protected StorageInterface $syncStorage;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The event dispatcher.
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The settings service.
   */
  protected SettingsService $settings;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The UUID generator.
   */
  protected UuidInterface $uuid;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The cache tags invalidator.
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * Constructs a SnapshotManagerService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    StorageInterface $config_storage,
    StorageInterface $sync_storage,
    ConfigFactoryInterface $config_factory,
    EventDispatcherInterface $event_dispatcher,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    SettingsService $settings,
    Connection $database,
    UuidInterface $uuid,
    TimeInterface $time,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configStorage = $config_storage;
    $this->syncStorage = $sync_storage;
    $this->configFactory = $config_factory;
    $this->eventDispatcher = $event_dispatcher;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('config_guardian');
    $this->settings = $settings;
    $this->database = $database;
    $this->uuid = $uuid;
    $this->time = $time;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * Creates a new snapshot of the current configuration.
   *
   * Snapshots capture the COMPLETE state of the environment, including BOTH
   * the active configuration AND the sync directory. This allows for a full
   * restore to the exact state at the time of capture.
   *
   * @param string $name
   *   The name of the snapshot.
   * @param string $type
   *   The type of snapshot: auto, manual, pre_import, pre_rollback.
   * @param array $options
   *   Additional options:
   *   - description: Long description.
   *   - exclude_patterns: Patterns to exclude.
   *
   * @return array
   *   The created snapshot data.
   */
  public function createSnapshot(string $name, string $type = 'manual', array $options = []): array {
    // Get exclusion patterns.
    $exclude_patterns = $options['exclude_patterns'] ?? $this->settings->getExcludePatterns();

    // Capture ACTIVE configuration.
    $active_config_names = $this->configStorage->listAll();
    if (!empty($exclude_patterns)) {
      $active_config_names = $this->applyExclusions($active_config_names, $exclude_patterns);
    }

    $active_data = [];
    foreach ($active_config_names as $config_name) {
      $data = $this->configStorage->read($config_name);
      if ($data !== FALSE) {
        $active_data[$config_name] = $data;
      }
    }

    // Capture SYNC directory configuration.
    $sync_config_names = $this->syncStorage->listAll();
    if (!empty($exclude_patterns)) {
      $sync_config_names = $this->applyExclusions($sync_config_names, $exclude_patterns);
    }

    $sync_data = [];
    foreach ($sync_config_names as $config_name) {
      $data = $this->syncStorage->read($config_name);
      if ($data !== FALSE) {
        $sync_data[$config_name] = $data;
      }
    }

    // Store both in a structured format.
    $full_snapshot_data = [
      'version' => 2,
      'active' => $active_data,
      'sync' => $sync_data,
    ];

    // Compress data.
    $compressed_data = $this->compressData($full_snapshot_data);

    // Calculate integrity hash on full data using JSON (secure).
    $hash = hash('sha256', json_encode($full_snapshot_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Generate UUID.
    $uuid = $this->uuid->generate();

    // Total config count is the max of both (unique configs).
    $all_config_names = array_unique(array_merge(
      array_keys($active_data),
      array_keys($sync_data)
    ));

    // Insert into database.
    $id = $this->database->insert('config_guardian_snapshot')
      ->fields([
        'uuid' => $uuid,
        'name' => $name,
        'description' => $options['description'] ?? '',
        'type' => $type,
        'config_data' => $compressed_data,
        'config_hash' => $hash,
        'config_count' => count($all_config_names),
        'created' => $this->time->getRequestTime(),
        'created_by' => $this->currentUser->id(),
      ])
      ->execute();

    $snapshot = [
      'id' => $id,
      'uuid' => $uuid,
      'name' => $name,
      'type' => $type,
      'config_count' => count($all_config_names),
      'active_count' => count($active_data),
      'sync_count' => count($sync_data),
    ];

    $this->logger->info('Snapshot "@name" created with ID @id (active: @active, sync: @sync)', [
      '@name' => $name,
      '@id' => $id,
      '@active' => count($active_data),
      '@sync' => count($sync_data),
    ]);

    // Invalidate cache tags to refresh dashboard.
    $this->cacheTagsInvalidator->invalidateTags(['config_guardian_snapshot_list']);

    return $snapshot;
  }

  /**
   * Loads a snapshot by ID.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return array|null
   *   The snapshot data or NULL if not found.
   */
  public function loadSnapshot(int $id): ?array {
    $result = $this->database->select('config_guardian_snapshot', 's')
      ->fields('s')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Gets a list of snapshots.
   *
   * @param array $filters
   *   Optional filters (type, etc.).
   * @param int $limit
   *   Maximum number of results.
   * @param int $offset
   *   Offset for pagination.
   *
   * @return array
   *   List of snapshots.
   */
  public function getSnapshotList(array $filters = [], int $limit = 20, int $offset = 0): array {
    $query = $this->database->select('config_guardian_snapshot', 's')
      ->fields('s', [
        'id',
        'uuid',
        'name',
        'description',
        'type',
        'config_count',
        'config_hash',
        'created',
        'created_by',
      ])
      ->orderBy('created', 'DESC')
      ->range($offset, $limit);

    if (!empty($filters['type'])) {
      $query->condition('type', $filters['type']);
    }

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Gets the total count of snapshots.
   *
   * @param array $filters
   *   Optional filters.
   *
   * @return int
   *   The total count.
   */
  public function getSnapshotCount(array $filters = []): int {
    $query = $this->database->select('config_guardian_snapshot', 's')
      ->countQuery();

    if (!empty($filters['type'])) {
      $query->condition('type', $filters['type']);
    }

    return (int) $query->execute()->fetchField();
  }

  /**
   * Deletes a snapshot.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return bool
   *   TRUE if deleted, FALSE otherwise.
   */
  public function deleteSnapshot(int $id): bool {
    $deleted = $this->database->delete('config_guardian_snapshot')
      ->condition('id', $id)
      ->execute();

    if ($deleted) {
      $this->logger->info('Snapshot @id deleted', ['@id' => $id]);
      // Invalidate cache tags to refresh dashboard.
      $this->cacheTagsInvalidator->invalidateTags(['config_guardian_snapshot_list']);
    }

    return $deleted > 0;
  }

  /**
   * Compares two snapshots and returns the differences.
   *
   * @param int $id1
   *   First snapshot ID.
   * @param int $id2
   *   Second snapshot ID.
   *
   * @return \Drupal\config_guardian\Model\SnapshotDiff
   *   The differences between snapshots.
   */
  public function compareSnapshots(int $id1, int $id2): SnapshotDiff {
    $snap1 = $this->loadSnapshot($id1);
    $snap2 = $this->loadSnapshot($id2);

    $diff = new SnapshotDiff();

    if (!$snap1 || !$snap2) {
      return $diff;
    }

    // Use getSnapshotActiveData to get the actual config data for comparison.
    // This handles both v1 (flat) and v2 (active/sync structure) snapshots.
    $data1 = $this->getSnapshotActiveData($id1);
    $data2 = $this->getSnapshotActiveData($id2);

    // Added configurations.
    $diff->added = array_keys(array_diff_key($data2, $data1));

    // Removed configurations.
    $diff->removed = array_keys(array_diff_key($data1, $data2));

    // Modified configurations.
    $common_keys = array_intersect(array_keys($data1), array_keys($data2));
    foreach ($common_keys as $key) {
      if ($data1[$key] !== $data2[$key]) {
        $diff->modified[$key] = [
          'before' => $data1[$key],
          'after' => $data2[$key],
        ];
      }
    }

    return $diff;
  }

  /**
   * Gets the configuration data from a snapshot.
   *
   * This method returns the raw decompressed data. For new v2 snapshots,
   * use getSnapshotActiveData() and getSnapshotSyncData() for specific storage.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return array
   *   The configuration data (for v1 snapshots: flat config array,
   *   for v2 snapshots: ['version' => 2, 'active' => [...], 'sync' => [...]]).
   */
  public function getSnapshotConfigData(int $id): array {
    $snapshot = $this->loadSnapshot($id);
    if (!$snapshot) {
      return [];
    }

    return $this->decompressData($snapshot['config_data']);
  }

  /**
   * Gets the active configuration data from a snapshot.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return array
   *   The active configuration data.
   */
  public function getSnapshotActiveData(int $id): array {
    $data = $this->getSnapshotConfigData($id);

    // v2 format: has 'version' key and 'active' array.
    if (isset($data['version']) && $data['version'] === 2) {
      return $data['active'] ?? [];
    }

    // v1 format (legacy): flat config array is the active config.
    return $data;
  }

  /**
   * Gets the sync directory configuration data from a snapshot.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return array
   *   The sync configuration data.
   */
  public function getSnapshotSyncData(int $id): array {
    $data = $this->getSnapshotConfigData($id);

    // v2 format: has 'version' key and 'sync' array.
    if (isset($data['version']) && $data['version'] === 2) {
      return $data['sync'] ?? [];
    }

    // v1 format (legacy): no sync data was captured.
    // Return empty array - old snapshots didn't capture sync.
    return [];
  }

  /**
   * Checks if a snapshot is in the new v2 format (captures both active and sync).
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return bool
   *   TRUE if v2 format, FALSE if legacy format.
   */
  public function isSnapshotV2(int $id): bool {
    $data = $this->getSnapshotConfigData($id);
    return isset($data['version']) && $data['version'] === 2;
  }

  /**
   * Exports a snapshot to an array suitable for JSON export.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return array|null
   *   The export data or NULL if not found.
   */
  public function exportSnapshot(int $id): ?array {
    $snapshot = $this->loadSnapshot($id);
    if (!$snapshot) {
      return NULL;
    }

    return [
      'meta' => [
        'name' => $snapshot['name'],
        'description' => $snapshot['description'],
        'type' => $snapshot['type'],
        'created' => $snapshot['created'],
        'hash' => $snapshot['config_hash'],
        'config_count' => $snapshot['config_count'],
        'drupal_version' => \Drupal::VERSION,
        'config_guardian_version' => '1.0.0',
      ],
      'config' => $this->decompressData($snapshot['config_data']),
    ];
  }

  /**
   * Imports a snapshot from exported data.
   *
   * @param array $data
   *   The imported data.
   *
   * @return array
   *   The created snapshot.
   */
  public function importSnapshot(array $data): array {
    $name = $data['meta']['name'] ?? 'Imported snapshot';
    $description = ($data['meta']['description'] ?? '') . ' (Imported on ' . date('Y-m-d H:i:s') . ')';

    return $this->createSnapshot($name, 'manual', [
      'description' => $description,
      'config_names' => array_keys($data['config']),
    ]);
  }

  /**
   * Cleans up old snapshots based on retention policy.
   *
   * @param int $cutoff_timestamp
   *   Snapshots older than this will be deleted.
   * @param int $max_snapshots
   *   Maximum number of snapshots to keep.
   */
  public function cleanupOldSnapshots(int $cutoff_timestamp, int $max_snapshots): void {
    $total_deleted = 0;

    // Delete by age.
    $deleted = $this->database->delete('config_guardian_snapshot')
      ->condition('created', $cutoff_timestamp, '<')
      ->condition('type', 'auto')
      ->execute();

    if ($deleted > 0) {
      $total_deleted += $deleted;
      $this->logger->info('Deleted @count old snapshots based on retention policy.', [
        '@count' => $deleted,
      ]);
    }

    // Delete excess snapshots (keep max_snapshots).
    $count = $this->getSnapshotCount();
    if ($count > $max_snapshots) {
      $excess = $count - $max_snapshots;
      $old_ids = $this->database->select('config_guardian_snapshot', 's')
        ->fields('s', ['id'])
        ->condition('type', 'auto')
        ->orderBy('created', 'ASC')
        ->range(0, $excess)
        ->execute()
        ->fetchCol();

      if (!empty($old_ids)) {
        $this->database->delete('config_guardian_snapshot')
          ->condition('id', $old_ids, 'IN')
          ->execute();

        $total_deleted += count($old_ids);
        $this->logger->info('Deleted @count excess snapshots.', [
          '@count' => count($old_ids),
        ]);
      }
    }

    // Invalidate cache tags if any snapshots were deleted.
    if ($total_deleted > 0) {
      $this->cacheTagsInvalidator->invalidateTags(['config_guardian_snapshot_list']);
    }
  }

  /**
   * Applies exclusion patterns to config names.
   *
   * @param array $config_names
   *   The configuration names.
   * @param array $patterns
   *   The exclusion patterns.
   *
   * @return array
   *   The filtered configuration names.
   */
  protected function applyExclusions(array $config_names, array $patterns): array {
    return array_filter($config_names, function ($name) use ($patterns) {
      foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $name)) {
          return FALSE;
        }
      }
      return TRUE;
    });
  }

  /**
   * Compresses configuration data.
   *
   * Uses JSON encoding for security (avoiding unserialize vulnerabilities).
   *
   * @param array $data
   *   The data to compress.
   *
   * @return string
   *   The compressed data.
   */
  public function compressData(array $data): string {
    // Use JSON encoding for security - avoids PHP object injection vulnerabilities.
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === FALSE) {
      throw new \RuntimeException('Failed to encode configuration data as JSON');
    }
    $compression = $this->settings->getCompression();

    if ($compression === 'gzip' && function_exists('gzcompress')) {
      return gzcompress($encoded, 9);
    }

    if ($compression === 'bzip2' && function_exists('bzcompress')) {
      return bzcompress($encoded);
    }

    return $encoded;
  }

  /**
   * Decompresses configuration data.
   *
   * Supports both JSON format (secure) and legacy serialized format.
   *
   * @param string $data
   *   The compressed data.
   *
   * @return array
   *   The decompressed data.
   */
  public function decompressData(string $data): array {
    $decompressed = NULL;

    // Try gzip first.
    if (function_exists('gzuncompress')) {
      $result = @gzuncompress($data);
      if ($result !== FALSE) {
        $decompressed = $result;
      }
    }

    // Try bzip2 if gzip didn't work.
    if ($decompressed === NULL && function_exists('bzdecompress')) {
      $result = @bzdecompress($data);
      if ($result !== FALSE && is_string($result)) {
        $decompressed = $result;
      }
    }

    // If no decompression worked, assume uncompressed.
    if ($decompressed === NULL) {
      $decompressed = $data;
    }

    // Try JSON decode first (secure, preferred format).
    $decoded = json_decode($decompressed, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      return $decoded;
    }

    // Fall back to unserialize for legacy data, but with security restrictions.
    // Only allow arrays and stdClass objects - no arbitrary class instantiation.
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
    $unserialized = @unserialize($decompressed, ['allowed_classes' => FALSE]);
    if ($unserialized !== FALSE && is_array($unserialized)) {
      return $unserialized;
    }

    // If both methods fail, return empty array.
    $this->logger->error('Failed to decompress snapshot data');
    return [];
  }

  /**
   * Verifies the integrity of a snapshot.
   *
   * @param int $id
   *   The snapshot ID.
   *
   * @return bool
   *   TRUE if integrity is valid.
   */
  public function verifySnapshotIntegrity(int $id): bool {
    $snapshot = $this->loadSnapshot($id);
    if (!$snapshot) {
      return FALSE;
    }

    $data = $this->decompressData($snapshot['config_data']);

    // Try JSON hash first (new format).
    $json_hash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($json_hash === $snapshot['config_hash']) {
      return TRUE;
    }

    // Fall back to legacy serialize hash for backward compatibility.
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
    $legacy_hash = hash('sha256', serialize($data));
    return $legacy_hash === $snapshot['config_hash'];
  }

}
