<?php

declare(strict_types=1);

namespace Switon\Console;

/**
 * Bitmask constants for ANSI foreground/background colors and text attributes.
 *
 * Use with {@see \Switon\Core\ConsoleInterface::colorize()} for CLI output.
 *
 * Guidance: Combine constants with bitwise OR; they are display hints, not semantic tags.
 */
class Colors
{
    protected function __construct()
    {
    }

    public const int FC_BLACK = 0x01;
    public const int FC_RED = 0x02;
    public const int FC_GREEN = 0x04;
    public const int FC_YELLOW = 0x08;
    public const int FC_BLUE = 0x10;
    public const int FC_MAGENTA = 0x20;
    public const int FC_CYAN = 0x40;
    public const int FC_WHITE = 0x80;

    public const int BC_BLACK = 0x0100;
    public const int BC_RED = 0x0200;
    public const int BC_GREEN = 0x0400;
    public const int BC_YELLOW = 0x0800;
    public const int BC_BLUE = 0x1000;
    public const int BC_MAGENTA = 0x2000;
    public const int BC_CYAN = 0x4000;
    public const int BC_WHITE = 0x8000;

    public const int AT_BOLD = 0x010000;
    public const int AT_ITALICS = 0x020000;
    public const int AT_UNDERLINE = 0x040000;
    public const int AT_BLINK = 0x080000;
    public const int AT_INVERSE = 0x100000;
    public const int AT_DIM = 0x200000;
    public const int AT_STRIKETHROUGH = 0x400000;

    public const int FC_GRAY = 0x0800000;
    public const int FC_LIGHT_RED = 0x1000000;
    public const int FC_LIGHT_GREEN = 0x2000000;
    public const int FC_LIGHT_YELLOW = 0x4000000;
    public const int FC_LIGHT_BLUE = 0x8000000;
    public const int FC_LIGHT_MAGENTA = 0x10000000;
    public const int FC_LIGHT_CYAN = 0x20000000;
    public const int FC_LIGHT_WHITE = 0x40000000;
}
