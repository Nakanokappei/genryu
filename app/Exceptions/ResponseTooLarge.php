<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a response body grows past the size accepted for a fetch. */
class ResponseTooLarge extends RuntimeException {}
