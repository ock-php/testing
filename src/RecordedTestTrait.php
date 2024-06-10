<?php

declare(strict_types=1);

namespace Ock\Testing;

use Ock\ClassDiscovery\NamespaceDirectory;
use Ock\ClassDiscovery\Reflection\ClassReflection;
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
    // Verify that the export is reversible.
    static::assertSame($yaml_data, Yaml::parse($yaml));
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
      $header['arguments'] = $this->exportForYaml($args);
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
   * @param int $depth
   *   Depth for yaml export.
   */
  public function assertAsRecorded(mixed $actual, string $key = null, int $depth = 2): void {
    $actual = $this->exportForYaml($actual, $depth);
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
   * @param string|int|null $key
   *   Array key or property name in the parent structure.
   *   This is unused here, but can be used in child methods.
   *
   * @return mixed
   *   Exported value.
   *   This won't contain any objects.
   */
  protected function exportForYaml(mixed $value, int $depth = 2, string|int $key = null): mixed {
    if (\is_array($value)) {
      if ($depth <= 0) {
        return '[...]';
      }
      return \array_map(
        fn ($k) => $this->exportForYaml($value[$k], $depth - 1, $k),
        \array_combine(array_keys($value), array_keys($value)),
      );
    }
    if (is_object($value)) {
      return $this->exportObjectForYaml($value, $depth, $key);
    }
    if (\is_resource($value)) {
      return 'resource';
    }
    return $value;
  }

  protected function exportObjectForYaml(object $object, int $depth, int|string $key = null): array {
    return $this->doExportObjectForYaml($object, $depth);
  }

  protected function doExportObjectForYaml(object $object, int $depth, bool $getters = false, object $compare = null): array {
    $reflectionClass = new \ReflectionClass($object);
    $classNameExport = $reflectionClass->getName();
    if ($reflectionClass->isAnonymous()) {
      if (\preg_match('#^(class@anonymous\\0/[^:]+:)\d+\$[0-9a-f]+$#', $classNameExport, $matches)) {
        // Replace the line number and the hash-like suffix.
        // This will make the asserted value more stable.
        $classNameExport = $matches[1] . '**';
      }
    }
    $export = ['class' => $classNameExport];
    if ($depth <= 0) {
      return $export;
    }
    if (!$getters) {
      $export += $this->exportObjectProperties($object, $depth - 1);
    }
    else {
      $export += $this->exportObjectProperties($object, $depth - 1, true);
      $export += $this->exportObjectGetterValues($object, $depth - 1);
    }
    if ($compare) {
      $compare_export = $this->doExportObjectForYaml($compare, $depth, $getters);
      unset($compare_export['class']);
      $export = $this->arrayDiffAssocStrict($export, $compare_export);
    }
    return $export;
  }

  protected function exportObjectWithGetters(object $object, int $depth, int|string $key = null): array {
    $reflectionClass = new \ReflectionClass($object);
    $classNameExport = $reflectionClass->getName();
    if ($reflectionClass->isAnonymous()) {
      if (\preg_match('#^(class@anonymous\\0/[^:]+:)\d+\$[0-9a-f]+$#', $classNameExport, $matches)) {
        // Replace the line number and the hash-like suffix.
        // This will make the asserted value more stable.
        $classNameExport = $matches[1] . '**';
      }
    }
    $export = ['class' => $classNameExport];
    if ($depth <= 0) {
      $export['properties'] = '...';
      return $export;
    }
    $export['properties'] = $this->exportObjectProperties($object, 0, true);
    $export['getters'] = $this->exportObjectGetterValues($object, 0);
    return $export;
  }

  /**
   * @param object $object
   * @param int $depth
   * @param bool $public
   *   TRUE to only export public properties.
   *
   * @return array|string
   */
  protected function exportObjectProperties(object $object, int $depth, bool $public = false): array|string {
    $reflector = new ClassReflection($object);
    $properties = $reflector->getFilteredProperties(static: false, public: $public ?: null);
    $export = [];
    foreach ($properties as $property) {
      try {
        $propertyValue = $property->getValue($object);
      }
      catch (\Throwable) {
        $propertyValue = '(not initialized)';
      }
      $export['$' . $property->name] = $this->exportForYaml($propertyValue, $depth - 1);
    }
    return $export;
  }

  protected function exportObjectGetterValues(object $object, int $depth): array|string {
    $reflector = new ClassReflection($object);
    $result = [];
    foreach ($reflector->getFilteredMethods(static: false, public: true, constructor: false) as $method) {
      if ($method->hasRequiredParameters()
        || (string) ($method->getReturnType() ?? '?') === 'void'
        || (string) ($method->getReturnType() ?? '?') === 'never'
        || !\preg_match('#^(get|is|has)[A-Z]#', $method->name)
      ) {
        // This is not a getter method.
        continue;
      }
      $value = $object->{$method->name}();
      $result[$method->name . '()'] = $this->exportForYaml($value, $depth - 1);
    }
    \ksort($result);
    return $result;
  }

  protected function arrayDiffAssocStrict(array $a, array $b): array {
    $diff = \array_filter(
      $a,
      fn (mixed $v, string|int $k) => !\array_key_exists($k, $b)
        || $v !== $b[$k],
      \ARRAY_FILTER_USE_BOTH,
    );
    return $diff;
  }

}
