<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Exception;

/**
 * Thrown from a DossierBuilder progress callback to stop the build: the
 * export job no longer exists or is no longer processing.
 */
class DossierExportAbortedException extends \RuntimeException
{
}
