<?php

declare(strict_types = 1);

namespace Ock\Testing\Tests;

use Ock\Testing\Diff\ExportedArrayDiffer;
use Ock\Testing\FileAsRecordedTrait;
use Ock\Testing\FixturesPathTrait;
use Ock\Testing\IsRecordingTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use function Ock\Helpers\scandir_known;

class ExportedArrayDifferTest extends TestCase {

  use FixturesPathTrait;
  use FileAsRecordedTrait;
  use IsRecordingTrait;

  #[DataProvider('compareDataProvider')]
  public function testCompare(string $file, array $before, array $after): void {
    $differ = new ExportedArrayDiffer();
    $diff = $differ->compare($before, $after);
    $dir = static::getFixturesDir();
    $yml = Yaml::dump([
      'before' => $before,
      'after' => $after,
      'diff' => $diff,
    ], 99, 2);
    $this->assertFileAsRecorded($dir . '/' . $file, $yml);
  }

  public static function compareDataProvider(): \Iterator {
    $dir = static::getFixturesDir();
    foreach (scandir_known($dir) as $candidate) {
      if (!str_ends_with($candidate, '.yml')) {
        continue;
      }
      $record = Yaml::parseFile($dir . '/' . $candidate, Yaml::PARSE_CUSTOM_TAGS);
      assert(is_array($record));
      yield [
        $candidate,
        $record['before'] ?? [],
        $record['after'] ?? [],
      ];
    }
  }

  protected static function getFixturesDir(): string {
    return static::getClassFixturesPath();
  }

}
