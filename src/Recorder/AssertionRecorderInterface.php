<?php

declare(strict_types=1);

namespace Ock\Testing\Recorder;

/**
 * Object used for recorded assertions.
 */
interface AssertionRecorderInterface {

  /**
   * Asserts that a value is the same as a previously recorded value.
   *
   * @param mixed $actual
   *   The actual value to compare.
   */
  public function assertValue(mixed $actual): void;

  /**
   * Asserts that recorded values end here.
   */
  public function assertEnd(): void;

}
