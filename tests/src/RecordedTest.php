<?php

declare(strict_types=1);

namespace Ock\Testing\Tests;

use Ock\Testing\RecordedTestTrait;
use PHPUnit\Framework\TestCase;

class RecordedTest extends TestCase {

  use RecordedTestTrait;

  /**
   * Tests recorded values.
   */
  public function testRecordedValues(): void {
    $this->assertAsRecorded(5);
    $this->assertAsRecorded(7, 'The number seven');
    $this->assertAsRecorded(new \stdClass(), 'empty object');
    $this->assertAsRecorded([], 'empty array');
    $this->assertAsRecorded((object) ['x' => 'X'], 'stdClass object with a property');
    $this->assertAsRecorded(new class () {}, 'anonymous class');
    $this->assertAsRecorded(new class () {
      public int $x = 5;
    }, 'anonymous class with property');
    $object = new class () {};
    $this->assertObjectsAsRecorded([
      [$object],
      $object,
    ]);
  }

}
