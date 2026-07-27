# OpenEuropa Testing Utilities

Generic testing utilities for OpenEuropa components.

## Installation

The recommended way of installing the package is via [Composer][1]:

```bash
composer require --dev openeuropa/oe_testing_utils
```

## Usage

### CachedDatabaseInstallTrait

Speeds up Drupal functional tests by caching the post-install database state. The
first test with a given module/theme/profile fingerprint runs a full site
install and dumps the resulting tables to disk. Subsequent tests with the same
fingerprint restore the dump instead of running the installer.

Add the trait to your functional test and enable it:

```php
use Drupal\Tests\BrowserTestBase;
use OpenEuropa\TestingUtilities\Traits\CachedDatabaseInstallTrait;

class MyFunctionalTest extends BrowserTestBase {

  use CachedDatabaseInstallTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->cacheDbInstall = TRUE;
    parent::setUp();
  }

}
```

Notes:

- Only the MySQL database driver is supported.
- Dumps are stored in `sites/default/files/functional-test-dumps`. Delete this
  directory to force a fresh install.

[1]: https://getcomposer.org
