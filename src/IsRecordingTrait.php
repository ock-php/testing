<?php

declare(strict_types = 1);

namespace Ock\Testing;

trait IsRecordingTrait {

  /**
   * Checks whether the test runs in "recording" mode.
   *
   * @return bool
   *   TRUE if the test runs in "recording" mode.
   */
  protected function isRecording(): bool {
    return !!\getenv('UPDATE_TESTS');
  }

}
