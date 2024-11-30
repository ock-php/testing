<?php

declare(strict_types = 1);

namespace Ock\Testing\Snapshotter;

use Ock\Testing\Diff\DifferInterface;

class DiffingMultiSnapshotter implements SnapshotterInterface, DifferInterface {

  /**
   * Constructor.
   *
   * @param array<string, \Ock\Testing\Snapshotter\SnapshotterInterface> $snapshotters
   * @param \Ock\Testing\Diff\DifferInterface $differ
   *   Fallback differ.
   */
  public function __construct(
    private readonly array $snapshotters,
    private readonly DifferInterface $differ,
  ) {}

  #[\Override]
  public function takeSnapshot(): array {
    return array_map(
      fn (SnapshotterInterface $snapshotter) => $snapshotter->takeSnapshot(),
      $this->snapshotters,
    );
  }

  #[\Override]
  public function compare(array $before, array $after): array {
    $diff = [];
    foreach ($this->snapshotters as $key => $snapshotter) {
      assert(is_array($before[$key] ?? null));
      assert(is_array($after[$key] ?? null));
      if ($snapshotter instanceof DifferInterface) {
        $diff[$key] = $snapshotter->compare($before[$key], $after[$key]);
      }
      else {
        $diff[$key] = $this->differ->compare($before[$key], $after[$key]);
      }
    }
    return $diff;
  }

}
