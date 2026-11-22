<?php

declare(strict_types=1);

namespace Esoul\Guidelines;

use RuntimeException;

/** A user-facing error (bad config, unknown layer, unterminated block...). */
final class GuidelinesException extends RuntimeException
{
}
