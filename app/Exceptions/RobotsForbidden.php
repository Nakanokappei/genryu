<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the global HTTP request middleware before a request is sent
 * to a URL the host's robots.txt does not allow.
 */
class RobotsForbidden extends RuntimeException {}
