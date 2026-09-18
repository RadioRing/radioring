<?php

namespace App\Exceptions;

/**
 * The rundown of this hour has already been broadcast and is not overwritten.
 *
 * Its own type so a caller can tell it apart from a generation that really went wrong:
 * this one is an expected outcome and a retry would produce it again, everything else
 * belongs in the log and on the queue's retry path.
 */
class RundownAlreadyPlayedException extends \RuntimeException {}
