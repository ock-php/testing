<?php

declare(strict_types=1);

namespace Ock\Testing\Exporter;

use Ock\ClassDiscovery\Reflection\ClassReflection;

/**
 * Exports as array suitable for a yaml file.
 */
class Exporter_ToYamlArray implements ExporterInterface {

  /**
   * Dedicated exporters by class name.
   *
   * The first parameter will accept instances of the class from the respective
   * array key.
   * The correct parameter type to specify here would be 'never', following the
   * idea of contravariance.
   * However, this would not take us very far with PhpStan.
   * For practical reasons, we pretend that all the callbacks accept any object
   * as first parameter.
   *
   * @var array<class-string, \Closure(object, int, string|int|null, self): mixed>
   */
  private array $exportersByClass = [];

  /**
   * Object paths as array keys by object hash.
   *
   * @var array<int, string>
   */
  private array $objectPaths = [];

  /**
   * Path in the tree.
   *
   * E.g. '[abc]->def()->xyz'.
   *
   * @var string
   */
  private string $path = '';

  /**
   * @template T of object
   *
   * @param class-string<T> $class
   * @param \Closure(T, int, string|int|null, self): mixed $exporter
   *
   * @return static
   */
  public function withDedicatedExporter(string $class, \Closure $exporter): static {
    $clone = clone $this;
    // Pretend that the first parameter of $exporter allows any object.
    /** @var \Closure(object, int, string|int|null, self): mixed $exporter */
    $clone->exportersByClass[$class] = $exporter;
    return $clone;
  }

  /**
   * @param class-string $class
   * @param array $keys_to_unset
   *
   * @return static
   */
  public function withObjectGetters(string $class, array $keys_to_unset = []): static {
    return $this->withDedicatedExporter($class, static function(
      object $object,
      int $depth,
      string|int|null $key,
      self $exporter,
    ) use ($keys_to_unset): array {
      $export = $exporter->doExportObject($object, $depth, true);
      foreach ($keys_to_unset as $key) {
        unset($export[$key]);
      }
      return $export;
    });
  }

  /**
   * @template T of object
   *
   * @param T&object $reference
   * @param class-string<T>|null $class
   *
   * @return static
   */
  public function withReferenceObject(object $reference, string $class = null): static {
    $class ??= \get_class($reference);
    $decorated = $this->exportersByClass[$class] ?? fn (
      object $object,
      int $depth,
      string|int|null $key,
      self $exporter,
    ): array => $exporter->doExportObject($object, $depth);
    return $this->withDedicatedExporter($class, static function (
      object $object,
      int $depth,
      string|int|null $key,
      self $exporter,
    ) use ($reference, $decorated): array {
      $compare_export = $decorated($reference, $depth, $key, $exporter);
      $export = $decorated($object, $depth, $key, $exporter);
      assert(is_array($export));
      assert(is_array($compare_export));
      unset($compare_export['class']);
      return static::arrayDiffAssocStrict($export, $compare_export);
    });
  }

