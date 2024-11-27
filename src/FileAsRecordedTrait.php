<?php

declare(strict_types = 1);

namespace Ock\Testing;

trait FileAsRecordedTrait {

  /**
   * Asserts that a file is as recorded.
   *
   * @param string $file
   *   File path.
   * @param string|null $content
   *   New file content.
   */
  protected function assertFileAsRecorded(string $file, string|null $content): void {
    if ($this->isRecording()) {
      if ($content === null) {
        if (file_exists($file)) {
          unlink($file);
        }
      }
      else {
        if (!is_dir(dirname($file))) {
          mkdir(dirname($file), recursive: true);
        }
        file_put_contents($file, $content);
      }
      $this->addToAssertionCount(1);
    }
    else {
      if (!file_exists($file)) {
        $this->assertNull($content, "File '$file' is missing.");
      }
      else {
        $expected = file_get_contents($file);
        $this->assertSame($expected, $content, "Content in '$file'.");
      }
    }
  }

  /**
   * Determines if the test is in recording mode.
   *
   * @return bool
   *   TRUE if in recording mode, FALSE if in replay mode.
   */
  abstract protected function isRecording(): bool;

}
