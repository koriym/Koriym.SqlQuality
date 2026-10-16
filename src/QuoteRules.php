<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function explode;
use function in_array;
use function strtoupper;

/**
 * How the server lexes quoted tokens, as far as sql_mode changes it.
 *
 * NO_BACKSLASH_ESCAPES makes a backslash a literal character inside a string;
 * ANSI_QUOTES turns "..." into an identifier, where a backslash never escapes.
 * A backtick identifier never has backslash escapes. The defaults are MySQL's.
 *
 * @psalm-immutable
 */
final class QuoteRules
{
    public function __construct(
        public readonly bool $backslashEscapes = true,
        public readonly bool $ansiQuotes = false,
    ) {
    }

    /**
     * @param string $sqlMode the value of @@session.sql_mode, a comma-separated flag list
     *
     * @psalm-pure
     */
    public static function fromSqlMode(string $sqlMode): self
    {
        $flags = explode(',', strtoupper($sqlMode));

        return new self(
            backslashEscapes: ! in_array('NO_BACKSLASH_ESCAPES', $flags, true),
            ansiQuotes: in_array('ANSI_QUOTES', $flags, true),
        );
    }

    /**
     * @return non-empty-list<self> every reading a server might apply, for a caller that cannot see sql_mode
     *
     * @psalm-pure
     */
    public static function every(): array
    {
        return [new self(true, false), new self(true, true), new self(false, false), new self(false, true)];
    }

    /** @param string $quote the opening quote character: ' " or ` */
    public function backslashEscapesIn(string $quote): bool
    {
        return match ($quote) {
            '\'' => $this->backslashEscapes,
            '"' => $this->backslashEscapes && ! $this->ansiQuotes,
            default => false,
        };
    }
}
