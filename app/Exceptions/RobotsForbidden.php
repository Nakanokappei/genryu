<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown before a request to a URL the host's robots.txt disallows. */
class RobotsForbidden extends RuntimeException {}
