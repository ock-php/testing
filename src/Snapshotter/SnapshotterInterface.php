<?php

declare(strict_types = 1);

namespace Ock\Testing\Snapshotter;

interface SnapshotterInterface {

  /**
   * Takes an exportable snapshot.
   *
   * @return array
   */
  public function takeSnapshot(): array;

}
