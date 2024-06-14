<?php

declare(strict_types=1);

namespace Ock\Testing\Storage;

interface AssertionValueStoreInterface {

  /**
   * Saves collected assertion values.
   *
   * @param string $name
   *   Name reflecting test method and dataset name.
   * @param list<mixed> $values
   *   Values from all recording assertions in this test method call.
   */
  public function save(string $name, array $values): void;

  /**
   * Loads pre-recorded assertion values.
   *
   * @param string $name
   *   Name reflecting test method and dataset name.
   *
   * @return list<mixed>
   *   Values from all recording assertions recorded in a previous call.
   */
  public function load(string $name): array;

  /**
   * Gets a list of stored test names.
   *
   * @return list<string>
   *   Names reflecting test method and dataset name.
   */
  public function getStoredNames(): array;

}