  /**
   * @template T of object
   *
   * @param class-string $class
   * @param \Closure(string|int|null): T $factory
   *
   * @return static
   */
  public function withReferenceObjectFactory(string $class, \Closure $factory): static {
    $decorated = $this->exportersByClass[$class] ?? fn (
      object $object,
      int $depth,
      string|int|null $key,
      self $exporter,
    ): array => $exporter->doExportObject($object, $depth);
    return $this->withDedicatedExporter($class, static function(
      object $object,
      int $depth,
      string|int|null $key,
      self $exporter,
    ) use ($factory, $decorated): array {
      $reference = $factory($key);
      $compare_export = $decorated($reference, $depth, $key, $exporter);
      $export = $decorated($object, $depth, $key, $exporter);
      assert(is_array($export));
      assert(is_array($compare_export));
      unset($compare_export['class']);
      return static::arrayDiffAssocStrict($export, $compare_export);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function export(mixed $value, string $label = null, int $depth = 2): mixed {
    // Don't pollute the main object cache in the main instance.
    $clone = clone $this;
    // Populate the object cache breadth-first.
    for ($i = 0; $i < $depth; ++$i) {
      $clone->exportRecursive($value, $i, null);
    }
    $export = $clone->exportRecursive($value, $depth, null);
    if ($label !== null) {
      $export = [$label => $export];
    }
    return $export;
  }

  /**
   * @param mixed $value
   * @param int $depth
   * @param string|int|null $key
   *
   * @return mixed
   */
  protected function exportRecursive(mixed $value, int $depth, string|int|null $key): mixed {
    if (\is_array($value)) {
      if ($value === []) {
        return [];
      }
      if ($depth <= 0) {
        if (array_is_list($value)) {
          return '[...]';
        }
        else {
          return '{...}';
        }
      }
      $result = [];
      foreach ($value as $k => $v) {
        $parents = $this->path;
        $this->path .= '[' . $k . ']';
        try {
          $result[$k] = $this->exportRecursive($v, $depth - 1, $k);
        }
        finally {
          $this->path = $parents;
        }
      }
      return $result;
    }
    if (is_object($value)) {
      return $this->exportObject($value, $depth, $key);
    }
    if (\is_resource($value)) {
      return 'resource';
    }
    return $value;
  }

  /**
   * @param object $object
   * @param int $depth
   * @param int|string|null $key
   *
   * @return mixed
   */
  protected function exportObject(object $object, int $depth, int|string|null $key = null): mixed {
    $id = spl_object_id($object);
    $known_path = $this->objectPaths[$id] ?? null;
    if ($known_path === NULL) {
      $this->objectPaths[$id] = $this->path;
    }
    elseif ($known_path !== $this->path) {
      return ['_ref' => $known_path];
    }
    foreach ($this->exportersByClass as $class => $callback) {
      if ($object instanceof $class) {
        return $callback($object, $depth, $key, $this);
      }
    }
    return $this->doExportObject($object, $depth);
  }

  /**
   * @param object $object
   * @param int $depth
   * @param bool $getters
   *
   * @return array
   */
  protected function doExportObject(object $object, int $depth, bool $getters = false): array {
    $reflectionClass = new \ReflectionClass($object);
    $classNameExport = $reflectionClass->getName();
    if ($reflectionClass->isAnonymous()) {
      if (\preg_match('#^class@anonymous\\0(/[^:]+:)\d+\$[0-9a-f]+$#', $classNameExport, $matches)) {
        $path = $matches[1];
        // @todo Inject project root path from outside.
        $path = $this->stabilizePath($path);
        // Replace the line number and the hash-like suffix.
        // This will make the asserted value more stable.
        $classNameExport = 'class@anonymous:' . $path . ':**';
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
    return $export;
  }

  /**
   * Stabilizes a path for use in a test recording.
   *
   * The goal is that a test should have the same recorded data no matter how it
   * is run.
   *
   * @param string $path
   *   Original path.
   *
   * @return string
   *   Stabilized string that represents the path.
   */
  protected function stabilizePath(string $path): string {
    if (!str_starts_with($path, '/')
      || substr_count($path, '/') < 3
    ) {
      return $path;
    }
    if (str_ends_with($path, '/')) {
      $suffix = '/';
      $base = substr($path, 0, -1);
    }
    elseif (!is_dir($path)) {
      $suffix = '/' . basename($path);
      $base = dirname($path);
    }
    else {
      $suffix = '';
      $base = $path;
    }
    while (true) {
      if ($base === '/') {
        return $path;
      }
      if (file_exists($base . '/composer.json') && is_readable($base . '/composer.json')) {
        $json = file_get_contents($base . '/composer.json');
        // We know at this point that composer.json exists, and we assume it is
        // readable. So we expect that file_get_contents() does not return
        // false.
        assert($json !== false, "Failed to read '$base/composer.json'.");
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $package_name = json_decode($json, true)['name'] ?? NULL;
        assert(is_string($package_name));
        return "[$package_name]$suffix";
      }
      $suffix = '/' . basename($base) . $suffix;
      $base = dirname($base);
    }
  }

  /**
   * @param object $object
   * @param int $depth
   * @param bool $public
   *   TRUE to only export public properties.
   *
   * @return array<string, mixed>
   */
  protected function exportObjectProperties(object $object, int $depth, bool $public = false): array {
    $reflector = new ClassReflection($object);
    $properties = $reflector->getFilteredProperties(static: false, public: $public ?: null);
    $export = [];
    foreach ($properties as $property) {
      if ($property->isInitialized($object)) {
        $propertyValue = $property->getValue($object);
      }
      else {
        $propertyValue = '(not initialized)';
      }
      $parents = $this->path;
      $this->path .= '->' . $property->name;
      try {
        $export['$' . $property->name] = $this->exportRecursive($propertyValue, $depth - 1, null);
      }
      finally {
        $this->path = $parents;
      }
    }
    return $export;
  }

  /**
   * @param object $object
   * @param int $depth
   *
   * @return array|string
   */
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
      $parents = $this->path;
      $this->path .= '->' . $method->name . '()';
      try {
        $result[$method->name . '()'] = $this->exportRecursive($value, $depth - 1, null);
      }
      finally {
        $this->path = $parents;
      }
    }
    \ksort($result);
    return $result;
  }

  /**
   * @param array $a
   * @param array $b
   *
   * @return array
   */
  protected static function arrayDiffAssocStrict(array $a, array $b): array {
    $diff = \array_filter(
      $a,
      fn (mixed $v, string|int $k) => !\array_key_exists($k, $b)
        || $v !== $b[$k],
      \ARRAY_FILTER_USE_BOTH,
    );
    return $diff;
  }

}
