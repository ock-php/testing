<?php

declare(strict_types = 1);

namespace Ock\Testing;

use Ock\ClassFilesIterator\NamespaceDirectory;

trait RecordingsPathTrait {

  /**
   * Gets a path to use for recordings for this test class.
   *
   * If the test class path is 'tests/src/Subdir/MyTest.php', then this method
   * would return 'tests/recordings/Subdir/MyTest'.
   *
   * The calling code can decide whether to use this as a directory, or as a
   * prefix, where the last fragment might be part of a file name.
   *
   * The idea is that anything under 'tests/recordings/' can be deleted and
   * regenerated, by running the test in recording mode.
   *
   * @return string
   *   Path without ending '/'.
   */
  protected function getClassRecordingsPath(): string {
    $reflection_class = new \ReflectionClass(static::class);
    $class_dir = NamespaceDirectory::fromReflectionClass($reflection_class);
    return $class_dir->getPackageDirectory(level: 3)
      . '/recordings'
      . $class_dir->getRelativePath('', 3)
      . '/' . $reflection_class->getShortName();
  }

}
