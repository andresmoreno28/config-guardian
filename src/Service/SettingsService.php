<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for managing Config Guardian settings.
 */
class SettingsService {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a SettingsService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Gets the settings configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The settings configuration.
   */
  protected function getSettings() {
    return $this->configFactory->get('config_guardian.settings');
  }

  /**
   * Checks if automatic snapshots are enabled.
   *
   * @return bool
   *   TRUE if automatic snapshots are enabled.
   */
  public function isAutoSnapshotEnabled(): bool {
    return (bool) $this->getSettings()->get('auto_snapshot_enabled');
  }

  /**
   * Checks if snapshot should be created before import.
   *
   * @return bool
   *   TRUE if snapshot should be created before import.
   */
  public function isAutoSnapshotBeforeImport(): bool {
    return (bool) $this->getSettings()->get('auto_snapshot_before_import');
  }

  /**
   * Gets the automatic snapshot interval.
   *
   * @return string
   *   The interval: hourly, daily, or weekly.
   */
  public function getAutoSnapshotInterval(): string {
    return $this->getSettings()->get('auto_snapshot_interval') ?? 'daily';
  }

  /**
   * Gets the maximum number of snapshots to keep.
   *
   * @return int
   *   The maximum number of snapshots.
   */
  public function getMaxSnapshots(): int {
    return (int) ($this->getSettings()->get('max_snapshots') ?? 50);
  }

  /**
   * Gets the retention days for snapshots.
   *
   * @return int
   *   The number of days to retain snapshots.
   */
  public function getRetentionDays(): int {
    return (int) ($this->getSettings()->get('retention_days') ?? 90);
  }

  /**
   * Gets the compression method.
   *
   * @return string
   *   The compression method: none, gzip, or bzip2.
   */
  public function getCompression(): string {
    return $this->getSettings()->get('compression') ?? 'gzip';
  }

  /**
   * Gets the exclude patterns.
   *
   * @return array
   *   The patterns to exclude from snapshots.
   */
  public function getExcludePatterns(): array {
    return $this->getSettings()->get('exclude_patterns') ?? [];
  }

}
