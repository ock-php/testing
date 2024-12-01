<?php

declare(strict_types=1);

namespace Ock\Testing;

use Ock\Testing\Exporter\Exporter_ToYamlArray;
use Ock\Testing\Exporter\ExporterInterface;
use Ock\Testing\Recorder\AssertionRecorder_RecordingMode;
use Ock\Testing\Recorder\AssertionRecorder_ReplayMode;
use Ock\Testing\Recorder\AssertionRecorderInterface;
use Ock\Testing\Storage\AssertionValueStore_Yaml;
use Ock\Testing\Storage\AssertionValueStoreInterface;
use PHPUnit\Framework\Attributes\After;

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

  private ?AssertionRecorderInterface $recorder = null;

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
   * Asserts that an array of objects is as recorded.
   *
   * The method helps to remove default values from object exports, and make the
   * recording less verbose and repetitive.
   *
   * @param mixed[] $objects
   *   Array of objects or other values.
   *   Only objects are treated with the noise removal.
   * @param string|null $label
   *   Label to include in the recording for this item, e.g. as a yaml key.
   * @param int $depth
   *   How deep into the object to go.
   * @param string|null $defaultClass
   *   Omit the 'class' if identical to the default class.
   * @param bool $arrayKeyIsDefaultClass
   *   Whether to omit the 'class' key, if identical to array key.
   * @param string|null $arrayKeyIsDefaultFor
   *   Result property to omit if identical to array key.
   */
  protected function assertObjectsAsRecorded(
    array $objects,
    string $label = null,
    int $depth = 15,
    string $defaultClass = null,
    bool $arrayKeyIsDefaultClass = false,
    string $arrayKeyIsDefaultFor = null,
  ): void {
    $export = $this->exportForYaml($objects, null, $depth);
    \assert(is_array($export));
    foreach ($export as $key => $item) {
      if (($item['class'] ?? false) === $defaultClass
        || ($arrayKeyIsDefaultClass && ($item['class'] ?? false) === $key)
      ) {
        unset($export[$key]['class']);
      }
      if ($arrayKeyIsDefaultFor !== null && ($item[$arrayKeyIsDefaultFor] ?? false) === $key) {
        unset($export[$key][$arrayKeyIsDefaultFor]);
      }
    }
    $this->assertAsRecorded($export, $label, $depth + 2);
  }

  /**
   * Asserts that a value is the same as a previously recorded value.
   *
   * @param mixed $actual
   *   The actual value to compare.
   * @param string|null $label
   *   A key or message to add to the value.
   * @param int $depth
   *   Depth for yaml export.
   */
  public function assertAsRecorded(mixed $actual, string $label = null, int $depth = 9): void {
    $actual = $this->exportForYaml($actual, $label, $depth);
    $this->recorder ??= $this->createRecorder();
    $this->recorder->assertValue($actual);
  }

  #[After]
  public function tearDownRecorder(): void {
    if ($this->status()->isSuccess()) {
      $this->recorder ??= $this->createRecorder();
      $this->recorder->assertEnd();
    }
  }

  /**
   * Creates the recorder object.
   *
   * This can be overridden in test classes, if needed.
   */
  protected function createRecorder(): AssertionRecorderInterface {
    $name = $this->name();
    $dataName = $this->dataName();
    if ($dataName !== '') {
      $name .= '-' . $dataName;
    }
    $storage = $this->createAssertionStore();
    if ($this->isRecording()) {
      $recorder = new AssertionRecorder_RecordingMode(
        fn ($values) => $storage->save($name, $values),
      );
    }
    else {
      $recorder = new AssertionRecorder_ReplayMode(
        fn () => $storage->load($name),
      );
    }
    // @todo Add exporter decorator.
    return $recorder;
  }

  /**
   * Creates a storage for the assertion recorder.
   */
  protected function createAssertionStore(): AssertionValueStoreInterface {
    $reflection_class = new \ReflectionClass(static::class);
    $class_file = $reflection_class->getFileName();
    if ($class_file === false) {
      throw new \RuntimeException(sprintf("No class file for '%s'.", static::class));
    }
    /** @var non-empty-list<string> $parts */
    $parts = \explode('\\', static::class);
    $parts_count = count($parts);
    if ($parts_count < 3) {
      throw new \RuntimeException(sprintf("Namespace of class '%s' is too shallow.", static::class));
    }
    $package_dir = dirname($class_file, $parts_count - 2);
    $base = implode('/', [
      $package_dir,
      'recordings',
      ...array_slice($parts, 3),
    ]) . '-';
    return new AssertionValueStore_Yaml(
      $base,
      $this->buildYamlHeader(...),
    );
  }

  /**
   * Builds a header for the yaml file.
   *
   * The header contains metadata about the test.
   *
   * @return array
   */
  protected function buildYamlHeader(): array {
    $header = [
      'test' => static::class . '::' . $this->name() . '()',
    ];
    $dataName = $this->dataName();
    if ($dataName !== '') {
      $header['dataset name'] = $dataName;
    }
    return $header;
  }

  /**
   * Exports values for yaml.
   *
   * @param mixed $value
   * @param string|null $label
   *   Label to add to the value.
   * @param int $depth
   *   Maximum depth for recursive export.
   *
   * @return mixed
   *   Exported value.
   *   This won't contain any objects.
   */
  protected function exportForYaml(mixed $value, string|null $label, int $depth): mixed {
    $export = $this->createExporter()->export($value, $depth);
    if ($label !== null) {
      $export = [$label => $export];
    }
    return $export;
  }

  /**
   * Creates an exporter to process asserted values.
   */
  protected function createExporter(): ExporterInterface {
    return new Exporter_ToYamlArray();
  }

}
