<?php

declare(strict_types=1);

namespace Ock\Testing;

use Ock\ClassDiscovery\NamespaceDirectory;
use Symfony\Component\Yaml\Yaml;

/**
 * Mechanism where expected values are pre-recorded.
 *
 * A test using this trait can be run with two modes:
 * - Default/playback mode:
 *   In this mode, the "as recorded" assertions compare the actual value against
 *   a recorded value.
 * - Recording mode:
 *   To activate this, set an environment variable with UPDATE_TESTS=1.
 *   In this mode, the "as recorded" assertions overwrite the recorded value
 *   with the actual value.
 *
 * @todo Implement a mechanism that deletes recording files when a test method
 *   or a data provider record was removed.
 */
trait RecordedTestTrait {

  /**
   * Recorded values for or from the assertions.
   *
   * In default/playback mode, these values are loaded from a yaml file.
   * In recording mode, the values are collected from the assertion calls.
   *
   * @var list<mixed>
   */
  private array $recordedValues;

  /**
   * Current index in the `$recordedValues`.
   *
   * @var int
   */
  private int $recordingIndex;

  /**
   * Backup of the original method name.
   *
   * @var string
   */
  private string $originalName;

  /**
   * {@inheritdoc}
   */
  public function runTest(): mixed {
    $this->originalName = $this->getName(false);
    if ($this->isRecording()) {
      $this->setName('runTestAndUpdate');
    }
    else {
      $this->setName('runTestAndCompare');
    }
    return parent::runTest();
  }

  /**
   * Placeholder test method.
   *
   * This runs as a decorator of the actual test method.
   *
   * @param mixed ...$args
   *   Arguments to pass to the test method.
   *
   * @return mixed
   *   Return value from the test method.
   *   Usually this is just null/void.
   */
  protected function runTestAndCompare(...$args): mixed {
    // Restore the original test name.
    $this->setName($this->originalName);
    $file = $this->getRecordingFile();

    static::assertFileExists($file);
    $yaml_data = Yaml::parseFile($file);
    static::assertIsArray($yaml_data);

    // Verify test metadata.
    $header = $yaml_data;
    unset($header['values']);
    static::assertSame($this->buildHeader($args), $header);

    static::assertArrayHasKey('values', $yaml_data);
    static::assertIsArray($yaml_data['values']);
    $this->recordedValues = $yaml_data['values'];
    $this->recordingIndex = 0;

    // Run the decorated method.
    $result = $this->{$this->originalName}(...$args);

    // Check for expected values that were not asserted for.
    static::assertArrayNotHasKey($this->recordingIndex, $this->recordedValues, "Premature end with index $this->recordingIndex.");

    return $result;
  }

  /**
   * Placeholder test method.
   *
   * This runs as a decorator of the actual test method.
   *
   * @param mixed ...$args
   *   Arguments to pass to the test method.
   *
   * @return mixed
   *   Return value from the test method.
   *   Usually this is just null/void.
   */
  protected function runTestAndUpdate(...$args): mixed {
    // Restore the original test name.
    $this->setName($this->originalName);
    $file = $this->getRecordingFile();

    $this->recordedValues = [];
    $this->recordingIndex = 0;

    // Run the decorated method.
    $result = $this->{$this->originalName}(...$args);

    // Update the yaml file.
    $yaml_data = $this->buildHeader($args);
    $yaml_data['values'] = $this->recordedValues;
    $yaml = Yaml::dump($yaml_data, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    if (!\is_dir(\dirname($file))) {
      \mkdir(dirname($file), recursive: true);
    }
    file_put_contents($file, $yaml);

    return $result;
  }

  protected function buildHeader(array $args): array {
    $header = [
      'test' => static::class . '::' . $this->originalName . '()',
    ];
    $dataName = $this->dataName() ?? '';
    if ($dataName !== '') {
      $header['dataset name'] = $dataName;
    }
    if ($args !== []) {
      $header['arguments'] = $args;
    }
    return $header;
  }

  protected function getRecordingFile(): string {
    $nsdir = NamespaceDirectory::fromKnownClass(static::class)
      ->package(3);
    $tns = $nsdir->getTerminatedNamespace();
    $name = static::class;
    if (\str_starts_with($name, $tns)) {
      $name = \substr($name, \strlen($tns));
    }
    $name .= '-' . $this->getName(false);
    $dataName = $this->dataName() ?? '';
    if ($dataName !== '') {
      $name .= '-' . $dataName;
    }
    $name = \preg_replace('@[^\w\-]@', '.', $name);
    return $nsdir->getPackageDirectory(level: 3) . '/recordings/' . $name . '.yml';
  }

  /**
   * Checks whether the test runs in "recording" mode.
   *
   * @return bool
   *   TRUE if the test runs in "recording" mode.
   */
  protected function isRecording(): bool {
    return !!\getenv('UPDATE_TESTS');
  }

  /**
   * Asserts that a value is the same as a previously recorded value.
   *
   * @param mixed $actual
   *   The actual value to compare.
   * @param string|null $key
   *   A key or message to add to the value.
   */
  public function assertAsRecorded(mixed $actual, string $key = null): void {
    $actual = $this->exportForYaml($actual);
    if ($key !== null) {
      $actual = [$key => $actual];
    }
    if ($this->isRecording()) {
      $this->recordedValues[] = $actual;
      static::assertTrue(true);
    }
    else {
      static::assertArrayHasKey($this->recordingIndex, $this->recordedValues, 'Unexpected assertion.');
      $expected = $this->recordedValues[$this->recordingIndex];
      ++$this->recordingIndex;
      static::assertSame($expected, $actual);
    }
  }

  /**
   * Exports values for yaml.
   *
   * @param mixed $value
   * @param int $depth
   *
   * @return mixed
   *   Exported value.
   *   This won't contain any objects.
   */
  protected function exportForYaml(mixed $value, int $depth = 2): mixed {
    if (\is_array($value)) {
      if ($depth <= 0) {
        return '[...]';
      }
      return \array_map(
        fn ($v) => $this->exportForYaml($v, $depth - 1),
        $value
      );
    }
    if (is_object($value)) {
      $export = ['class' => \get_class($value)];
      $properties = (new \ReflectionClass($value))->getProperties();
      if ($depth <= 0) {
        $export['properties'] = '...';
        return $export;
      }
      $export['properties'] = [];
      foreach ($properties as $property) {
        if ($property->isStatic()) {
          continue;
        }
        try {
          $propertyValue = $property->getValue($value);
        }
        catch (\Throwable) {
          $propertyValue = '(not initialized)';
        }
        $export['properties']['$' . $property->name] = $this->exportForYaml($propertyValue, 0);
      }
      return $export;
    }
    if (\is_resource($value)) {
      return 'resource';
    }
    return $value;
  }

}
