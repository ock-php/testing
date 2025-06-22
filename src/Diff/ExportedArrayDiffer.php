<?php

declare(strict_types = 1);

namespace Ock\Testing\Diff;

use Symfony\Component\Yaml\Tag\TaggedValue;

class ExportedArrayDiffer implements DifferInterface {

  protected array $nonListProperties = [];

  protected array $identifyingProperties = [];

  public function withNonListProperty(string $class, string $property): static {
    $clone = clone $this;
    $clone->nonListProperties[$class][$property] = TRUE;
    return $clone;
  }

  public function withIdentifyingProperty(string $class, string $property): static {
    $clone = clone $this;
    $clone->identifyingProperties[$class][$property] = TRUE;
    return $clone;
  }

  public function compare(array $before, array $after): array {
    if ($before === $after) {
      return [];
    }
    $diff = $this->compareArrays($before, $after);
    if ($diff === false) {
      return [
        '-' => $before,
        '+' => $after,
      ];
    }
    return $diff;
  }

  /**
   * Compares two values.
   *
   * @param mixed $before
   * @param mixed $after
   * @param bool $could_be_list
   *
   * @return array|false
   *   FALSE if the values are completely different.
   *   Empty array, if the values are identical.
   *   Otherwise, a non-empty array with diff information.
   */
  protected function compareValues(mixed $before, mixed $after, bool $could_be_list = true): array|false {
    if ($before === $after) {
      return [];
    }
    if (!is_array($before) || !is_array($after)) {
      return false;
    }
    return $this->compareArrays($before, $after, $could_be_list);
  }

  /**
   * Compares two exported arrays.
   *
   * Note that these arrays could be the result of an object export.
   *
   * @param array $before
   * @param array $after
   * @param bool $could_be_list
   *
   * @return array|false
   *   FALSE if the values are completely different or from different objects.
   *   Empty array, if the values are identical.
   *   Otherwise, a non-empty array with diff information.
   */
  protected function compareArrays(array $before, array $after, bool $could_be_list = true): array|false {
    if ($could_be_list && array_is_list($before) && array_is_list($after)) {
      return $this->compareLists($before, $after);
    }
    if (array_key_first($before) === 'class' && array_key_first($after) === 'class'
      && is_string($before['class']) && is_string($after['class'])
      && preg_match(
        '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*$/',
        $before['class'],
      )
      && preg_match(
        '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*$/',
        $after['class'],
      )
    ) {
      if ($before['class'] !== $after['class']) {
        // Don't go deeper if the two objects have a different class.
        return false;
      }
      return $this->compareExportedObjects($before, $after, $before['class']);
    }
    return $this->compareAssoc($before, $after);
  }

  /**
   * Compares two lists.
   *
   * @param list<mixed> $before
   * @param list<mixed> $after
   *
   * @return array|false
   */
  protected function compareLists(array $before, array $after): array|false {
    $diff = $this->doCompareLists($before, $after);
    if (!$diff || count($diff) === count($before) + count($after)) {
      // The two lists are completely different.
      return false;
    }
    return $diff;
  }

  /**
   * Compares two lists recursively.
   *
   * @param list<mixed> $before
   * @param list<mixed> $after
   * @param int $i_before
   * @param int $i_after
   *
   * @return array
   */
  protected function doCompareLists(array $before, array $after, int $i_before = 0, int $i_after = 0): array {
    $diff = [];
    while (true) {
      if ($i_before >= count($before)) {
        // There are more items in "after" list.
        for (; $i_after < count($after); ++$i_after) {
          $diff[] = new TaggedValue('add', $after[$i_after]);
        }
        return $diff;
      }
      if ($i_after >= count($after)) {
        // There are more items in "before" list.
        for (; $i_before < count($before); ++$i_before) {
          $diff[] = new TaggedValue('rm', $before[$i_before]);
        }
        return $diff;
      }
      $item_diff = $this->compareValues($before[$i_before], $after[$i_after]);
      if ($item_diff === []) {
        // The two values are the same.
        ++$i_before;
        ++$i_after;
        continue;
      }
      // The two items are completely different.
      $diff_minus = $this->doCompareLists($before, $after, $i_before + 1, $i_after);
      $diff_plus = $this->doCompareLists($before, $after, $i_before, $i_after + 1);
      if ($item_diff !== false) {
        $diff_eq = $this->doCompareLists($before, $after, $i_before + 1, $i_after + 1);
        if (count($diff_eq) < count($diff_minus) && count($diff_eq) < count($diff_plus)) {
          return [
            ...$diff,
            new TaggedValue('diff', $item_diff),
            ...$diff_eq,
          ];
        }
      }
      if (count($diff_minus) <= count($diff_plus)) {
        return [
          ...$diff,
          new TaggedValue('rm', $before[$i_before]),
          ...$diff_minus,
        ];
      }
      else {
        return [
          ...$diff,
          new TaggedValue('add', $after[$i_after]),
          ...$diff_plus,
        ];
      }
    }
  }

  protected function compareAssoc(array $before, array $after): array|false {
    // @todo Also compare order of keys?
    ksort($before);
    ksort($after);
    $shared_keys = array_intersect(
      array_keys($before),
      array_keys($after),
    );
    if (!$shared_keys) {
      return false;
    }
    $diff = [];
    $similar = false;
    foreach (array_diff_key($before, $after) as $key => $item) {
      $diff[$key] = new TaggedValue('rm', $item);
    }
    foreach ($shared_keys as $key) {
      $item_diff = $this->compareValues($before[$key], $after[$key]);
      if ($item_diff === false) {
        $diff[$key] = new TaggedValue('replace', $after[$key]);
      }
      elseif ($item_diff) {
        $diff[$key] = new TaggedValue('diff', $item_diff);
        $similar = true;
      }
      else {
        $similar = true;
      }
    }
    if (!$similar) {
      return false;
    }
    foreach (array_diff_key($after, $before) as $key => $item) {
      $diff[$key] = new TaggedValue('add', $item);
    }
    return $diff;
  }

  protected function compareExportedObjects(array $before, array $after, string $class): array|false {
    unset($before['class'], $after['class']);
    $info = ['class' => $class];
    if (isset($this->identifyingProperties[$class])) {
      $info_before = array_intersect_key($before, $this->identifyingProperties[$class]);
      $info_after = array_intersect_key($after, $this->identifyingProperties[$class]);
      if ($info_before !== $info_after) {
        return $this->compareAssoc($before, $after);
      }
      $info += $info_before;
    }
    $shared_keys = array_intersect(
      array_keys($before),
      array_keys($after),
    );
    $diff = [];
    foreach (array_diff_key($before, $after) as $key => $item) {
      $diff[$key] = new TaggedValue('rm', $item);
    }
    foreach ($shared_keys as $key) {
      $item_diff = $this->compareExportedObjectProperty($class, $key, $before[$key], $after[$key]);
      if ($item_diff === false) {
        $diff[$key] = new TaggedValue('replace', $after[$key]);
      }
      elseif ($item_diff) {
        $diff[$key] = new TaggedValue('diff', $item_diff);
      }
    }
    if (!$diff) {
      return [];
    }
    return $info + $diff;
  }

  protected function compareExportedObjectProperty(string $class, string $property, mixed $before, mixed $after): array|null|false|TaggedValue {
    if (is_array($before) && is_array($after)
      && array_is_list($before) && array_is_list($after)
      && isset($this->nonListProperties[$class][$property])
    ) {
      // Suppress list.
      return $this->compareValues($before, $after, false);
    }
    return $this->compareValues($before, $after);
  }

}
