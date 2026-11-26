<?php

namespace App\Services\PixelForge;

use RuntimeException;

/**
 * A PixelForge request ended without a usable response (timeout or
 * connection failure). Deliberately carries no request URL or response
 * body so it is safe to log.
 */
final class PixelForgeConnectionException extends RuntimeException {}
