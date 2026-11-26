<?php

namespace App\Services\PixelForge;

use RuntimeException;

/**
 * An authenticated PixelForge call was skipped because shared traffic is
 * paused or cooling down. No request was sent.
 */
final class PixelForgePausedException extends RuntimeException {}
