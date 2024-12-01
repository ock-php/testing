<?php

declare(strict_types=1);

namespace Ock\Testing\Exporter;

/**
 * Transform values suitable for an export storage.
 *
 * This has two purposes:
 * - Make the values suitable for the export storage.
 *   E.g. yaml cannot properly store objects.
 * - Remove "noise" that would cause test failures for irrelevant changes.
 *   (e.g. randomness, timestamps, external factors)
 * - Reduce unnecessary verbosity and detail.
 */
interface ExporterInterface {

  /**
   * Exports values for yaml.
   *
   * @param mixed $value
   * @param int $depth
   *   Maximum depth for recursive export.
   *
   * @return mixed
   *   Exported value.
   *   This won't contain any objects.
   */
  public function export(mixed $value, int $depth = 2): mixed;

}
