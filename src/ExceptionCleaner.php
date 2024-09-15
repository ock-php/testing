<?php

declare(strict_types=1);

namespace Ock\Testing;

/**
 * Cleans up exception objects so they can be serialized.
 *
 * See https://git.drupalcode.org/project/drupal/-/commit/4cffbd777c827b7fa9b00977b75e6148f947c701?merge_request_iid=4238
 */
class ExceptionCleaner {

  /**
   * Modifies an exception to make it serializable.
   *
   * @param \Throwable $exception
   *   Exception or error to clean up.
   *
   * @throws \Throwable
   */
  public function cleanException(\Throwable $exception): void {
    // Look for properties that can be cleaned.
    $rc = new \ReflectionClass($exception);
    $properties = $this->getAllProperties($rc);
    foreach ($properties as $property) {
      $value = $property->getValue($exception);
      if ($value instanceof \Throwable) {
        $this->cleanException($value);
      }
      elseif ($property->name === 'trace' && is_array($value)) {
        $this->cleanArray($value);
        $property->setValue($exception, $value);
      }
      try {
        serialize($value);
      }
      catch (\Throwable $e) {
        throw new \RuntimeException(sprintf(
          'Property %s of %s is not serializable: %s',
          $property->name,
          $rc->name,
          $e->getMessage(),
        ), 0, $e);
      }
    }
    try {
      serialize($exception);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf(
        'The %s object is still serializable after cleaning: %s',
        $rc->name,
        $e->getMessage(),
      ), 0, $e);
    }
  }

  /**
   * Gets all properties of a given class.
   *
   * This includes private properties from parent classes.
   *
   * @param \ReflectionClass $reflection_class
   *   Class to get properties for.
   *
   * @return list<\ReflectionProperty>
   *   All the properties.
   */
  protected function getAllProperties(\ReflectionClass $reflection_class): array {
    $properties_by_level = [$reflection_class->getProperties()];
    while ($reflection_class = $reflection_class->getParentClass()) {
      $properties_by_level[] = $reflection_class->getProperties(\ReflectionProperty::IS_PRIVATE);
    }
    return \array_merge(...$properties_by_level);
  }

  /**
   * Cleans an array to make it serializable.
   *
   * @param array $array
   *   The array to clean up.
   */
  private function cleanArray(array &$array): void {
    foreach ($array as $key => &$value) {
      if ($value instanceof \Closure) {
        $value = $this->replaceClosure($value);
      }
      elseif ($value instanceof \Reflector) {
        $value = $this->replaceReflector($value);
      }
      elseif (is_array($value)) {
        $this->cleanArray($value);
      }
      elseif (is_object($value)) {
        try {
          $serialized = serialize($value);
          unserialize($serialized);
        }
        catch (\Throwable) {
          // Cannot serialize or unserialize this object.
          // Insert a placeholder.
          $value = '{' . get_class($value) . ' object}';
        }
      }
      try {
        serialize($value);
      }
      catch (\Throwable $e) {
        throw new \RuntimeException(sprintf(
          'The %s array value at %s is still not serializable after cleaning: %s',
          \get_debug_type($value),
          var_export($key, TRUE),
          $e->getMessage(),
        ), 0, $e);
      }
    }
    try {
      serialize($array);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf(
        'The array is still serializable after cleaning: %s',
        $e->getMessage(),
      ), 0, $e);
    }
  }

  /**
   * Replaces a closure with a descriptive string.
   *
   * @param \Closure $closure
   *   Closure.
   *
   * @return string
   *   String to insert instead of the closure.
   */
  private function replaceClosure(\Closure $closure): string {
    $rf = new \ReflectionFunction($closure);
    return sprintf(
      '{closure: %s:%d}',
      $rf->getFileName(),
      $rf->getStartLine(),
    );
  }

  /**
   * Replaces a reflector with a descriptive string.
   *
   * @param \Reflector $reflector
   *   Reflector.
   *
   * @return string
   *   String to insert instead of the reflector.
   */
  private function replaceReflector(\Reflector $reflector): string {
    return '{' . get_class($reflector) . '}';
  }

}
