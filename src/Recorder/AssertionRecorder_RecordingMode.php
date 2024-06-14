<?php

declare(strict_types=1);

namespace Ock\Testing\Recorder;

/**
 * Assertion recorder used in recording mode.
 */
class AssertionRecorder_RecordingMode implements AssertionRecorderInterface {

  /**
   * @var list<mixed>
   */
  private array $values = [];

  /**
   * Constructor.
   *
   * @param \Closure(list<mixed>): void $save
   *   Callback to save values.
   */
  public function __construct(
    private readonly \Closure $save,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assertValue(mixed $actual): void {
    $this->values[] = $actual;
  }

  /**
   * {@inheritdoc}
   */
  public function assertEnd(): void {
    ($this->save)($this->values);
  }

}
