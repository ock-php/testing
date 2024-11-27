<?php

declare(strict_types = 1);

namespace Ock\Testing\Diff;

interface DifferInterface {

  /**
   * Creates a diff between two arrays.
   *
   * @param array $before
   * @param array $after
   *
   * @return array
   */
  public function compare(array $before, array $after): array;

}
