<?php

declare(strict_types=1);

namespace Ock\Testing\Storage;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Stores assertion values in a yaml file per test method and dataset.
 */
class AssertionValueStore_Yaml implements AssertionValueStoreInterface {

  /**
   * Constructor.
   *
   * @param string $basePath
   * @param \Closure(): array $buildHeader
   */
  public function __construct(
    private readonly string $basePath,
    private readonly \Closure $buildHeader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(string $name, array $values): void {
    $file = $this->getFile($name);
    if ($values === []) {
      // This test does not use recorded assertions.
      if (\file_exists($file)) {
        \unlink($file);
      }
      return;
    }
    $yaml_data = ($this->buildHeader)();
    $yaml_data['values'] = $values;
    $yaml = Yaml::dump($yaml_data, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    // Verify that yaml export is reversible.
    Assert::assertSame($yaml_data, Yaml::parse($yaml));
    if (!\is_dir(\dirname($file))) {
      \mkdir(dirname($file), recursive: true);
    }
    file_put_contents($file, $yaml);
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $name): array {
    $file = $this->getFile($name);
    Assert::assertFileExists($file);
    $yaml_data = Yaml::parseFile($file);
    Assert::assertIsArray($yaml_data);

    // Verify test metadata.
    $stored_header = $yaml_data;
    unset($stored_header['values']);
    $actual_header = ($this->buildHeader)();
    Assert::assertSame($stored_header, $actual_header);

    Assert::assertArrayHasKey('values', $yaml_data);
    Assert::assertIsArray($yaml_data['values']);
    Assert::assertNotEmpty($yaml_data['values'], 'The list of recorded values is empty. The file should not exist.');
    return $yaml_data['values'];
  }

  /**
   * {@inheritdoc}
   */
  public function getStoredNames(): array {
    $files = \glob($this->basePath . '*.yml');
    $regex = sprintf('#^%s([\w\-]*)\.yml$#', \preg_quote($this->basePath, '#'));
    $names = [];
    foreach ($files as $file) {
      if (!\preg_match($regex, $file, $m)) {
        // This file must belong to another test or have a different purpose.
        continue;
      }
      $names[] = $m[1];
    }
    return $names;
  }

  /**
   * Gets the file path of a yaml file.
   *
   * @param string $name
   *   Name for the test method and dataset.
   *
   * @return string
   */
  protected function getFile(string $name): string {
    $name = \preg_replace('@[^\w\-]@', '.', $name);
    return $this->basePath . $name . '.yml';
  }

}
