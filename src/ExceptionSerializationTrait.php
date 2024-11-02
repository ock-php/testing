<?php

declare(strict_types=1);

namespace Ock\Testing;

/**
 * Trait that cleans up exceptions and test failures, to make them serializable.
 *
 * This is relevant for tests with process isolation, if a backtrace contains
 * values that cannot be serialized, such as closures, reflectors etc.
 *
 * Actually most of the exceptions in PhpUnit _are_ serializable. They have
 * __sleep() methods that prevent the stack trace from being serialized.
 *
 * However, there is at least one exception where this is not the case.
 */
trait ExceptionSerializationTrait {

  /**
   * {@inheritdoc}
   */
  protected function onNotSuccessfulTest(\Throwable $t): never {
    $cleaner = new ExceptionCleaner();
    $cleaner->cleanException($t);
    /* @see \PHPUnit\Framework\TestCase::onNotSuccessfulTest() */
    parent::onNotSuccessfulTest($t);
  }

}
