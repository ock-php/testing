<?php

declare(strict_types=1);

namespace Ock\Testing\Tests;

use Ock\Testing\Exporter\Exporter_ToYamlArray;
use Ock\Testing\Exporter\ExporterInterface;
use Ock\Testing\RecordedTestTrait;
use Ock\Testing\Tests\Fixtures\ClassWithDefaultObject;
use Ock\Testing\Tests\Fixtures\ExampleObject;
use PHPUnit\Framework\TestCase;

class ExporterTest extends TestCase {

  use RecordedTestTrait;

  /**
   * Tests recorded values with nested objects.
   */
  public function testNestedObjects(): void {
    $this->assertObjectsAsRecorded([
      new ExampleObject(new ExampleObject(5)),
      new ClassWithDefaultObject(new ExampleObject(5)),
    ]);
  }

  protected function createExporter(): ExporterInterface {
    return (new Exporter_ToYamlArray())
      ->withDefaultObject(new ClassWithDefaultObject(new ExampleObject()));
  }

}
