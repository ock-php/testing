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
   * Objects to export later.
   *
   * @var array<int, object>
   */
  private array $objects = [];

  /**
   * Object occurences by object id and depth.
   *
   * @var array<int, array<int, list<array{key: int|string|null, path: string, ref: mixed}>>>
   */
  private array $objectOccurences = [];

  /**
   * Path in the tree.
   *
   * E.g. '[abc]->def()->xyz'.
   *
   * @var string
   */
  private string $path = '';

  /**
   * Default objects, to reduce redundancy of the export.
   *
   * @var array<class-string, object|false>
   */
  private array $defaultObjects = [];

  /**
   * Default object factories.
   *
   * @var array<class-string, callable(string|int|null): (object)>
   */
  private array $defaultObjectFactories = [];

  /**
   * @template T of object
   *
   * @param class-string<T> $class
   * @param \Closure(T, int, string|int|null, self): (mixed|null) $exporter
   *   Dedicated export function for objects of the provided class.
   *   If that function returns NULL, the regular exporter is used instead.
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
      $export = $exporter->doExportObject($object, $depth, $key, true);
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
  public function withDefaultObject(object $reference, string $class = null): static {
    $clone = clone $this;
    $clone->defaultObjects[$class ?? get_class($reference)] = $reference;
    return $clone;
  }

  /**
   * @template T of object
   *
   * @param class-string $class
   * @param \Closure(string|int|null): T $factory
   *
   * @return static
   */
  public function withDefaultObjectFactory(string $class, \Closure $factory): static {
    $clone = clone $this;
    $clone->defaultObjectFactories[$class] = $factory;
    return $clone;
  }

  /**
   * {@inheritdoc}
   */
  public function export(mixed $value, int $depth = 2): mixed {
    // Don't pollute the main object cache in the main instance.
    return (clone $this)->doExport($value, $depth);
  }

  /**
   * @param mixed $value
   * @param int $depth
   *
   * @return mixed
   */
  public function doExport(mixed $value, int $depth): mixed {
    // Populate the object cache breadth-first.
    $export =& $this->exportRecursive($value, $depth, null);

    $canonical_object_paths = [];
    while ($objects = $this->objects) {
      $this->objects = [];
      foreach ($objects as $id => $object) {
        if (isset($canonical_object_paths[$id])) {
          continue;
        }
        $object_best_depth = max(array_keys($this->objectOccurences[$id]));
        $object_best_occurence = array_shift($this->objectOccurences[$id][$object_best_depth]);
        assert($object_best_occurence !== null);
        // Overwrite the reference.
        try {
          $original_path = $this->path;
          $this->path = $object_best_occurence['path'];
          $object_best_occurence['ref'] = $this->exportObject($object, $object_best_depth, $object_best_occurence['key']);
        }
        finally {
          $this->path = $original_path;
        }
        $canonical_object_paths[$id] = $object_best_occurence['path'];
      }
    }

    foreach ($this->objectOccurences as $id => $occurences_by_depth) {
      foreach ($occurences_by_depth as $occurences) {
        foreach ($occurences as $occurence) {
          // Overwrite the reference.
          $occurence['ref'] = ['_ref' => $canonical_object_paths[$id]];
        }
      }
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
  protected function &exportRecursive(mixed $value, int $depth, string|int|null $key): mixed {
    if (is_array($value)) {
      $result = $this->exportArrayRecursive($value, $depth);
    }
    elseif (is_object($value)) {
      $result =& $this->registerObject($value, $depth, $key);
    }
    elseif (\is_resource($value)) {
      $result = 'resource';
    }
    else {
      $result = $value;
    }

    return $result;
  }

  /**
   * @param array $value
   * @param int $depth
   *
   * @return mixed
   */
  protected function exportArrayRecursive(array $value, int $depth): mixed {
    if ($value === []) {
      $result = [];
      return $result;
    }
    if ($depth <= 0) {
      if (array_is_list($value)) {
        $result = '[...]';
      }
      else {
        $result = '{...}';
      }
      return $result;
    }
    $result = [];
    foreach ($value as $k => $v) {
      $parents = $this->path;
      $this->path .= '[' . $k . ']';
      try {
        $result[$k] =& $this->exportRecursive($v, $depth - 1, $k);
      }
      finally {
        $this->path = $parents;
      }
    }
    return $result;
  }

  /**
   * @param object $object
   * @param int $depth
   * @param string|int|null $key
   *
   * @return int
   */
  protected function &registerObject(object $object, int $depth, string|int|null $key): int {
    $id = spl_object_id($object);
    $this->objects[$id] = $object;
    $ref = $id;
    $this->objectOccurences[$id][$depth][] = ['key' => $key, 'path' => $this->path, 'ref' => &$ref];
    return $ref;
  }

  /**
   * @param object $object
   * @param int $depth
   * @param int|string|null $key
   *
   * @return mixed
   */
  protected function exportObject(object $object, int $depth, int|string|null $key = null): mixed {
    foreach ($this->exportersByClass as $class => $callback) {
      if ($object instanceof $class) {
        $export = $callback($object, $depth, $key, $this);
        if ($export !== null) {
          return $export;
        }
      }
    }
    return $this->doExportObject($object, $depth, $key);
  }

  /**
   * @param object $object
   * @param int $depth
   * @param int|string|null $key
   * @param bool $getters
   *
   * @return array
   */
  protected function doExportObject(object $object, int $depth, int|string|null $key = null, bool $getters = false): array {
    $export = ['class' => $this->exportObjectClassName($object)];
    $export += $this->exportObjectValues($object, $depth, $getters);
    $default_object = $this->getDefaultObject(get_class($object), $key);
    if ($default_object) {
      $default_export = $this->exportObjectValues($default_object, $depth, $getters);
      $export = static::arrayDiffAssocStrict($export, $default_export);
    }
    return $export;
  }

  /**
   * @param object $object
   * @param int $depth
   * @param bool $getters
   *
   * @return array
   */
  protected function exportObjectValues(object $object, int $depth, bool $getters = false): array {
    if ($depth <= 0) {
      return [];
    }
    if (!$getters) {
      return $this->exportObjectProperties($object, $depth - 1);
    }
    $export = $this->exportObjectProperties($object, $depth - 1, true);
    $export += $this->exportObjectGetterValues($object, $depth - 1);
    return $export;
  }

  /**
   * Exports the object's class name.
   *
   * @param object $object
   *
   * @return string
   */
  protected function exportObjectClassName(object $object): string {
    $reflectionClass = new \ReflectionClass($object);
    if ($reflectionClass->isAnonymous()) {
      if (\preg_match('#^class@anonymous\\0(/[^:]+:)\d+\$[0-9a-f]+$#', $reflectionClass->getName(), $matches)) {
        $path = $matches[1];
        // @todo Inject project root path from outside.
        $path = $this->stabilizePath($path);
        // Replace the line number and the hash-like suffix.
        // This will make the asserted value more stable.
        return 'class@anonymous:' . $path . ':**';
      }
    }
    return $reflectionClass->getName();
  }

  /**
   * @template T of object
   *
   * @param class-string<T> $class
   * @param int|string|null $key
   *
   * @return (object&T)|null
   */
  protected function getDefaultObject(string $class, int|string|null $key): object|null {
    if (isset($this->defaultObjectFactories[$class])) {
      $object = ($this->defaultObjectFactories[$class])($key);
      assert($object instanceof $class);
      return $object;
    }
    $object_or_false = $this->defaultObjects[$class]
      ??= ($this->createDefaultObject($class) ?? false);
    if (!$object_or_false) {
      return NULL;
    }
    assert($object_or_false instanceof $class);
    return $object_or_false;
  }

  /**
   * @template T of object
   *
   * @param class-string<T> $class
   *
   * @return (object&T)|null
   */
  protected function createDefaultObject(string $class): object|null {
    $rc = new \ReflectionClass($class);
    $constructor = $rc->getConstructor();
    if ($constructor && !$constructor->isPublic()) {
      return null;
    }
    $args = [];
    foreach (($constructor?->getParameters() ?? []) as $parameter) {
      if ($parameter->isOptional()) {
        break;
      }
      $type = $parameter->getType();
      if (!$type) {
        return null;
      }
      switch ($type->__toString()) {
        case 'string':
        case 'string|int':
        case 'int|string':
          $args[] = '?#?#?#*';
          break;

        default:
          return null;
      }
    }
    try {
      return new $class(...$args);
    }
    catch (\Throwable) {
      return null;
    }
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
        $export['$' . $property->name] =& $this->exportRecursive($propertyValue, $depth - 1, null);
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
   * @return array<string, mixed>
   */
  protected function exportObjectGetterValues(object $object, int $depth): array {
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
        $result[$method->name . '()'] =& $this->exportRecursive($value, $depth - 1, null);
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
