<?php

declare(strict_types = 1);

namespace Ock\Testing\Tests\Fixtures;

class ClassWithDefaultObject {

  public function __construct(
    public readonly mixed $var = null,
  ) {}

}
