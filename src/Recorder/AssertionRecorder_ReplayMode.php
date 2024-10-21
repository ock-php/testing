<?php

declare(strict_types=1);

namespace Ock\Testing\Recorder;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Assertion recorder used in regular mode.
 */
class AssertionRecorder_ReplayMode implements AssertionRecorderInterface {

  private ?array $expected = null;

  private int $recordingIndex = 0;

  /**
   * Constructor.
   *
   * @param \Closure(): list<mixed> $load
   */
  public function __construct(
    private readonly \Closure $load,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assertValue(mixed $actual): void {
    $this->expected ??= ($this->load)();
    Assert::assertArrayHasKey($this->recordingIndex, $this->expected, 'Unexpected assertion.');
    $expected = $this->expected[$this->recordingIndex];
    ++$this->recordingIndex;
    Assert::assertSame(
      // Use yaml to avoid diff noise from array keys in lists.
      // Prepend a line break so that the starting quote will be on a new line.
      // The end quote will already be a new line.
      "\n" . Yaml::dump($expected, 99, 2),
      "\n" . Yaml::dump($actual, 99, 2),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function assertEnd(): void {
    Assert::assertCount($this->recordingIndex, $this->expected ?? [], 'Premature end.');
  }

}
