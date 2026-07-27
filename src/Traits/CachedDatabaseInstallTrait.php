<?php

declare(strict_types=1);

namespace OpenEuropa\TestingUtilities\Traits;

use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\user\Entity\User;

/**
 * Caches the post-install database state to speed up functional tests.
 *
 * The first test with a given module/theme/profile fingerprint runs a full
 * site install and dumps the resulting tables to disk. Later tests with the
 * same fingerprint restore that dump from doInstall() instead of running the
 * installer.
 *
 * Set $this->cacheDbInstall = TRUE to enable.
 */
trait CachedDatabaseInstallTrait {

  /**
   * Whether to use the cached database install.
   */
  protected bool $cacheDbInstall = FALSE;

  /**
   * Path to the SQL dump file for this test's fingerprint.
   */
  protected string $dumpFile;

  /**
   * Whether the dump file existed when this test run started.
   */
  protected bool $dumpFileExisted = FALSE;

  /**
   * {@inheritdoc}
   */
  public function installDrupal(): void {
    if (!$this->cacheDbInstall) {
      parent::installDrupal();
      return;
    }

    $this->initDumpFile();
    $this->dumpFileExisted = file_exists($this->dumpFile);

    parent::installDrupal();

    if ($this->dumpFileExisted) {
      $this->postRestoreFixups();
    }
    else {
      $this->dumpDatabase();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doInstall() {
    if (!$this->cacheDbInstall || !$this->dumpFileExisted) {
      parent::doInstall();
      return;
    }

    $this->restoreDatabase();

    // install_drupal normally writes the database connection and hash_salt to
    // settings.php. Do it here so the subsequent initSettings/initKernel calls
    // in parent::installDrupal() can boot against the restored DB.
    $connection_info = Database::getConnectionInfo('default');
    $settings['databases']['default']['default'] = (object) [
      'value' => $connection_info['default'],
      'required' => TRUE,
    ];
    $settings['settings']['hash_salt'] = (object) [
      'value' => $this->databasePrefix,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
  }

  /**
   * Repairs bits of state that install_drupal() would normally set up.
   */
  protected function postRestoreFixups(): void {
    \Drupal::service('file_system')->prepareDirectory(
      $this->tempFilesDirectory,
      FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY
    );

    // The dump has user 1 with the password hash from the run that created it.
    // initUserSession() generated a fresh pass_raw for this test, so sync the
    // stored hash to match — otherwise drupalLogin($this->rootUser) fails.
    $user = User::load(1);
    $user->setPassword($this->rootUser->pass_raw);
    $user->save();

    // Cache tables (cache_*) were captured in the dump and contain entries
    // keyed to the first test's container/site — stale for this test. Flushing
    // rebuilds routes, discovery, and clears all cache bins.
    drupal_flush_all_caches();
    $this->container = \Drupal::getContainer();
  }

  /**
   * Dumps the tables for the current test prefix to $this->dumpFile.
   */
  protected function dumpDatabase(): void {
    $connection_info = Database::getConnectionInfo('default');
    $default = $connection_info['default'];
    $this->assertMysqlDriver($default);

    $tables = \Drupal::database()
      ->query("SHOW TABLES LIKE '{$this->databasePrefix}%'")
      ->fetchCol();
    if (!$tables) {
      throw new \RuntimeException(sprintf('No tables found to dump for prefix %s.', $this->databasePrefix));
    }

    $command = sprintf(
      'mysqldump --no-tablespaces %s %s | sed %s > %s',
      $this->mysqlClientArgs($default),
      implode(' ', array_map('escapeshellarg', $tables)),
      escapeshellarg("s/{$this->databasePrefix}/default_db_prefix_/g"),
      escapeshellarg($this->dumpFile)
    );
    exec($command, $output, $status);
    if ($status !== 0) {
      @unlink($this->dumpFile);
      throw new \RuntimeException(sprintf('mysqldump failed with status %d.', $status));
    }
  }

  /**
   * Restores $this->dumpFile into the current test prefix.
   */
  protected function restoreDatabase(): void {
    $connection_info = Database::getConnectionInfo('default');
    $default = $connection_info['default'];
    $this->assertMysqlDriver($default);

    $command = sprintf(
      'sed %s %s | mysql %s',
      escapeshellarg("s/default_db_prefix_/{$this->databasePrefix}/g"),
      escapeshellarg($this->dumpFile),
      $this->mysqlClientArgs($default)
    );
    exec($command, $output, $status);
    if ($status !== 0) {
      throw new \RuntimeException(sprintf('mysql restore failed with status %d.', $status));
    }
  }

  /**
   * Computes the dump-file path from the test's module/theme fingerprint.
   */
  protected function initDumpFile(): void {
    $modules = [];
    $class = static::class;
    while ($class) {
      if (property_exists($class, 'modules')) {
        $modules = array_merge($modules, $class::$modules);
      }
      $class = get_parent_class($class);
    }
    $modules = array_unique($modules);
    sort($modules);

    $profile = property_exists($this, 'profile') ? (string) $this->profile : '';
    $fingerprint = hash('sha256', implode(',', $modules) . '|' . $this->defaultTheme . '|' . $profile . '|' . \Drupal::VERSION);

    $cache_dir = \DRUPAL_ROOT . '/sites/default/files/functional-test-dumps';
    if (!is_dir($cache_dir)) {
      mkdir($cache_dir, 0777, TRUE);
    }
    $this->dumpFile = $cache_dir . '/' . $fingerprint . '.sql';
  }

  /**
   * Throws exception unless the connection is MySQL.
   */
  protected function assertMysqlDriver(array $default): void {
    $driver = strtolower((string) $default['driver']);
    $short = ltrim(strrchr($driver, '\\') ?: $driver, '\\');
    if (!in_array($short, ['mysql', 'mysqli'], TRUE)) {
      throw new \LogicException(sprintf('CachedDatabaseInstallTrait only supports the mysql driver, got %s.', var_export($default['driver'], TRUE)));
    }
  }

  /**
   * Builds the shared `-h -P -u -p <db>` argument string for mysql/mysqldump.
   */
  protected function mysqlClientArgs(array $default): string {
    $args = [
      '-h', escapeshellarg($default['host']),
      '-u', escapeshellarg($default['username']),
    ];
    if (!empty($default['port'])) {
      $args[] = '-P';
      $args[] = escapeshellarg((string) $default['port']);
    }
    if (!empty($default['password'])) {
      $args[] = '-p' . escapeshellarg($default['password']);
    }
    $args[] = escapeshellarg($default['database']);
    return implode(' ', $args);
  }

}
