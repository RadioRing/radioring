<?php

namespace App\Services;

use RuntimeException;

/**
 * A broadcast file could not be downloaded. The message is operator-facing: it ends up
 * in ExternalSource::last_error and is shown in the external source library.
 */
class RemoteFetchException extends RuntimeException {}
