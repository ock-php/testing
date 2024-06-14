<?php

declare(strict_types=1);

namespace Ock\Testing\Recorder;

use PHPUnit\Framework\Assert;

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
    Assert::assertSame($expected, $actual);
  }

  /**
   * {@inheritdoc}
   */
  public function assertEnd(): void {
    Assert::assertCount($this->recordingIndex, $this->expected ?? [], 'Premature end.');
  }

}
