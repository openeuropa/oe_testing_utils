<?php

declare(strict_types=1);

namespace OpenEuropa\TestingUtilities\Traits;

use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Core JS test-helper modules that WebDriverTestBase adds on top of $modules.
   *
   * These are installed for every cached-install test (functional and
   * JavaScript alike) and excluded from the cache fingerprint, so that a single
   * dump per declared-module set is valid for both a functional test and a
   * JavaScript test that declare the same modules.
   *
   * @see \Drupal\FunctionalJavascriptTests\WebDriverTestBase::installModulesFromClassProperty()
   */
  protected const TEST_HELPER_MODULES = [
    'js_testing_ajax_request_test',
    'js_testing_log_test',
    'css_disable_transitions_test',
  ];

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
  protected function installModulesFromClassProperty(ContainerInterface $container) {
    // Install the JS test modules for functional tests too so the resulting
    // cached dump is interchangeable between a functional test and a JavaScript
    // test that declare the same modules.
    if (!$this instanceof WebDriverTestBase) {
      self::$modules = array_values(array_unique(array_merge(self::$modules, self::TEST_HELPER_MODULES)));
    }
    parent::installModulesFromClassProperty($container);
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

    // The contents of the cache tables are specific to the site/container that
    // created the dump and would be stale when restored under another test.
    // Dump their structure only, so they are restored empty and Drupal
    // repopulates them on demand. This also keeps the dump small and fast.
    $cache_tables = array_filter($tables, fn (string $table): bool => str_starts_with($table, $this->databasePrefix . 'cache'));
    $data_tables = array_diff($tables, $cache_tables);

    $args = $this->mysqlClientArgs($default);
    $dumps = [];
    if ($data_tables) {
      $dumps[] = sprintf('mysqldump --no-tablespaces %s %s', $args, implode(' ', array_map('escapeshellarg', $data_tables)));
    }
    if ($cache_tables) {
      $dumps[] = sprintf('mysqldump --no-tablespaces --no-data %s %s', $args, implode(' ', array_map('escapeshellarg', $cache_tables)));
    }

    // Write to a unique temporary file and then rename it into place. Why?
    // Tests run in parallel against this shared dump directory, so a
    // plain redirect onto the final path would let two processes that both
    // install and dump try to save their mysqldump output into the same
    // file and corrupt it. rename() on the same filesystem is atomic: readers
    // always see a complete file, and racing writers at worst repeat an
    // install, never produce a half-written dump.
    $tmp = sprintf('%s.%d.%s.tmp', $this->dumpFile, getmypid(), uniqid());
    $command = sprintf(
      '{ %s ; } | sed %s > %s',
      implode(' ; ', $dumps),
      escapeshellarg("s/{$this->databasePrefix}/default_db_prefix_/g"),
      escapeshellarg($tmp)
    );
    exec($command, $output, $status);
    if ($status !== 0) {
      @unlink($tmp);
      throw new \RuntimeException(sprintf('mysqldump failed with status %d.', $status));
    }
    if (!@rename($tmp, $this->dumpFile)) {
      @unlink($tmp);
      throw new \RuntimeException(sprintf('Failed to publish database dump to %s.', $this->dumpFile));
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
    // The JS test-helper modules are installed for every test (see
    // ::installModulesFromClassProperty()) and core's WebDriverTestBase leaks
    // them into the shared static $modules slot mid-run. Excluding them keeps
    // the fingerprint identical for a functional test and a JavaScript test
    // with the same declared modules.
    $modules = array_diff($modules, self::TEST_HELPER_MODULES);
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
