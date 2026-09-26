<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown before a request to a URL that is not http(s) or whose host is not a public address. */
class PrivateAddressForbidden extends RuntimeException {}
