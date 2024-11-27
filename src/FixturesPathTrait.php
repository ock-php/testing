<?php

declare(strict_types = 1);

namespace Ock\Testing;

use Ock\ClassDiscovery\NamespaceDirectory;

trait FixturesPathTrait {

  /**
   * Gets the base fixtures path for all methods of this class.
   *
   * @return string
   *   Directory without ending '/'.
   */
  protected static function getClassFixturesPath(): string {
    $reflection_class = new \ReflectionClass(static::class);
    $class_dir = NamespaceDirectory::fromReflectionClass($reflection_class);
    return $class_dir->getPackageDirectory(level: 3)
      . '/fixtures'
      . $class_dir->getRelativePath('', 3)
      . '/' . $reflection_class->getShortName();
  }

}
